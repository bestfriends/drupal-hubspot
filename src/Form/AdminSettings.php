<?php

namespace Drupal\hubspot\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Hubspot admin settings form.
 *
 * @package Drupal\hubspot\Form
 */
class AdminSettings extends ConfigFormBase {

  /**
   * Config settings.
   *
   * @var string
   */
  const SETTINGS = 'hubspot.settings';

  /**
   * The state key/value store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a new AdminSettings object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state key/value store.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state) {
    parent::__construct($config_factory);
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'hubspot_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [
      static::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(static::SETTINGS);
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
      '#default_value' => $config->get('hubspot_portal_id'),
      '#description' => $this->t('Enter the HubSpot Portal ID for this site. <a href="@url" target="_blank">How do I find my portal ID?</a>.', [
        '@url' => 'https://knowledge.hubspot.com/articles/kcs_article/account/where-can-i-find-my-hub-id',
      ]),
    ];

    if ($config
      ->get('hubspot_portal_id')) {
      $form['settings']['hubspot_authentication'] = [
        '#value' => $this->t('Connect HubSpot Account'),
        '#type' => 'submit',
        '#submit' => [[$this, 'hubspotOauthSubmitForm']],
      ];

      if ($this->state->get('hubspot.hubspot_refresh_token')) {
        $form['settings']['hubspot_authentication']['#suffix'] = $this->t('Your HubSpot account is connected.');
        $form['settings']['hubspot_authentication']['#value'] = $this->t('Disconnect HubSpot Account');
        $form['settings']['hubspot_authentication']['#submit'] = [
          [
            $this,
            'hubspotOauthDisconnect',
          ],
        ];
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
      '#default_value' => $config
        ->get('hubspot_client_id'),
      '#description' => $this->t('Enter the HubSpot application Client ID.
        <a href="https://developers.hubspot.com/docs/faq/how-do-i-create-an-app-in-hubspot" target="_blank">How do I find my Client ID?</a>'),
    ];

    $form['settings']['app_settings']['hubspot_client_secret'] = [
      '#title' => $this->t('HubSpot Client Secret'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => $config
        ->get('hubspot_client_secret'),
      '#description' => $this->t('Enter the HubSpot application Client Secret.
      <a href="https://developers.hubspot.com/docs/faq/how-do-i-create-an-app-in-hubspot" target="_blank">How do I find my Client Secret?</a>'),
    ];

    $form['settings']['app_settings']['hubspot_scope'] = [
      '#title' => $this->t('HubSpot Scope'),
      '#type' => 'textfield',
      '#default_value' => $config
        ->get('hubspot_scope'),
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
      '#default_value' => $config
        ->get('tracking_code_on'),
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
      '#default_value' => $config
        ->get('hubspot_debug_on'),
      '#description' => $this->t('If debugging is enabled, HubSpot errors will be emailed to the address below. Otherwise, they
      will be logged to the regular Drupal error log.'),
    ];

    $form['settings']['debugging']['hubspot_debug_email'] = [
      '#title' => $this->t('Debugging email'),
      '#type' => 'email',
      '#default_value' => $config
        ->get('hubspot_debug_email'),
      '#description' => $this->t('Email error reports to this address if debugging is enabled.'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => ('Save Configuration'),
    ];

    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $this->configFactory->getEditable(static::SETTINGS)
      ->set('hubspot_portal_id', $form_state->getValue('hubspot_portal_id'))
      ->set('hubspot_client_id', $form_state->getValue('hubspot_client_id'))
      ->set('hubspot_client_secret', $form_state->getValue('hubspot_client_secret'))
      ->set('hubspot_scope', $form_state->getValue('hubspot_scope'))
      ->set('hubspot_debug_email', $form_state->getValue('hubspot_debug_email'))
      ->set('hubspot_debug_on', $form_state->getValue('hubspot_debug_on'))
      ->set('tracking_code_on', $form_state->getValue(['tracking_code_on']))
      ->save();
    $this->messenger()->addStatus($this->t('The configuration options have been saved.'));
  }

  /**
   * Form submission handler for hubspot_admin_settings().
   *
   * @param array $form
   *   Active form build.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Active form state.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Ajax response.
   */
  public function hubspotOauthSubmitForm(array &$form, FormStateInterface $form_state): RedirectResponse {
    $config = $this->config(static::SETTINGS);
    global $base_url;
    $options = [
      'query' => [
        'client_id' => $config
          ->get('hubspot_client_id'),
        'redirect_uri' => $base_url . Url::fromRoute('hubspot.oauth_connect')->toString(),
        'scope' => $config
          ->get('hubspot_scope'),
      ],
    ];
    $redirect_url = Url::fromUri('https://app.hubspot.com/oauth/authorize', $options)->toString();

    $response = new RedirectResponse($redirect_url);
    $response->send();
    return $response;
  }

  /**
   * Deletes Hubspot OAuth tokens.
   *
   * @param array $form
   *   Active form build.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Active form state.
   */
  public function hubspotOauthDisconnect(array &$form, FormStateInterface $form_state) {
    $this->messenger()->addStatus($this->t('Successfully disconnected from HubSpot.'), FALSE);
    $this->state->delete('hubspot.hubspot_refresh_token');
  }

}
