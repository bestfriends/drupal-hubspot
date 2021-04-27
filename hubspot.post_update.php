<?php

/**
 * @file
 * Post Update hook file for hubspot.
 */

/**
 * Convert hubspot webform handler mapping to config.
 */
function hubspot_post_update_table_to_configuration(&$sandbox) {
  $database = \Drupal::database();
  $webform_guids = $database->select('hubspot', 'hs')
    ->fields('hs', ['id', 'hubspot_guid'])
    ->distinct()
    ->execute()->fetchAll(\PDO::FETCH_ASSOC);
  $webform_guids = array_column($webform_guids, 'hubspot_guid', 'id');
  $webform_storage = \Drupal::entityTypeManager()->getStorage('webform');

  /** @var \Drupal\webform\WebformInterface[] $webforms */
  $webforms = $webform_storage->loadMultiple(array_keys($webform_guids));
  foreach ($webforms as $webform) {
    $id = $webform->id();

    $mapping = $database->select('hubspot', 'hs')
      ->fields('hs', ['webform_field', 'hubspot_field'])
      ->condition('id', $id)
      ->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $mapping = array_column($mapping, 'hubspot_field', 'webform_field');
    foreach ($webform->getHandlers() as $handler) {
      if ($handler instanceof HubspotWebformHandler) {
        $mapping = array_filter($mapping, function ($hubspot_field) {
          return $hubspot_field !== '--donotmap--';
        });
        $keys = array_map(function ($key) {
          // Drupal config arrays don't support having keys with `.`s.
          return str_replace('.', ':', $key);
        }, array_keys($mapping));
        $hubspot_mapping = [
          'form_guid' => $webform_guids[$id],
          'field_mapping' => array_combine($keys, $mapping),
        ];
        $handler->setSettings($hubspot_mapping);
      }
    }
    $webform->save();
  }
  $database->schema()->dropTable('hubspot');
}

/**
 * Flip field mapping keys and values.
 */
function hubspot_post_update_flip_field_mapping(&$sandbox) {
  $webform_storage = \Drupal::entityTypeManager()->getStorage('webform');
  /** @var \Drupal\webform\WebformInterface[] $webforms */
  $webforms = $webform_storage->loadMultiple();
  foreach ($webforms as $webform) {
    $updated = FALSE;
    foreach ($webform->getHandlers() as $handler) {
      if ($handler instanceof HubspotWebformHandler) {
        $hubspot_mapping = $handler->getSettings();
        $hubspot_mapping['field_mapping'] = array_flip($hubspot_mapping['field_mapping']);
        $handler->setSettings($hubspot_mapping);
        $updated = TRUE;
      }
    }
    if ($updated) {
      $webform->save();
    }
  }
}
