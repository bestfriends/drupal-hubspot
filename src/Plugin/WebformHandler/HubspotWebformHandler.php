<?php

namespace Drupal\hubspot\Plugin\WebformHandler;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
          '@admin_link' => Link::createFromRoute('connect to Hubspot', 'hubspot.admin_settings'),
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

    $options = ['--donotmap--' => 'Do Not Map'];

    try {
      $hubspot_forms = $this->hubspot->getHubspotForms();
    }
    catch (GuzzleException $e) {
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
      '#ajax' => [
        'callback' => [$this, 'showWebformFields'],
        'event' => 'change',
        'wrapper' => 'field_mapping_list',
      ],
    ];

    $form['mapping']['original_hubspot_id'] = [
      '#type' => 'hidden',
      '#value' => $default_hubspot_guid,
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
      '#prefix' => '<div id="field_mapping_list">',
      '#suffix' => '</div>',
      '#markup' => '',
    ];

    $form_values = $form_state->getValues();

    // Apply default values if available.
    if (!empty($form_values['mapping']['hubspot_form']) || !empty($default_hubspot_guid)) {
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

      if (!empty($form_values['mapping']['hubspot_form'])) {
        $hubspot_guid = $form_values['mapping']['hubspot_form'];
      }
      else {
        $hubspot_guid = $default_hubspot_guid;
      }

      $hubspot_fields = $hubspot_forms[$hubspot_guid] ?? [];
      $options = ['--donotmap--' => 'Do Not Map'];
      foreach ($hubspot_fields['formFieldGroups'] as $hubspot_field) {
        $options[$hubspot_field['fields'][0]['name']] = $hubspot_field['fields'][0]['label'];
      }

      $components = $this->webform->getElementsInitializedAndFlattened();
      foreach ($components as $webform_field => $value) {
        if (!in_array($value['#type'], $exclude_elements)) {
          if ($value['#webform_composite']) {
            // Loop through a composite field to get all fields.
            foreach ($value['#webform_composite_elements'] as $composite_field => $composite_value) {
              $key = $webform_field . ':' . $composite_field;
              $form['mapping']['field_group']['fields'][$key] = [
                '#title' => (@$webform_field . ':' . $composite_value['#title'] ?: $key) . ' (' . $composite_value['#type'] . ')',
                '#type' => 'select',
                '#options' => $options,
              ];
              if (isset($settings['field_mapping'][$key])) {
                $form['mapping']['field_group']['fields'][$key]['#default_value'] = $settings['field_mapping'][$key];
              }
            }
          }
          else {
            // Non composite element.
            $form['mapping']['field_group']['fields'][$webform_field] = [
              '#title' => (@$value['#title'] ?: $webform_field) . ' (' . $value['#type'] . ')',
              '#type' => 'select',
              '#options' => $options,
            ];
            if (isset($settings['field_mapping'][$webform_field])) {
              $form['mapping']['field_group']['fields'][$webform_field]['#default_value'] = $settings['field_mapping'][$webform_field];
            }
          }
        }
      }
    }

    return $form;
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
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    if (!$this->hubspot->isConfigured()) {
      return;
    }

    $hubspot_id = $form_state->getValues()['mapping']['hubspot_form'];
    $fields = $form_state->getValues()['mapping']['field_group']['fields'];

    $settings = [];

    // Add new field mapping.
    if ($hubspot_id != '--donotmap--') {
      $settings['form_guid'] = $hubspot_id;
      $settings['field_mapping'] = array_filter($fields, function ($hubspot_field) {
        return $hubspot_field !== '--donotmap--';
      });
      $this->messenger()->addMessage($this->t('Saved new field mapping.'));
    }

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
    if ($entity_type) {
      $entity_storage = $this->entityTypeManager->getStorage($entity_type);
      $entity = $entity_storage->load($request_post_data['entity_id']);
      $form_title = $entity->label();
      $context['pageUrl'] = Url::fromUserInput($request_post_data['uri'], ['absolute' => TRUE])->toString();
    }
    else {
      // Case 2: Webform it self.
      // Webform title.
      $form_title = $this->getWebform()->label();
      $context['pageUrl'] = $this->webform->toUrl('canonical', [
        'absolute' => TRUE,
      ])->toString();
    }
    $settings = $this->getSettings();
    $form_guid = $settings['form_guid'];
    $field_mapping = $settings['field_mapping'];

    $webform_values = $webform_submission->getData();
    $form_values = [];
    foreach ($field_mapping as $webform_path => $hubspot_field) {
      if ($hubspot_field != '--donotmap--') {
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

    try {
      $hubspot_response = $this->hubspot->submitHubspotForm($form_guid, $form_values, $context);
      $response = $hubspot_response['response'] ?? NULL;

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
          '@msg' => $hubspot_response['error'],
        ]);
      }
    }
    catch (RequestException $e) {
      $this->loggerFactory->get('HubSpot')->notice('HTTP error when submitting HubSpot data from Webform "@form": <pre>@error</pre>', [
        '@form' => $form_title,
        '@error' => $e->getResponse()->getBody()->getContents(),
      ]);
      watchdog_exception('HubSpot', $e);
    }
    catch (GuzzleException $e) {
      $this->loggerFactory->get('HubSpot')->notice('HTTP error when submitting HubSpot data from Webform "@form": <pre>@error</pre>', [
        '@form' => $form_title,
        '@error' => $e->getMessage(),
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
