<?php

namespace Drupal\hubspot\Plugin\Block;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'hubspot' block.
 *
 * @Block(
 *   id = "hubspot_block",
 *   admin_label = @Translation("HubSpot Recent Leads"),
 * )
 */
class HubspotBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Json Service.
   *
   * @var \Drupal\Component\Serialization\Json
   */
  protected $json;

  /**
   * Stores the configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * HubspotBlock constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client used to fetch remote definitions.
   * @param \Drupal\Core\Logger\LoggerChannelFactory $logger
   *   Defines a factory for logging channels.
   * @param \Drupal\Core\Datetime\DateFormatter $dateFormatter
   *   The date formatter service.
   * @param \Drupal\Component\Serialization\Json $json
   *   Default serialization for JSON.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $httpClient, LoggerChannelFactory $logger, DateFormatter $dateFormatter, Json $json, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $httpClient;
    $this->loggerFactory = $logger;
    $this->dateFormatter = $dateFormatter;
    $this->json = $json;
    $this->configFactory = $config_factory->getEditable('hubspot.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('logger.factory'),
      $container->get('date.formatter'),
      $container->get('serialization.json'),
      $container->get('config.factory'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    if ($account->hasPermission('view recent hubspot leads')) {
      return AccessResult::allowed();
    }
    return AccessResult::forbidden();
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $leads = $this->hubspotGetRecent();

    // This part of the HubSpot API returns HTTP error codes on failure, with
    // no message.
    if (!empty($leads['Error']) || $leads['HTTPCode'] != 200) {
      $output = $this->t('An error occurred when fetching the HubSpot leads data: @error', [
        '@error' => !empty($leads['Error']) ? $leads['Error'] : $leads['HTTPCode'],
      ]);

      return [
        '#type' => 'markup',
        '#markup' => $output,
      ];

    }
    elseif (empty($leads['Data'])) {
      $output = $this->t('No leads to show.');
      return [
        '#type' => 'markup',
        '#markup' => $output,
      ];
    }

    $items = [];

    foreach ($leads['Data']['contacts'] as $lead) {
      $first_name = isset($lead['properties']['firstname']['value']) ? $lead['properties']['firstname']['value'] : NULL;
      $last_name = isset($lead['properties']['lastname']['value']) ? $lead['properties']['lastname']['value'] : NULL;
      $url = Url::fromUri($lead['profile-url']);
      $items[] = [
        '#markup' => Link::fromTextAndUrl($first_name . ' ' .
          $last_name, $url)->toString() . ' ' . $this->t('(@time ago)',
          [
            '@time' => $this->dateFormatter->formatInterval(REQUEST_TIME - floor($lead['addedAt'] / 1000)),
          ]
        ),
      ];
    }

    $build = [
      '#theme' => 'item_list',
      '#items' => $items,
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    return $build;

  }

  /**
   * Gets the most recent HubSpot leads.
   *
   * @param int $n
   *   The number of leads to fetch.
   *
   * @see http://docs.hubapi.com/wiki/Searching_Leads
   *
   * @return array
   *   Returns array of recent hubspot leads activity.
   */
  public function hubspotGetRecent($n = 5) {
    $access_token = \Drupal::state()->get('hubspot.hubspot_access_token');
    $n = intval($n);

    if (empty($access_token)) {
      return ['Error' => $this->t('This site is not connected to a HubSpot Account.')];
    }

    $api = 'https://api.hubapi.com/contacts/v1/lists/recently_updated/contacts/recent';

    $options = [
      'query' => [
        'count' => $n,
      ],
    ];
    $url = Url::fromUri($api, $options)->toString();

    if (\Drupal::state()->get('hubspot.hubspot_expires_in') > REQUEST_TIME) {
      $result = $this->httpClient->get($url, [
        'headers' => ['Authorization' => 'Bearer ' . $access_token],
      ]);
    }
    else {
      $refresh = $this->hubspotOauthRefresh();
      if ($refresh) {
        $access_token = \Drupal::state()->get('hubspot.hubspot_access_token');
        $options = [
          'query' => [
            'access_token' => $access_token,
            'count' => $n,
          ],
        ];
        $url = Url::fromUri($api, $options)->toString();
        $result = $this->httpClient->get($url, [
          'headers' => ['Authorization' => 'Bearer ' . $access_token],
        ]);
      }
    }
    return [
      'Data' => json_decode($result->getBody(), TRUE),
      'Error' => isset($result->error) ? $result->error : '',
      'HTTPCode' => $result->getStatusCode(),
    ];
  }

  /**
   * Refreshes HubSpot OAuth Access Token when expired.
   */
  public function hubspotOauthRefresh() {
    global $base_url;
    $refresh_token = \Drupal::state()->get('hubspot.hubspot_refresh_token');
    if (!$refresh_token) {
      drupal_set_message($this->t('Refresh token not found. Reconnect to your Hubspot account.'), 'error', FALSE);
    }
    else {
      try {
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
        return TRUE;
      }
      catch (RequestException $e) {
        watchdog_exception('Hubspot', $e);
        return FALSE;
      }
    }
  }

}
