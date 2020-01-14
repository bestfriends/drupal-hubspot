<?php

namespace Drupal\hubspot\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class AdminSettings.
 *
 * @package Drupal\hubspot\Form
 */
class AdminSettings extends FormBase {

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
   * AdminSettings constructor.
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
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'hubspot_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = [];

    $form['settings'] = [
      '#title' => $this->t('Settings'),
      '#type' => 'fieldset',
    ];

    // Settings Tab.
    $form['settings']['hubspot_portal_id'] = [
      '#title' => $this->t('HubSpot Portal ID'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => $this->configFactory->get('hubspot_portal_id'),
      '#description' => $this->t('Enter the HubSpot Portal ID for this site.
      <a href="https://knowledge.hubspot.com/articles/kcs_article/account/where-can-i-find-my-hub-id" target="_blank">How do I find my portal ID?</a>.'),
    ];

    if ($this->configFactory->get('hubspot_portal_id')) {
      $form['settings']['hubspot_authentication'] = [
        '#value' => $this->t('Connect HubSpot Account'),
        '#type' => 'submit',
        '#submit' => [[$this, 'hubspotOauthSubmitForm']],
      ];

      if (\Drupal::state()->get('hubspot.hubspot_refresh_token')) {
        $form['settings']['hubspot_authentication']['#suffix'] = $this->t('Your HubSpot account is connected.');
        $form['settings']['hubspot_authentication']['#value'] = $this->t('Disconnect HubSpot Account');
        $form['settings']['hubspot_authentication']['#submit'] = [[$this, 'hubspotOauthDisconnect']];
      }
    }

    // Application Settings.
    $form['settings']['app_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('App Settings'),
    ];

    $form['settings']['app_settings']['hubspot_client_id'] = [
      '#title' => $this->t('HubSpot Client ID'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => $this->configFactory->get('hubspot_client_id'),
      '#description' => $this->t('Enter the HubSpot application Client ID.
        <a href="https://developers.hubspot.com/docs/faq/how-do-i-create-an-app-in-hubspot" target="_blank">How do I find my Client ID?</a>'),
    ];

    $form['settings']['app_settings']['hubspot_client_secret'] = [
      '#title' => $this->t('HubSpot Client Secret'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => $this->configFactory->get('hubspot_client_secret'),
      '#description' => $this->t('Enter the HubSpot application Client Secret.
      <a href="https://developers.hubspot.com/docs/faq/how-do-i-create-an-app-in-hubspot" target="_blank">How do I find my Client Secret?</a>'),
    ];

    $form['settings']['app_settings']['hubspot_scope'] = [
      '#title' => $this->t('HubSpot Scope'),
      '#type' => 'textfield',
      '#default_value' => $this->configFactory->get('hubspot_scope'),
      '#description' => $this->t('Enter the scopes required by your app. Click
      <a href="https://developers.hubspot.com/docs/methods/oauth2/initiate-oauth-integration#scopes" target="_blank">here</a>
      to see how to see what scopes are available and how to format them. For example, <em>contacts forms</em> will give you
      access to the contacts and forms API on HubSpot. Note: Your HubSpot App, must have these options checked.'),
    ];

    // Tracking Settings.
    $form['settings']['tracking'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Tracking'),
    ];

    $form['settings']['tracking']['tracking_code_on'] = [
      '#title' => $this->t('Enable Tracking Code'),
      '#type' => 'checkbox',
      '#default_value' => $this->configFactory->get('tracking_code_on'),
      '#description' => $this->t('If Tracking code is enabled, Javascript
      tracking will be inserted in all/specified pages of the site as configured
       in HubSpot account.'),
    ];

    // Debug Settings.
    $form['settings']['debugging'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Debugging'),
    ];

    $form['settings']['debugging']['hubspot_debug_on'] = [
      '#title' => $this->t('Debugging enabled'),
      '#type' => 'checkbox',
      '#default_value' => $this->configFactory->get('hubspot_debug_on'),
      '#description' => $this->t('If debugging is enabled, HubSpot errors will be emailed to the address below. Otherwise, they
      will be logged to the regular Drupal error log.'),
    ];

    $form['settings']['debugging']['hubspot_debug_email'] = [
      '#title' => $this->t('Debugging email'),
      '#type' => 'email',
      '#default_value' => $this->configFactory->get('hubspot_debug_email'),
      '#description' => $this->t('Email error reports to this address if debugging is enabled.'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => ('Save Configuration'),
    ];

    return $form;
  }

  /**
   * Submit handler of admin config form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->set('hubspot_portal_id', $form_state->getValue('hubspot_portal_id'))
      ->set('hubspot_client_id', $form_state->getValue('hubspot_client_id'))
      ->set('hubspot_client_secret', $form_state->getValue('hubspot_client_secret'))
      ->set('hubspot_scope', $form_state->getValue('hubspot_scope'))
      ->set('hubspot_debug_email', $form_state->getValue('hubspot_debug_email'))
      ->set('hubspot_debug_on', $form_state->getValue('hubspot_debug_on'))
      ->set('tracking_code_on', $form_state->getValue(['tracking_code_on']))
      ->save();
    drupal_set_message($this->t('The configuration options have been saved.'));
  }

  /**
   * Form submission handler for hubspot_admin_settings().
   */
  public function hubspotOauthSubmitForm(array &$form, FormStateInterface $form_state) {
    global $base_url;
    $options = [
      'query' => [
        'client_id' => $this->configFactory->get('hubspot_client_id'),
        'redirect_uri' => $base_url . Url::fromRoute('hubspot.oauth_connect')->toString(),
        'scope' => $this->configFactory->get('hubspot_scope'),
      ],
    ];
    $redirect_url = Url::fromUri('https://app.hubspot.com/oauth/authorize', $options)->toString();

    $response = new RedirectResponse($redirect_url);
    $response->send();
    return $response;
  }

  /**
   * Form submit handler.
   *
   * Deletes Hubspot OAuth tokens.
   */
  public function hubspotOauthDisconnect(array &$form, FormStateInterface $form_state) {
    drupal_set_message($this->t('Successfully disconnected from HubSpot.'), 'status', FALSE);
    \Drupal::state()->delete('hubspot.hubspot_refresh_token');
  }

}
