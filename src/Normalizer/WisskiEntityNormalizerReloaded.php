<?php

namespace Drupal\wisski_api\Normalizer;

use Drupal\serialization\Normalizer\EntityNormalizer;
use Drupal\wisski_core\Entity\WisskiEntity;
use Drupal\wisski_core\WisskiEntityInterface;
use Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity;
use Drupal\wisski_salz\AdapterHelper;

/**
 * A normalizer for WisskiEntities.
 */
class WisskiEntityNormalizerReloaded extends EntityNormalizer {

  const ENTITY_TYPE = "wisski_individual";

  const META_KEYS = [
    'eid',
    'uuid',
    'vid',
    'langcode',
    'revision_timestamp',
    'revision_uid',
    'revision_log',
    'published',
    'label',
    'uid',
    'created',
    'changed',
    'status',
    'preview_image',
    'default_langcode',
    'revision_default',
    'revision_translation_affected',
    'content_translation_source',
    'content_translation_outdated',
    'content_translation_uid',
  ];

  const WISSKI_KEYS = [
    'wisski_uri',
    'bundle',
  ];

  /**
   * {@inheritdoc}
   */
  protected $supportedInterfaceOrClass = WisskiEntityInterface::class;

  /**
   * {@inheritdoc}
   */
  public function normalize($object, $format = NULL, array $context = []) {
    $normalized = parent::normalize($object, $format, $context);

    // Get PbPaths and re-key them to fieldId.
    $pbs = WisskiPathbuilderEntity::loadMultiple();
    $pbPaths = [];
    foreach ($pbs as $pb) {
      $paths = $pb->getPbPaths();
      foreach ($paths as $id => $path) {
        $path['is_group'] = $path['bundle'] == $path['field'];
        $paths[$id] = $path;
      }
      $pbPaths += $paths;
    }

    // Get current bundle.
    $bundle = $object->bundle();

    // Create the fid->pathId mapping.
    $fidMap = [];
    foreach ($pbPaths as $pbPath) {
      $fidMap[$pbPath['field']] = $pbPath['id'];
    }

    foreach ($normalized as $fieldId => $values) {
      if (in_array($fieldId, self::META_KEYS)) {
        if (array_key_exists('meta', $context) && !$context['meta']) {
          unset($normalized[$fieldId]);
        }
        continue;
      }

      foreach ($values as $idx => $keys) {
        // Catch entity references and replace EID with URIs them.
        if (array_key_exists('target_type', $keys) && $keys['target_type'] == 'wisski_individual') {
          // Check if entity references should be expanded.
          if (array_key_exists('expand', $context) && $context['expand']) {
            // Check if the field is a bundle. This makes sure
            // that we never expand entity-references, which might contain
            // circular relations. Also make sure that this sub-bundle belongs
            // to the current entity's bundle.
            if ($pbPaths[$fidMap[$fieldId]]['is_group'] && self::belongsTo($fieldId, $bundle, $pbPaths, $fidMap)) {
              $normalized[$fieldId][$idx] = [];
              $subEntity = WisskiEntity::load($keys['target_id']);
              $normalized[$fieldId][$idx]['entity'] = $this->normalize($subEntity, context: $context);
              continue;
            }
          }
          $uri = current(AdapterHelper::doGetUrisForDrupalIdAsArray($keys['target_id']));
          $normalized[$fieldId][$idx] = [];
          $normalized[$fieldId][$idx]['target_uri'] = $uri;
        }
      }
    }
    return $normalized;
  }

  /**
   * Recursively check if a field belongs to a bundle.
   *
   * @param string $fieldId
   *   The ID of the field to be checked.
   * @param string $bundleId
   *   The ID of the bundle that should be checked.
   * @param array $pbPaths
   *   The pbPaths array from the pathbuilder(s).
   * @param array $fidMap
   *   An array that maps from fieldIds to pathIds.
   *
   * @return bool
   *   TRUE if the field belongs to the bundle, FALSE otherwise.
   */
  protected static function belongsTo(string $fieldId, string $bundleId, array $pbPaths, array $fidMap) {
    // Return FALSE if the fieldId is not known.
    if (!array_key_exists($fieldId, $fidMap)) {
      return FALSE;
    }
    // Return FALSE if the parent bundle is not known.
    $child = $pbPaths[$fidMap[$fieldId]];
    if (!array_key_exists($child['parent'], $pbPaths)) {
      return FALSE;
    }
    $parent = $pbPaths[$child['parent']];
    if ($parent['field'] == $bundleId) {
      return TRUE;
    }
    // Check if the fields' parent bundle belongs to the specified bundle.
    return self::belongsTo($parent['field'], $bundleId, $pbPaths, $fidMap);
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = []) {
    return $this->denormalizeEntity($data, $format, $context);
  }

  /**
   * Denormalize a WissKI Entity.
   *
   * @param array $data
   *   The normalized entity.
   * @param string $format
   *   The format the entity should was deserialized from.
   * @param array $context
   *   Context for the denormalizer.
   *   Keys:
   *   - bool 'meta': If TRUE Metadata will be included.
   *   - bool 'expand': If TRUE sub-entities will be expanded.
   *
   * @return \Drupal\wisski_core\Entity\WisskiEntity
   *   The denormalized entity.
   */
  private function denormalizeEntity(array $data, ?string $format = NULL, array $context = []): WisskiEntity {
    foreach ($data as $fieldId => $values) {
      if (in_array($fieldId, self::META_KEYS) || in_array($fieldId, self::WISSKI_KEYS)) {
        continue;
      }

      foreach ($values as $idx => $keys) {
        // Catch entity references and redirect them.
        if (array_key_exists('target_uri', $keys)) {
          $eid = AdapterHelper::getDrupalIdForUri($keys['target_uri']);
          $data[$fieldId][$idx] = [];
          $data[$fieldId][$idx]['target_id'] = $eid;
        }
        elseif (array_key_exists('entity', $keys)) {
          // Create the new sub-entity.
          $entity = $this->denormalize($keys['entity'], self::ENTITY_TYPE, $format, $context);
          $entity->save();
          if (!$entity) {
            continue;
          }

          // Reset the current entry.
          $data[$fieldId][$idx] = [];
          // Replace with Drupal-style entity reference.
          $data[$fieldId][$idx]['target_id'] = $entity->id();
          $data[$fieldId][$idx]['target_type'] = self::ENTITY_TYPE;
          // $data[$fieldId][$idx]['target_uuid'] = NULL;
        }
      }
    }
    return parent::denormalize($data, WisskiEntity::class, $format);
    // $this->entityTypeManager->getStorage(self::ENTITY_TYPE)->create($data);.
  }

}
