<?php

namespace Drupal\hubspot\Plugin\WebformHandler;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use SevenShores\Hubspot\Exceptions\BadRequest;
use SevenShores\Hubspot\Exceptions\HubspotException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Webform submission remote post handler.
 *
 * @WebformHandler(
 *   id = "hubspot_webform_handler",
 *   label = @Translation("HubSpot Webform Handler"),
 *   category = @Translation("External"),
 *   description = @Translation("Sends a webform submission to a Hubspot form."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class HubspotWebformHandler extends WebformHandlerBase {

  /**
   * The node storage.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * Internal reference to the hubspot forms.
   *
   * @var \Drupal\hubspot\Hubspot
   */
  protected $hubspot;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {

    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->mailManager = $container->get('plugin.manager.mail');
    $instance->hubspot = $container->get('hubspot.hubspot');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    // First check if hubspot is connected.
    if (!$this->hubspot->isConfigured()) {
      $form['mapping']['notice'] = [
        '#type' => 'item',
        '#title' => $this->t('Notice'),
        '#markup' => $this->t('Your site account is not connected to a Hubspot account, please @admin_link first.', [
          '@admin_link' => Link::createFromRoute('connect to Hubspot', 'hubspot.admin_settings')->toString(),
        ]),
      ];
      return $form;
    }

    $settings = $this->getSettings();
    $default_hubspot_guid = $settings['form_guid'] ?? NULL;
    $this->webform = $this->getWebform();

    $form['mapping'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Field Mapping'),
    ];

    try {
      $hubspot_forms = $this->hubspot->getHubspotForms();
    }
    catch (HubspotException $e) {
      $this->messenger()->addWarning('Unable to load hubspot form info.');
      return $form;
    }
    $hubspot_forms = array_column($hubspot_forms, NULL, 'guid');
    $options = array_column($hubspot_forms, 'name', 'guid');

    // Sort $options alphabetically and retain key (guid).
    asort($options, SORT_STRING | SORT_FLAG_CASE);

    // Select list of forms on hubspot.
    $form['mapping']['hubspot_form'] = [
      '#type' => 'select',
      '#title' => $this->t('Choose a hubspot form:'),
      '#options' => $options,
      '#default_value' => $default_hubspot_guid,
      '#empty_option' => $this->t('Select a form'),
      '#ajax' => [
        'callback' => [$this, 'showWebformFields'],
        'event' => 'change',
        'wrapper' => 'edit-settings-mapping-field-group-fields-wrapper',
      ],
    ];

    // Fieldset to contain mapping fields.
    $form['mapping']['field_group'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Fields to map for form: @label', ['@label' => $this->webform->label()]),
      '#states' => [
        'invisible' => [
          ':input[name="settings[mapping][hubspot_form]"]' => ['value' => '--donotmap--'],
        ],
      ],
    ];

    $form['mapping']['field_group']['fields'] = [
      '#type' => 'container',
      '#prefix' => '<div id="edit-settings-mapping-field-group-fields-wrapper">',
      '#suffix' => '</div>',
      '#markup' => '',
    ];

    $form_values = $form_state->getValues();

    // Generally, these elements cannot be submitted to HubSpot.
    $exclude_elements = [
      'webform_actions',
      'webform_flexbox',
      'webform_markup',
      'webform_more',
      'webform_section',
      'webform_wizard_page',
      'webform_message',
      'webform_horizontal_rule',
      'webform_terms_of_service',
      'webform_computed_token',
      'webform_computed_twig',
      'webform_element',
      'processed_text',
      'captcha',
      'container',
      'details',
      'fieldset',
      'item',
      'label',
    ];

    $consent_field_types = [
      'checkbox',
      'checkboxes',
      'webform_terms_of_service',
      'radios',
      'select',
    ];

    $components = $this->webform->getElementsInitializedAndFlattened();
    $webform_fields_options = [
      '--donotmap--' => 'Do Not Map',
    ];
    $webform_consent_fields_options = [];

    foreach ($components as $webform_field => $value) {
      if (!in_array($value['#type'], $exclude_elements)) {
        $key = $webform_field;
        $title = (@$value['#title'] ?: $webform_field) . ' (' . $value['#type'] . ')';
        if ($value['#webform_composite']) {
          // Loop through a composite field to get all fields.
          foreach ($value['#webform_composite_elements'] as $composite_field => $composite_value) {
            $key = $webform_field . ':' . $composite_field;
            $webform_fields_options[$key] = (@$value['#title'] . ': ' . $composite_value['#title'] ?: $key) . ' (' . $composite_value['#type'] . ')';
          }
        }
        else {
          $webform_fields_options[$webform_field] = (@$value['#title'] ?: $webform_field) . ' (' . $value['#type'] . ')';

          if (in_array($value['#type'], $consent_field_types)) {
            $webform_consent_fields_options[$webform_field] = (@$value['#title'] ?: $webform_field) . ' (' . $value['#type'] . ')';
          }
        }
      }
    }

    if (empty($webform_consent_fields_options)) {
      $webform_consent_fields_options['none'] = $this->t('No consent fields available.');
    }

    // Apply default values if available.
    if (!empty($form_values['mapping']['hubspot_form']) || !empty($default_hubspot_guid)) {

      if (!empty($form_values['mapping']['hubspot_form'])) {
        $hubspot_guid = $form_values['mapping']['hubspot_form'];
      }
      else {
        $hubspot_guid = $default_hubspot_guid;
      }

      $hubspot_form = $hubspot_forms[$hubspot_guid] ?? NULL;
      if ($hubspot_form) {
        foreach ($hubspot_form->formFieldGroups as $fieldGroup) {
          foreach ($fieldGroup->fields as $field) {
            $form['mapping']['field_group']['fields'][$field->name] = [
              '#title' => $field->label . ' (' . $field->fieldType . ')',
              '#type' => 'select',
              '#options' => $webform_fields_options,
            ];
          }
          if (isset($settings['field_mapping'][$field->name])) {
            $form['mapping']['field_group']['fields'][$field->name]['#default_value'] = $settings['field_mapping'][$field->name];
          }
        }
      }
    }

    $form['legal_consent'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Legal Consent'),
      'include' => [
        '#type' => 'select',
        '#title' => $this->t('Include Legal Consent status'),
        '#options' => [
          'never' => $this->t('Never'),
          'always' => $this->t('Always'),
          'conditionally' => $this->t('Conditionally'),
        ],
        '#parents' => [
          'settings',
          'legal_consent',
          'include',
        ],
      ],
      'source' => [
        '#type' => 'container',
        '#prefix' => '<div id="edit-settings-legal-consent-source-wrapper">',
        '#suffix' => '</div>',
        'element' => [
          '#type' => 'select',
          '#title' => 'Source Element',
          '#states' => [
            'visible' => [
              '#edit-settings-legal-consent-include' => [
                'value' => 'conditionally',
              ],
            ],
          ],
          '#parents' => [
            'settings',
            'legal_consent',
            'source',
            'element',
          ],
          '#empty_option' => $this->t('Select'),
          '#options' => $webform_consent_fields_options,
          '#ajax' => [
            'callback' => [$this, 'updateLegalConsentSource'],
            'event' => 'change',
            'wrapper' => 'edit-settings-legal-consent-source-wrapper',
          ],
        ],
      ],
    ];

    if (
      !empty($form_values['legal_consent']['include'])
      && !empty($form_values['legal_consent']['source']['element'])
      && isset($components[$form_values['legal_consent']['source']['element']])
      && isset($components[$form_values['legal_consent']['source']['element']]['#options'])
    ) {
      $form['legal_consent']['source']['option'] = [
        '#type' => 'select',
        '#title' => 'Source Value',
        '#states' => [
          'visible' => [
            '#edit-settings-legal-consent-include' => [
              'value' => 'conditionally',
            ],
          ],
        ],
        '#parents' => [
          'settings',
          'legal_consent',
          'source',
          'option',
        ],
        '#options' => $components[$form_values['legal_consent']['source']['element']]['#options'],
      ];
    }

    $form['subscriptions'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Subscriptions'),
    ];
    try {
      $available_subscriptions = $this->hubspot->hubspotGetSubscriptions();
      $subscription_options = [];
      foreach ($available_subscriptions as $subscription) {
        $subscription_options[$subscription->id] = $subscription->name;
      }
      $form_state->addBuildInfo('subscription_options', $subscription_options);
      $form_state->addBuildInfo('webform_consent_fields_options', $webform_consent_fields_options);

      if (empty($subscription_options)) {
        $form['subscriptions']['notice'] = [
          '#type' => 'item',
          '#markup' => $this->t('No subscriptions available.'),
        ];
      }
      else {
        $form['subscriptions']['mapping'] = [
          '#type' => 'table',
          '#prefix' => '<div id="edit-settings-subscriptions-mapping-wrapper">',
          '#suffix' => '</div>',
          '#header' => [
            $this->t('Subscription'),
            $this->t('Mapping'),
            $this->t('Actions'),
          ],
          '#empty' => $this->t('No configured subscriptions'),
        ];
        $subscriptions = $form_values['subscriptions']['mapping'] ?? $settings['subscriptions'] ?? [];
        if ($subscriptions === '') {
          $subscriptions = [];
        }
        $form['subscriptions']['add'] = [
          '#type' => 'button',
          '#value' => $this->t('Add Subscription'),
          '#ajax' => [
            'callback' => [$this, 'addSubscription'],
            'event' => 'click',
            'wrapper' => 'edit-settings-subscriptions-mapping-wrapper',
          ],
          '#parents' => [
            'settings',
            'subscriptions',
            'add',
          ],
        ];
        if ($form_state->getTriggeringElement() && $form_state->getTriggeringElement()['#parents'] == $form['subscriptions']['add']['#parents']) {
          $subscriptions[] = [];
        }
        foreach ($subscriptions as $key => $subscription) {
          $form['subscriptions']['mapping'][$key] = $this->buildSubscriptionRow($key, $subscription_options, $webform_consent_fields_options, $subscription);
        }
      }
    }
    catch (BadRequest $e) {
      $form['subscriptions']['notice'] = [
        '#type' => 'item',
        '#markup' => $this->t('Unable to load Email Subscription Types. Please re-authorize the integration.'),
      ];
    }

    return $form;
  }

  /**
   * Build row for the subscription table.
   *
   * @param mixed $index
   *   THe row id.
   * @param array $subscription_options
   *   The subscription options.
   * @param array $default_values
   *   The configured values.
   *
   * @return array
   *   Render array for the row.
   */
  protected function buildSubscriptionRow($index, array $subscription_options, array $webform_consent_fields_options, array $default_values = []): array {
    $row = [
      '#attributes' => [
        'id' => 'edit-settings-subscriptions-mapping-' . $index,
      ],
      'subscription' => [
        '#type' => 'select',
        '#title' => $this->t('Subscription Name'),
        '#title_display' => 'invisible',
        '#empty_option' => $this->t('Select'),
        '#required' => TRUE,
        '#options' => $subscription_options,
        '#parents' => [
          'settings',
          'subscriptions',
          'mapping',
          $index,
          'subscription',
        ],
      ],
      'mapping' => [
        '#type' => 'container',
        '#prefix' => '<div id="edit-settings-subscriptions-mapping-' . $index . '-mapping-wrapper">',
        '#suffix' => '</div>',
        'include' => [
          '#type' => 'select',
          '#title' => 'Include',
          '#options' => [
            'always' => $this->t('Always'),
            'conditionally' => $this->t('Conditionally'),
          ],
          '#ajax' => [
            'callback' => [$this, 'updateSubscriptionSource'],
            'event' => 'change',
            'wrapper' => 'edit-settings-subscriptions-mapping-' . $index . '-mapping-wrapper',
          ],
          '#required' => TRUE,
          '#parents' => [
            'settings',
            'subscriptions',
            'mapping',
            $index,
            'mapping',
            'include',
          ],
        ],
      ],
      'remove' => [
        '#type' => 'button',
        '#value' => $this->t('Remove'),
        '#ajax' => [
          'callback' => [$this, 'removeSubscription'],
          'event' => 'click',
          'wrapper' => 'edit-settings-legal-consent-source-wrapper',
        ],
        '#parents' => [
          'settings',
          'subscriptions',
          'mapping',
          $index,
          'remove',
        ],
      ],
    ];

    $components = $this->webform->getElementsInitializedAndFlattened();

    if ($default_values['mapping']['include'] == 'conditionally') {
      $row['mapping']['element'] = [
        '#type' => 'select',
        '#title' => $this->t('Element'),
        '#states' => [
          'visible' => [
            '#edit-settings-subscriptions-mapping-' . $index . '-mapping-include' => [
              'value' => 'conditionally',
            ],
          ],
        ],
        '#required' => TRUE,
        '#empty_option' => $this->t('Select'),
        '#options' => $webform_consent_fields_options,
        '#ajax' => [
          'callback' => [$this, 'updateSubscriptionSource'],
          'event' => 'change',
          'wrapper' => 'edit-settings-subscriptions-mapping-' . $index . '-mapping-wrapper',
        ],
        '#parents' => [
          'settings',
          'subscriptions',
          'mapping',
          $index,
          'mapping',
          'element',
        ],
      ];

      if (!empty($default_values['mapping']['element'])
        && isset($components[$default_values['mapping']['element']])
        && isset($components[$default_values['mapping']['element']]['#options'])
      ) {
        $row['mapping']['value'] = [
          '#type' => 'select',
          '#title' => $this->t('Value'),
          '#required' => TRUE,
          '#states' => [
            'visible' => [
              '#edit-settings-subscriptions-mapping-' . $index . '-include' => [
                'value' => 'conditionally',
              ],
            ],
          ],
          '#options' => $components[$default_values['mapping']['element']]['#options'],
          '#parents' => [
            'settings',
            'subscriptions',
            'mapping',
            $index,
            'mapping',
            'option',
          ],
        ];
      }
    }

    return $row;
  }

  /**
   * AJAX callback for hubspot form change event.
   *
   * @param array $form
   *   Active form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Active form state.
   *
   * @return array
   *   Render array.
   */
  public function showWebformFields(array $form, FormStateInterface $form_state): array {
    return $form['settings']['mapping']['field_group']['fields'];
  }

  /**
   * AJAX callback for hubspot form change event.
   *
   * @param array $form
   *   Active form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Active form state.
   *
   * @return array
   *   Render array.
   */
  public function updateLegalConsentSource(array $form, FormStateInterface $form_state): array {
    return $form['settings']['legal_consent']['source'];
  }

  /**
   * AJAX callback for hubspot form change event.
   *
   * @param array $form
   *   Active form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Active form state.
   *
   * @return array
   *   Render array.
   */
  public function addSubscription(array $form, FormStateInterface $form_state): array {
    return $form['settings']['subscriptions']['mapping'];
  }

  /**
   * Ajax call back for removing subscription mapping row.
   *
   * @param array $form
   *   Drupal form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Drupal form state for ajax callback.
   *
   * @return mixed
   *   Drupal ajax response.
   */
  public function removeSubscription(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();

    $element_path = $trigger['#parents'];
    array_pop($element_path);
    $selector = '#edit-' . implode('-', $element_path);
    $response = new AjaxResponse();
    $response->addCommand(new RemoveCommand($selector));
    return $response;
  }

  /**
   * Ajax call back for removing subscription mapping row.
   *
   * @param array $form
   *   Drupal form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Drupal form state for ajax callback.
   *
   * @return mixed
   *   Drupal ajax response.
   */
  public function updateSubscriptionSource(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();

    $element_path = $trigger['#parents'];
    array_pop($element_path);
    $elem = NestedArray::getValue($form, $element_path);
    return $elem;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    if (!$this->hubspot->isConfigured()) {
      return;
    }

    $form_values = $form_state->getValues();

    $hubspot_id = $form_values['mapping']['hubspot_form'];
    $fields = $form_values['mapping']['field_group']['fields'];

    $settings = [];

    // Add new field mapping.
    if ($hubspot_id != '--donotmap--') {
      $settings['form_guid'] = $hubspot_id;
      $settings['field_mapping'] = array_filter($fields, function ($hubspot_field) {
        return $hubspot_field !== '--donotmap--';
      });
      $this->messenger()->addMessage($this->t('Saved new field mapping.'));
    }

    $settings['legal_consent'] = $form_values['legal_consent'];
    if ($settings['legal_consent']['include'] != 'conditionally') {
      unset($settings['legal_consent']['source']);
    }

    $settings['subscriptions'] = $form_values['subscriptions']['mapping'];

    $this->setSettings($settings);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $operation = ($update) ? 'update' : 'insert';
    $this->remotePost($operation, $webform_submission);
  }

  /**
   * Get hubspot settings.
   *
   * @return array
   *   An associative array containing hubspot configuration values.
   */
  public function getSettings(): array {
    $configuration = $this->getConfiguration();
    return $configuration['settings'] ?? [];
  }

  /**
   * Set hubspot settings.
   *
   * @param array $settings
   *   An associative array containing hubspot configuration values.
   */
  public function setSettings(array $settings) {
    $configuration = $this->getConfiguration();
    $configuration['settings'] = $settings;
    $this->setConfiguration($configuration);
  }

  /**
   * Execute a remote post.
   *
   * @param string $operation
   *   The type of webform submission operation to be posted. Can be 'insert',
   *   'update', or 'delete'.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   */
  protected function remotePost($operation, WebformSubmissionInterface $webform_submission) {
    // Get the hubspot config settings.
    $request_post_data = $this->getPostData($operation, $webform_submission);
    $entity_type = $request_post_data['entity_type'];
    $context = [];

    // Get webform.
    $webform = $this->getWebform();

    // Get all components.
    $elements = $webform->getElementsDecodedAndFlattened();

    // Loop through components and set new value for checkbox fields.
    foreach ($elements as $component_key => $component) {
      if ($component['#type'] == 'checkbox') {
        $webform_submission->setElementData($component_key, $webform_submission->getElementData($component_key) ? 'true' : 'false');
      }
    }

    if ($entity_type) {
      $entity_storage = $this->entityTypeManager->getStorage($entity_type);
      $entity = $entity_storage->load($request_post_data['entity_id']);
      $form_title = $entity->label();
      $page_url = Url::fromUserInput($request_post_data['uri'], ['absolute' => TRUE])->toString();
    }
    else {
      // Case 2: Webform it self.
      // Webform title.
      $form_title = $this->getWebform()->label();
      $page_url = $this->webform->toUrl('canonical', [
        'absolute' => TRUE,
      ])->toString();
    }

    $context['pageUri'] = $page_url;
    $settings = $this->getSettings();
    $form_guid = $settings['form_guid'];
    $field_mapping = $settings['field_mapping'];

    $webform_values = $webform_submission->getData();
    $form_values = [];

    foreach ($field_mapping as $hubspot_field => $webform_path) {
      if ($webform_path != '--donotmap--') {
        if (strpos($webform_path, ':') !== FALSE) {
          // Is composite element.
          $composite = explode(':', $webform_path);
          $composite_value = NestedArray::getValue($webform_values, $composite);
          $form_values[$hubspot_field] = $composite_value;
        }
        else {
          // Not a composite element.
          $form_values[$hubspot_field] = $webform_values[$webform_path];

        }
      }
    }

    $request_body = [];
    if (isset($settings['legal_consent']) && $settings['legal_consent']['include'] != 'never') {
      if (
        $settings['legal_consent']['include'] == 'always'
        || (
          in_array($elements[$settings['legal_consent']['source']['element']]['#type'], [
            'checkbox',
            'webform_terms_of_service',
          ]) && $webform_values[$settings['legal_consent']['source']['element']] == 1)
        || $webform_values[$settings['legal_consent']['source']['element']] == $settings['legal_consent']['source']['option']
      ) {
        $request_body['legalConsentOptions']['consent']['consentToProcess'] = TRUE;
        $request_body['legalConsentOptions']['consent']['text'] = $settings['legal_consent']['include'] == 'always' ? $this->t('I agree') : $elements[$settings['legal_consent']['source']['element']]['#title'];
      }
    }

    if (isset($settings['subscriptions'])) {
      foreach ($settings['subscriptions'] as $subscription) {
        if (
          $subscription['mapping']['include'] == 'always'
          || (
            in_array($elements[$subscription['mapping']['element']]['#type'], [
              'checkbox',
              'webform_terms_of_service',
            ]) && $webform_values[$subscription['mapping']['element']] == 1)
          || $webform_values[$subscription['mapping']['element']] == $subscription['mapping']['option']
        ) {
          $request_body['legalConsentOptions']['consent']['communications'][] = [
            'value' => TRUE,
            'subscriptionTypeId' => $subscription['subscription'],
            'text' => $subscription['mapping']['include'] == 'always' ? $this->t('I agree') : $elements[$subscription['mapping']['element']]['#title'],
          ];
        }
      }
    }

    try {
      $response = $this->hubspot->submitHubspotForm($form_guid, $form_values, $context, $request_body);

      // Debugging information.
      $config = $this->configFactory->get('hubspot.settings');
      $hubspot_url = 'https://app.hubspot.com';
      $to = $config->get('hubspot_debug_email');
      $default_language = \Drupal::languageManager()->getDefaultLanguage()->getId();
      $from = $config->get('site_mail');

      if ($response) {
        $data = (string) $response->getBody();

        if ($response->getStatusCode() == '200' || $response->getStatusCode() == '204') {
          $this->loggerFactory->get('HubSpot')->notice('Webform "@form" results successfully submitted to HubSpot.', [
            '@form' => $form_title,
          ]);
        }
        else {
          $this->loggerFactory->get('HubSpot')->notice('HTTP notice when submitting HubSpot data from Webform "@form". @code: <pre>@msg</pre>', [
            '@form' => $form_title,
            '@code' => $response->getStatusCode(),
            '@msg' => $response->getBody()->getContents(),
          ]);
        }

        if ($config->get('hubspot_debug_on')) {
          $this->mailManager->mail('hubspot', 'hub_error', $to, $default_language, [
            'errormsg' => $data,
            'hubspot_url' => $hubspot_url,
            'node_title' => $form_title,
          ], $from);
        }
      }
      else {
        $this->loggerFactory->get('HubSpot')->notice('HTTP error when submitting HubSpot data from Webform "@form": <pre>@msg</pre>', [
          '@form' => $form_title,
          '@msg' => 'No response returned from Hubspot Client',
        ]);
      }
    }
    catch (HubspotException $e) {
      $this->loggerFactory->get('HubSpot')->notice('HTTP error when submitting HubSpot data from Webform "@form": <pre>@error</pre>', [
        '@form' => $form_title,
        '@error' => $e->getResponse()->getBody()->getContents(),
      ]);
      watchdog_exception('HubSpot', $e);
    }

  }

  /**
   * Get a webform submission's post data.
   *
   * @param string $operation
   *   The type of webform submission operation to be posted. Can be 'insert',
   *   'update', or 'delete'.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission to be posted.
   *
   * @return array
   *   A webform submission converted to an associative array.
   */
  protected function getPostData($operation, WebformSubmissionInterface $webform_submission) {
    // Get submission and elements data.
    $data = $webform_submission->toArray(TRUE);

    // Flatten data.
    // Prioritizing elements before the submissions fields.
    $data = $data['data'] + $data;
    unset($data['data']);

    return $data;
  }

}
