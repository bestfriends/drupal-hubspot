<?php

namespace Drupal\hubspot\Plugin\WebformHandler;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManager;
use Drupal\Core\Url;
use Drupal\hubspot\Hubspot;
use Drupal\node\NodeStorageInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use GuzzleHttp\ClientInterface;
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
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $nodeStorage;

  /**
   * Stores the configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * Internal reference to the webform.
   *
   * @var \Drupal\webform\Entity\Webform
   */
  protected $webform;

  /**
   * Internal reference to the hubspot forms.
   *
   * @var \Drupal\hubspot\Hubspot
   */
  protected $hubspot;

  /**
   * {@inheritdoc}
   */
  public function __construct(
      array $configuration,
      $plugin_id,
      $plugin_definition,
      LoggerChannelFactoryInterface $logger_factory,
      ConfigFactoryInterface $config_factory,
      EntityTypeManagerInterface $entity_type_manager,
      WebformSubmissionConditionsValidatorInterface $conditions_validator,
      ClientInterface $httpClient,
      NodeStorageInterface $node_storage,
      MailManager $mailManager,
      Hubspot $hubspot
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $entity_type_manager, $conditions_validator);
    $this->httpClient = $httpClient;
    $this->nodeStorage = $node_storage;
    $this->mailManager = $mailManager;
    $this->hubspot = $hubspot;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('webform_submission.conditions_validator'),
      $container->get('http_client'),
      $container->get('entity.manager')->getStorage('node'),
      $container->get('plugin.manager.mail'),
      $container->get('hubspot.hubspot')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // First check if hubspot is connected.
    if (!$this->hubspot->isConfigured()) {
      $form['mappping']['notice'] = [
        '#type' => 'item',
        '#title' => $this->t('Notice'),
        '#markup' => $this->t('Your hubspot account is not connected. Please configure that first <a href="/admin/config/services/hubspot">here</a>.'),
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

    $hubspot_forms = $this->hubspot->getHubspotForms();

    foreach ($hubspot_forms as $hubspot_form) {
      $options[$hubspot_form['guid']] = $hubspot_form['name'];
    }


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

      foreach ($hubspot_forms as $key => $val) {
        if ($val['guid'] == $hubspot_guid) {
          $index = $key;
        }
      }
      $hubspot_fields = $hubspot_forms[$index];
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
   */
  public function showWebformFields(array $form, FormStateInterface $form_state) {
    return $form['settings']['mapping']['field_group']['fields'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    if (!$this->hubspot->isConfigured()) {
      return FALSE;
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
      drupal_set_message($this->t('Saved new field mapping.'));
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
  public function getSettings() {
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
    if ($entity_type == 'node') {
      // Case 1: of node forms.
      $entity_id = $request_post_data['entity_id'];
      $node = $this->nodeStorage->load($entity_id);
      // Node form title of some webform type.
      $form_title = $node->getTitle();
      $page_url = Url::fromUserInput($request_post_data['uri'], ['absolute' => TRUE])->toString();
    }
    else {
      // Case 2: Webform it self.
      // Webform title.
      $form_title = $this->getWebform()->get('title');
      $page_url = Url::fromUserInput('/form/' . $request_post_data['webform_id'], ['absolute' => TRUE])->toString();
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
      $hubspot_response = $this->hubspot->submitHubspotForm($form_guid, $form_values, $page_url);
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
          $this->loggerFactory->get('HubSpot')->notice('Webform "@form" results succesfully submitted to HubSpot.', [
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
