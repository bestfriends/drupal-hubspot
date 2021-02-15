<?php

namespace Drupal\hubspot\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\hubspot\Hubspot;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Default controller for the hubspot module.
 */
class Controller extends ControllerBase {

  /**
   * Hubspot configuration.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The hubspot api client service.
   *
   * @var \Drupal\hubspot\Hubspot
   */
  protected $hubspot;

  /**
   * Controller constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The Drupal config factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\hubspot\Hubspot $hubspot
   *   The hubspot api client service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, RequestStack $request_stack, Hubspot $hubspot) {
    $this->config = $config_factory->getEditable('hubspot.settings');
    $this->request = $request_stack->getCurrentRequest();
    $this->hubspot = $hubspot;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('request_stack'),
      $container->get('hubspot.hubspot')
    );
  }

  /**
   * Gets response data and saves it in config.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Returns Hubspot Connection Response(api key values like access_token,
   *   refresh token, expire_in).
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function hubspotOauthConnect(): RedirectResponse {
    $code = $this->request->get('code');
    if ($code) {
      try {
        $this->hubspot->authorize($code);
        $this->messenger()->addStatus($this->t('Successfully authenticated with HubSpot.'), FALSE);
      }
      catch (RequestException $e) {
        watchdog_exception('Hubspot', $e);
      }
    }
    if (($error = $this->request->get('error')) && $error == 'access_denied') {
      $this->messenger()->addError($this->t('You denied the request for authentication with Hubspot. Please click the button again and
      choose the AUTHORIZE option.'), FALSE);
    }

    return $this->redirect('hubspot.admin_settings');
  }

}
