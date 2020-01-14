<?php

namespace Drupal\hubspot\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Default controller for the hubspot module.
 */
class Controller extends ControllerBase {
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
   * Controller constructor.
   */
  public function __construct(Connection $connection, ConfigFactoryInterface $config_factory) {
    $this->connection = $connection;
    $this->configFactory = $config_factory->getEditable('hubspot.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('config.factory')
    );
  }

  /**
   * Gets response data and saves it in config.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Returns Hubspot Connection Response(api key values like access_token,
   *   refresh token, expire_in).
   */
  public function hubspotOauthConnect() {
    if (!empty($_GET['code'])) {
      global $base_url;
      try {
        $authorize = \Drupal::httpClient()->post('https://api.hubapi.com/oauth/v1/token', [
          'headers' => ['Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8'],
          'form_params' => [
            'grant_type' => 'authorization_code',
            'client_id' => $this->configFactory->get('hubspot_client_id'),
            'client_secret' => $this->configFactory->get('hubspot_client_secret'),
            'redirect_uri' => $base_url . Url::fromRoute('hubspot.oauth_connect')->toString(),
            'code' => $_GET['code'],
          ],
        ])->getBody();
        $data = Json::decode($authorize);
        drupal_set_message($this->t('Successfully authenticated with HubSpot.'), 'status', FALSE);
        \Drupal::state()->set('hubspot.hubspot_access_token', $data['access_token']);
        \Drupal::state()->set('hubspot.hubspot_refresh_token', $data['refresh_token']);
        \Drupal::state()->set('hubspot.hubspot_expires_in', ($data['expires_in'] + REQUEST_TIME));
      }
      catch (RequestException $e) {
        watchdog_exception('Hubspot', $e);
      }
    }

    if (!empty($_GET['error']) && $_GET['error'] == 'access_denied') {
      drupal_set_message($this->t('You denied the request for authentication with Hubspot. Please click the button again and
      choose the AUTHORIZE option.'), 'error', FALSE);
    }
    $redirect_url = Url::fromRoute('hubspot.admin_settings')->toString();
    $response = new RedirectResponse($redirect_url);
    $response->send();
    return $response;
  }

  /**
   * Gets response data and saves it in config.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Returns Hubspot Connection Response(api key values like access_token,
   *   refresh token, expire_in). access_token expires and needs to be
   *   refreshed using the refresh_token.
   */
  public function hubspotOauthRefresh() {
    global $base_url;
    $refresh_token = \Drupal::state()->get('hubspot.hubspot_refresh_token');
    if (!$refresh_token) {
      drupal_set_message($this->t('Refresh token not found. Reconnect to your HubSpot account.'), 'error', FALSE);
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
      }
      catch (RequestException $e) {
        watchdog_exception('Hubspot', $e);
      }
    }

    $redirect_url = Url::fromRoute('hubspot.admin_settings')->toString();
    $response = new RedirectResponse($redirect_url);
    $response->send();
    return $response;
  }

}
