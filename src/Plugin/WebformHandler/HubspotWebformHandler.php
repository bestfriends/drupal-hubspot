<?php

namespace Drupal\hubspot\Plugin\WebformHandler;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManager;
use Drupal\Core\Url;
use Drupal\node\NodeStorageInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

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
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

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
   * @var array
   */
  protected $hubspotForms;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, WebformSubmissionConditionsValidatorInterface $conditions_validator, ClientInterface $httpClient, NodeStorageInterface $node_storage, Connection $connection, MailManager $mailManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $entity_type_manager, $conditions_validator);
    $this->httpClient = $httpClient;
    $this->nodeStorage = $node_storage;
    $this->connection = $connection;
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
    $this->mailManager = $mailManager;
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
      $container->get('database'),
      $container->get('plugin.manager.mail')
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
    if (!\Drupal::state()->get('hubspot.hubspot_refresh_token')) {
      $form['mappping']['notice'] = [
        '#type' => 'item',
        '#title' => $this->t('Notice'),
        '#markup' => $this->t('Your hubspot account is not connected. Please configure that first <a href="/admin/config/services/hubspot">here</a>.'),
      ];
      return $form;
    }

    $this->webform = $this->getWebform();

    $form['mapping'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Field Mapping'),
    ];

    $options = ['--donotmap--' => 'Do Not Map'];

    // Get forms from hubspot. Will reauth if needs to.
    $this->hubspotForms = $this->getHubspotForms();

    foreach ($this->hubspotForms['value'] as $hubspot_form) {
      $options[$hubspot_form['guid']] = $hubspot_form['name'];
    }

    // Check if mapping exists.
    $query = $this->connection->select('hubspot', 'hs')
      ->fields('hs', ['hubspot_guid', 'webform_field', 'hubspot_field'])
      ->condition('hs.id', $this->webform->id(), '=')
      ->execute();
    $existing_fields = $query->fetchAll(\PDO::FETCH_ASSOC);
    $default_hubspot_guid = NULL;
    if (count($existing_fields) >= 1) {
      $default_hubspot_guid = $existing_fields[0]['hubspot_guid'];
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

    // Apply default values if available.
    if (!empty($form_state->getValues()['mapping']['hubspot_form']) || !empty($default_hubspot_guid)) {
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

      if (!empty($form_state->getValues()['mapping']['hubspot_form'])) {
        $hubspot_guid = $form_state->getValues()['mapping']['hubspot_form'];
      }
      else {
        $hubspot_guid = $default_hubspot_guid;
      }

      foreach ($this->hubspotForms['value'] as $key => $val) {
        if ($val['guid'] == $hubspot_guid) {
          $index = $key;
        }
      }
      $hubspot_fields = $this->hubspotForms['value'][$index];
      $components = $this->webform->getElementsInitializedAndFlattened();
      foreach ($components as $webform_field => $value) {
        if (!in_array($value['#type'], $exclude_elements)) {
          $options = ['--donotmap--' => 'Do Not Map'];
          foreach ($hubspot_fields['formFieldGroups'] as $hubspot_field) {
            $options[$hubspot_field['fields'][0]['name']] = $hubspot_field['fields'][0]['label'];
          }

          if ($value['#webform_composite']) {
            // Loop through a composite field to get all fields.
            foreach ($value['#webform_composite_elements'] as $composite_field => $composite_value) {
              $form['mapping']['field_group']['fields'][$webform_field . '.' . $composite_field] = [
                '#title' => (@$webform_field . '.' . $composite_value['#title'] ?: $webform_field . '.' . $composite_field) . ' (' . $composite_value['#type'] . ')',
                '#type' => 'select',
                '#options' => $options,
              ];
            }
          }
          else {
            // Non composite element.
            $form['mapping']['field_group']['fields'][$webform_field] = [
              '#title' => (@$value['#title'] ?: $webform_field) . ' (' . $value['#type'] . ')',
              '#type' => 'select',
              '#options' => $options,
            ];
          }

          // Set default value of existing mappings.
          foreach ($existing_fields as $hs_field) {
            $form['mapping']['field_group']['fields'][$hs_field['webform_field']]['#default_value'] = $hs_field['hubspot_field'];
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
    if (!\Drupal::state()->get('hubspot.hubspot_refresh_token')) {
      return FALSE;
    }
    $webform_id = $this->webform->id();
    $original_hubspot_id = $form_state->getValues()['mapping']['original_hubspot_id'];
    $hubspot_id = $form_state->getValues()['mapping']['hubspot_form'];
    $fields = $form_state->getValues()['mapping']['field_group']['fields'];

    // Add new field mapping.
    if ($hubspot_id != '--donotmap--' && $hubspot_id != $original_hubspot_id) {
      foreach ($fields as $webform_field => $hubspot_field) {
        $fields = [
          'id' => $webform_id,
          'hubspot_guid' => $hubspot_id,
          'webform_field' => $webform_field,
          'hubspot_field' => $hubspot_field,
        ];
        $this->connection->insert('hubspot')->fields($fields)->execute();
      }
      drupal_set_message($this->t('Saved new field mapping.'));
    }

    // Remove existing field mapping.
    if ($hubspot_id == '--donotmap--' && $original_hubspot_id != '--donotmap--' && original_hubspot_id != NULL) {
      $delete = $this->connection->delete('hubspot')
        ->condition('id', $webform_id)
        ->condition('hubspot_guid', $original_hubspot_id)
        ->execute();
      drupal_set_message($this->t('Removed existing field mapping.'));
    }

    // Completely change hubspot forms and insert new field mapping.
    if ($hubspot_id != '--donotmap--' && $hubspot_id != $original_hubspot_id && $original_hubspot_id != NULL) {
      // Reset fields array.
      $fields = [];
      $delete = $this->connection->delete('hubspot')
        ->condition('id', $webform_id)
        ->condition('hubspot_guid', $original_hubspot_id)
        ->execute();
      foreach ($fields as $webform_field => $hubspot_field) {
        $fields = [
          'id' => $webform_id,
          'hubspot_guid' => $hubspot_id,
          'webform_field' => $webform_field,
          'hubspot_field' => $hubspot_field,
        ];
        $this->connection->insert('hubspot')->fields($fields)->execute();
      }
      drupal_set_message($this->t('Saved new field mapping.'));
    }

    // Update existing field mapping.
    if ($hubspot_id == $original_hubspot_id) {
      $delete = $this->connection->delete('hubspot')
        ->condition('id', $webform_id)
        ->condition('hubspot_guid', $hubspot_id)
        ->execute();
      foreach ($fields as $webform_field => $hubspot_field) {
        $fields = [
          'id' => $webform_id,
          'hubspot_guid' => $hubspot_id,
          'webform_field' => $webform_field,
          'hubspot_field' => $hubspot_field,
        ];
        $this->connection->insert('hubspot')->fields($fields)->execute();
      }
      drupal_set_message($this->t('Updated field mapping.'));
    }
  }

  /**
   * Get hubspot forms and fields from their API.
   */
  public function getHubspotForms() {
    $access_token = \Drupal::state()->get('hubspot.hubspot_access_token');
    if (empty($access_token)) {
      return ['error' => t('This site is not connected to a HubSpot Account.')];
    }

    $api = 'https://api.hubapi.com/forms/v2/forms';
    $url = Url::fromUri($api)->toString();
    try {
      $response = \Drupal::httpClient()->get($url, [
        'headers' => ['Authorization' => 'Bearer ' . $access_token],
      ]);
      $data = (string) $response->getBody();
      return ['value' => Json::decode($data)];
    }
    catch (RequestException $e) {
      // We need to reauthenticate with hubspot.
      global $base_url;
      $refresh_token = \Drupal::state()->get('hubspot.hubspot_refresh_token');

      try {
        $this->configFactory = $this->configFactory->getEditable('hubspot.settings');
        $reauth = \Drupal::httpClient()->post('https://api.hubapi.com/oauth/v1/token', [
          'headers' => ['Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8'],
          'form_params' => [
            'grant_type' => 'refresh_token',
            'client_id' => $this->configFactory->get('hubspot_client_id'),
            'client_secret' => $this->configFactory->get('hubspot_client_secret'),
            'redirect_uri' => $base_url . Url::fromRoute('hubspot.oauth_connect')->toString(),
            'refresh_token' => $refresh_token,
          ],
        ])->getBody();
        $data = Json::decode($reauth);
        \Drupal::state()->set('hubspot.hubspot_access_token', $data['access_token']);
        \Drupal::state()->set('hubspot.hubspot_refresh_token', $data['refresh_token']);
        \Drupal::state()->set('hubspot.hubspot_expires_in', ($data['expires_in'] + REQUEST_TIME));

        $response = \Drupal::httpClient()->get($url, [
          'headers' => ['Authorization' => 'Bearer ' . $data['access_token']],
        ]);
        $data = (string) $response->getBody();
        return ['value' => Json::decode($data)];

      }
      catch (RequestException $e) {
        watchdog_exception('Hubspot', $e);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $operation = ($update) ? 'update' : 'insert';
    $this->remotePost($operation, $webform_submission);
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
    $this->configFactory = $this->configFactory->getEditable('hubspot.settings');
    if (empty($this->configFactory->get('hubspot_portal_id'))) {
      // No portal id. Probably not connected to hubspot.
      $this->loggerFactory->get('HubSpot')->notice('Webform not sent to hubspot because portal ID is empty');
      return FALSE;
    }
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
    $form_guid = $this->connection->select('hubspot', 'h')
      ->fields('h', ['hubspot_guid'])
      ->condition('id', $request_post_data['webform_id'])
      ->range(0, 1)
      ->execute()->fetchField();

    $portal_id = $this->configFactory->get('hubspot_portal_id');
    $api = 'https://forms.hubspot.com/uploads/form/v2/' . $portal_id . '/' . $form_guid;
    $url = Url::fromUri($api)->toString();
    $cookie = \Drupal::request()->cookies->get('hubspotutk');

    try {
      $hs_context = [
        'hutk' => isset($cookie) ? $cookie : '',
        'ipAddress' => \Drupal::request()->getClientIp(),
        'pageName' => $form_title,
        'pageUrl' => $page_url,
      ];

      $field_values = $webform_submission->getData();
      $columns = $this->connection->select('hubspot', 'h')
        ->fields('h', ['hubspot_field', 'webform_field'])
        ->condition('hubspot_guid', $form_guid)
        ->condition('id', $request_post_data['webform_id'])
        ->execute()->fetchAllKeyed();
      $fields = [];
      foreach ($columns as $key => $val) {
        if ($key != '--donotmap--') {
          if (strpos($val, '.') !== FALSE) {
            // Is composite element.
            $composite = explode('.', $val);
            $composite_value = $field_values[$composite[0]][$composite[1]];
            if (is_array($composite_value)) {
              // Multivalue fields like checkboxes are semi-colon seperated.
              array_push($fields, $key . '=' . implode(';', $composite_value));
            }
            else {
              array_push($fields, $key . '=' . $composite_value);
            }
          }
          else {
            // Not a composite element.
            if (is_array($field_values[$val])) {
              // Multivalue fields like checkboxes are semi-colon seperated.
              array_push($fields, $key . '=' . implode(';', $field_values[$val]));
            }
            else {
              array_push($fields, $key . '=' . $field_values[$val]);
            }
          }
        }
      }
      $fields = implode('&', $fields);
      $string = 'hs_context=' . Json::encode($hs_context) . '&' . $fields;
      $request_options = [
        RequestOptions::HEADERS => ['Content-Type' => 'application/x-www-form-urlencoded'],
        RequestOptions::BODY => $string,
      ];
      $response = $this->httpClient->request('POST', $url, $request_options);

      // Debugging information.
      $hubspot_url = 'https://app.hubspot.com';
      $to = $this->configFactory->get('hubspot_debug_email');
      $default_language = \Drupal::languageManager()->getDefaultLanguage()->getId();
      $from = $this->configFactory->get('site_mail');
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

      if ($this->configFactory->get('hubspot_debug_on')) {
        $this->mailManager->mail('hubspot', 'hub_error', $to, $default_language, [
          'errormsg' => $data,
          'hubspot_url' => $hubspot_url,
          'node_title' => $form_title,
        ], $from);
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
