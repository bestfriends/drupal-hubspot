<?php

namespace Drupal\hubspot\Plugin\Block;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\hubspot\Hubspot;
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
   * Hubspot API Client service.
   *
   * @var \Drupal\hubspot\Hubspot
   */
  protected $hubspot;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * HubspotBlock constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\hubspot\Hubspot $hubspot
   *   Hubspot API Client service.
   * @param \Drupal\Core\Datetime\DateFormatter $dateFormatter
   *   The date formatter service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(array $configuration, string $plugin_id, $plugin_definition, Hubspot $hubspot, DateFormatter $dateFormatter, TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->hubspot = $hubspot;
    $this->dateFormatter = $dateFormatter;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('hubspot.hubspot'),
      $container->get('date.formatter'),
      $container->get('datetime.time')
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
  public function build(): array {
    $leads = $this->hubspot->hubspotGetRecent();

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
            '@time' => $this->dateFormatter->formatInterval($this->time->getRequestTime() - floor($lead['addedAt'] / 1000)),
          ]
        ),
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#cache' => [
        'max-age' => 0,
      ],
    ];

  }

}
