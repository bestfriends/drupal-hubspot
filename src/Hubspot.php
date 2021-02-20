<?php

namespace Drupal\hubspot;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManager;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use GuzzleHttp\RequestOptions;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Define a service for interacting with the HubSpot CRM.
 */
class Hubspot {

  use StringTranslationTrait;

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
   * @param \Drupal\Core\Mail\MailManager $mailManager
   *   Drupal mailer service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   Drupal request stack.
   */
  public function __construct(
    StateInterface $state,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    ClientInterface $httpClient,
    MailManager $mailManager,
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
   * Authorize site via OAuth.
   *
   * @param string $code
   *   Auth authorization code.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function authorize(string $code) {
    $response = $this->httpClient->post('https://api.hubapi.com/oauth/v1/token', [
      'headers' => ['Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8'],
      'form_params' => [
        'grant_type' => 'authorization_code',
        'client_id' => $this->config->get('hubspot_client_id'),
        'client_secret' => $this->config->get('hubspot_client_secret'),
        'redirect_uri' => Url::fromRoute('hubspot.oauth_connect', [], [
          'absolute' => TRUE,
        ])->toString(),
        'code' => $code,
      ],
    ]);
    $data = Json::decode($response->getBody()->getContents());
    $this->state->set('hubspot.hubspot_access_token', $data['access_token']);
    $this->state->set('hubspot.hubspot_refresh_token', $data['refresh_token']);
    $this->state->set('hubspot.hubspot_expires_in', ($data['expires_in'] + $this->currentRequest->server->get('REQUEST_TIME')));
  }

  /**
   * Get hubspot forms and fields from their API.
   *
   * @return array
   *   The hubspot forms.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getHubspotForms(): array {
    static $hubspot_forms;

    if (!isset($hubspot_forms)) {
      $api = 'https://api.hubapi.com/forms/v2/forms';
      $url = Url::fromUri($api)->toString();
      $response = $this->request('GET', $url);
      if (isset($response['error'])) {
        return [];
      }
      else {
        $hubspot_forms = $response['value'] ?? [];
      }
    }

    return $hubspot_forms;
  }

  /**
   * Make a request to hubspot.
   *
   * This function will return an array containing the hubspot response at the
   * index 'value'. In the event that the module is unable to make the request
   * an array with the key 'error', and value of an error message, will be
   * returned instead.
   *
   * If the hubspot bearer token is expired, an attempt will be made to renew
   * the token.
   *
   * @param string $method
   *   HTTP Request method.
   * @param string $url
   *   HTTP Request URL.
   * @param array $options
   *   Guzzle Request options.
   *
   * @return array
   *   Hubspot return array.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function request(string $method, string $url, array $options = []): array {
    $access_token = $this->state
      ->get('hubspot.hubspot_access_token');
    if (empty($access_token)) {
      return ['error' => $this->t('This site is not connected to a HubSpot Account.')];
    }

    $options += [
      'headers' => ['Authorization' => 'Bearer ' . $access_token],
    ];

    try {
      $response = $this->httpClient->request($method, $url, $options);
    }
    catch (RequestException $e) {
      // We need to reauthenticate with hubspot.
      global $base_url;
      $refresh_token = $this->state
        ->get('hubspot.hubspot_refresh_token');

      try {
        $reauth = $this->httpClient->post('https://api.hubapi.com/oauth/v1/token', [
          'headers' => ['Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8'],
          'form_params' => [
            'grant_type' => 'refresh_token',
            'client_id' => $this->config->get('hubspot_client_id'),
            'client_secret' => $this->config->get('hubspot_client_secret'),
            'redirect_uri' => $base_url . Url::fromRoute('hubspot.oauth_connect')->toString(),
            'refresh_token' => $refresh_token,
          ],
        ])->getBody();
        $data = Json::decode($reauth);
        $this->state
          ->set('hubspot.hubspot_access_token', $data['access_token']);
        $this->state
          ->set('hubspot.hubspot_refresh_token', $data['refresh_token']);
        $this->state
          ->set('hubspot.hubspot_expires_in', ($data['expires_in'] + $this->currentRequest->server->get('REQUEST_TIME')));

        $response = $this->httpClient->request($method, $url, $options);
      }
      catch (RequestException $e) {
        $this->logger->error($this->t('Unable to execute request: %message', [
          '%message' => $e->getMessage(),
        ]));
        return ['error' => $e->getMessage()];
      }
    }

    $data = $response->getBody()->getContents();
    return [
      'value' => Json::decode($data),
      'response' => $response,
    ];
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
   *
   * @return array
   *   The request response info.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function submitHubspotForm(string $form_guid, array $form_field_values, array $context = []): array {
    // Convert list values into semicolon separated lists.
    array_walk($form_field_values, function (&$value) {
      $value = urlencode($value);
      if (is_array($value)) {
        $value = implode(';', $value);
      }
    });

    $portal_id = $this->config->get('hubspot_portal_id');
    $api = 'https://forms.hubspot.com/uploads/form/v2/' . $portal_id . '/' . $form_guid;
    $url = Url::fromUri($api)->toString();

    $hs_context = [
      'hutk' => $this->currentRequest->cookies->get('hubspotutk') ?? '',
      'ipAddress' => $this->currentRequest->getClientIp(),
      'pageName' => isset($context['pageName']) ? $context['pageName'] : '',
      'pageUrl' => $this->currentRequest->headers->get('referer'),
    ];

    $hs_context = array_merge($hs_context, $context);

    $request_options = [
      RequestOptions::HEADERS => ['Content-Type' => 'application/x-www-form-urlencoded'],
      RequestOptions::FORM_PARAMS => $form_field_values + [
        'hs_context' => Json::encode($hs_context),
      ],
    ];

    return $this->request('POST', $url, $request_options);
  }

  /**
   * Gets the most recent HubSpot leads.
   *
   * @param int $n
   *   The number of leads to fetch.
   *
   * @return array
   *   Returns array of recent hubspot leads activity.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *
   * @see http://docs.hubapi.com/wiki/Searching_Leads
   */
  public function hubspotGetRecent(int $n = 5): array {
    $api = 'https://api.hubapi.com/contacts/v1/lists/recently_updated/contacts/recent';

    $options = [
      'query' => [
        'count' => $n,
      ],
    ];
    $url = Url::fromUri($api, $options)->toString();

    $result = $this->request('GET', $url);
    $response = $result['response'];

    return [
      'Data' => $result['value'],
      'Error' => isset($response->error) ? $response->error : '',
      'HTTPCode' => $response->getStatusCode(),
    ];
  }

}
