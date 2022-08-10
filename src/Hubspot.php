<?php

namespace Drupal\hubspot;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use SevenShores\Hubspot\Http\Response;
use Symfony\Component\HttpFoundation\RequestStack;

use SevenShores\Hubspot\Factory as HubspotClientFactory;
use SevenShores\Hubspot\Utils\OAuth2 as HubspotOAuthHelper;

/**
 * Define a service for interacting with the HubSpot CRM.
 */
class Hubspot {

  use StringTranslationTrait;

  const HUBSPOT_OAUTH2_SCOPES = [
    'oauth',
    'forms',
  ];

  /**
   * The Drupal state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The hubspot api client.
   *
   * @var \SevenShores\Hubspot\Factory
   */
  protected $hubspotClient;

  /**
   * Stores the configuration factory.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * Internal reference to the hubspot forms.
   *
   * @var array
   */
  protected $hubspotForms;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  private $currentRequest;

  /**
   * Create the hubspot integration service.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   Drupal state api.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Drupal logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Drupal config factory.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   Drupal http client.
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   Drupal mailer service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   Drupal request stack.
   */
  public function __construct(
    StateInterface $state,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    ClientInterface $httpClient,
    MailManagerInterface $mailManager,
    RequestStack $requestStack
  ) {
    $this->state = $state;
    $this->httpClient = $httpClient;
    $this->config = $config_factory->get('hubspot.settings');
    $this->logger = $logger_factory->get('hubspot');
    $this->mailManager = $mailManager;
    $this->currentRequest = $requestStack->getCurrentRequest();
  }

  /**
   * Check if hubspot is configured.
   *
   * When hubspot is configured, the refresh token will be set.
   *
   * @return bool
   *   True if the OAuth Refresh token is set. False, otherwise.
   */
  public function isConfigured(): bool {
    return !empty($this->state->get('hubspot.hubspot_refresh_token'));
  }

  /**
   * Initialize and return the hubspot client instance.
   *
   * @return \SevenShores\Hubspot\Factory
   *   Hubspot client instance.
   */
  public function getHubspotClient(): HubspotClientFactory {
    if (!$this->isConfigured()) {
      throw new \LogicException('Hubspot api has not been configured.');
    }

    if ($this->state->get('hubspot.hubspot_expires_in') < time()) {
      $this->refreshTokens();
    }

    if (!$this->hubspotClient) {
      $access_token = $this->state->get('hubspot.hubspot_access_token');
      $this->hubspotClient = HubspotClientFactory::createWithOAuth2Token($access_token);
    }
    return $this->hubspotClient;
  }

  /**
   * Generate hubspot authorization URL.
   *
   * @return string
   *   Hubspot authorization uri.
   */
  public function getAuthorizationUrl(): string {
    return HubspotOAuthHelper::getAuthUrl(
      $this->config->get('hubspot_client_id'),
      Url::fromRoute('hubspot.oauth_connect', [], [
        'absolute' => TRUE,
      ])->toString(),
      static::HUBSPOT_OAUTH2_SCOPES,
    );
  }

  /**
   * Authorize site via OAuth.
   *
   * @param string $code
   *   Auth authorization code.
   *
   * @throws \SevenShores\Hubspot\Exceptions\BadRequest
   */
  public function authorize(string $code) {
    $client = new HubspotClientFactory([], NULL, [
      'http_errors' => FALSE,
    ], TRUE);

    $tokens = $client->oAuth2()->getTokensByCode(
      $this->config->get('hubspot_client_id'),
      $this->config->get('hubspot_client_secret'),
      Url::fromRoute('hubspot.oauth_connect', [], [
        'absolute' => TRUE,
      ])->toString(),
      $code
    );
    $this->state->set('hubspot.hubspot_access_token', $tokens['access_token']);
    $this->state->set('hubspot.hubspot_refresh_token', $tokens['refresh_token']);
    $this->state->set('hubspot.hubspot_expires_in', ($tokens['expires_in'] + $this->currentRequest->server->get('REQUEST_TIME')));
  }

  /**
   * Authorize site via OAuth.
   *
   * @throws \SevenShores\Hubspot\Exceptions\BadRequest
   */
  public function refreshTokens() {
    $client = new HubspotClientFactory([], NULL, [
      'http_errors' => FALSE,
    ], TRUE);

    $tokens = $client->oAuth2()->getTokensByRefresh(
      $this->config->get('hubspot_client_id'),
      $this->config->get('hubspot_client_secret'),
      $this->state->get('hubspot.hubspot_refresh_token'),
    );
    $this->state->set('hubspot.hubspot_access_token', $tokens['access_token']);
    $this->state->set('hubspot.hubspot_refresh_token', $tokens['refresh_token']);
    $this->state->set('hubspot.hubspot_expires_in', ($tokens['expires_in'] + $this->currentRequest->server->get('REQUEST_TIME')));

    $this->hubspotClient = NULL;
  }

  /**
   * Get hubspot forms and fields from their API.
   *
   * @return array
   *   The hubspot forms.
   */
  public function getHubspotForms(): array {

    static $hubspot_forms;

    if (!isset($hubspot_forms)) {
      $response = $this->getHubspotClient()->forms()->all();

      $hubspot_forms = $response->data;
    }

    return $hubspot_forms;
  }

  /**
   * Submit a hubspot form.
   *
   * @param string $form_guid
   *   Hubspot Form GUID.
   * @param array $form_field_values
   *   Hubspot submission values, keyed by hubspot form item id.
   * @param array $context
   *   Options to pass to hubspot.
   * @param array $request_body
   *   Base request body to allow more complicated options to be passed.
   *
   * @return \SevenShores\Hubspot\Http\Response
   *   The request response info.
   */
  public function submitHubspotForm(string $form_guid, array $form_field_values, array $context = [], array $request_body = []): Response {
    // Convert list values into semicolon separated lists.
    $formatted_form_values = [];
    foreach ($form_field_values as $field_name => $field_value) {
      if ($field_value) {
        if (is_array($field_value)) {
          $field_value = implode(';', $field_value);
        }
        $formatted_form_values[] = [
          'name' => $field_name,
          'value' => $field_value,
        ];
      }
    }

    $request_body += [
      'fields' => $formatted_form_values,
      'context' => $context + [
        'ipAddress' => $this->currentRequest->getClientIp(),
        'pageUri' => $this->currentRequest->headers->get('referer'),
      ],
    ];
    if (($hutk = $this->currentRequest->cookies->get('hubspotutk'))) {
      $request_body['context']['hutk'] = $hutk;
    }

    $portal_id = $this->config->get('hubspot_portal_id');
    return $this->getHubspotClient()->forms()->submit($portal_id, $form_guid, $request_body);
  }

  /**
   * Gets the most recent HubSpot leads.
   *
   * @param int $count
   *   The number of leads to fetch.
   *
   * @return \SevenShores\Hubspot\Http\Response
   *   The request response info.
   *
   * @see http://docs.hubapi.com/wiki/Searching_Leads
   */
  public function hubspotGetRecent(int $count = 5): Response {
    return $this->getHubspotClient()->contacts()->recent([
      'count' => $count,
    ]);
  }

  /**
   * Load hubspot email Subscriptions.
   *
   * @return array
   *   The hubspot Subscriptions.
   */
  public function hubspotGetSubscriptions(): array {

    static $subscriptions;

    if (!isset($subscriptions)) {
      $response = $this->getHubspotClient()->emailSubscription()->subscriptions();

      $subscriptions = $response->data->subscriptionDefinitions ?? [];
    }

    return $subscriptions;
  }

}
