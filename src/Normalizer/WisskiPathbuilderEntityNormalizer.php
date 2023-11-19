<?php

namespace Drupal\wisski_api\Normalizer;

use Drupal\serialization\Normalizer\EntityNormalizer;
use Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity;
use Drupal\wisski_pathbuilder\Entity\WisskiPathEntity;

/**
 * A normalizer for WisskiPathbuilderEntities.
 */
class WisskiPathbuilderEntityNormalizer extends EntityNormalizer {
  // Keys from the pbPaths array that should be included in the export.
  const PBPATH_PROPERTIES = [
    "id",
    "weight",
    "enabled",
    "parent",
    "bundle",
    "field",
    "fieldtype",
    "displaywidget",
    "formatterwidget",
    "autocomplete_title_pattern_enabled",
    "cardinality",
    "field_type_informative",
    "relativepath",
  ];

  // List specifying which values of the WisskiPathbuilderEntity will be
  // normalized. The key in this array will be the key in the normalized
  // entity. The value in this array is the function name that returns the
  // value for the key.
  const PATHBUILDER_PROPERTIES = [
    'id' => 'id',
    'name' => 'getName',
    'adapter' => 'getAdapterId',
  ];

  // List specifying which values of the WisskiPathEntity will be normalized.
  // The key in this array will be the key in the normalized entity.
  // The value in this array is the function name that returns the value for
  // the key.
  const PATH_PROPERTIES = [
    'name' => 'getName',
    'path_array' => 'getPathArray',
    'is_group' => 'isGroup',
    'path_array' => 'getPathArray',
    'datatype_property' => 'getDatatypeProperty',
    'disamb' => 'getDisamb',
    'short_name' => 'getShortName',
    'length' => 'getLength',
    'description' => 'getDescription',
    'type' => 'getType',
  ];

  const REQUIRED_PROPERTIES = [
    "weight" => 0,
    "field" => "",
    "fieldtype" => "",
    "formatterwidget" => "",
    "displaywidget" => "",
  ];

  // Set this to handle only WisskiPathbuilderEntity instances.
  /**
   * {@inheritdoc}
   */
  protected $supportedInterfaceOrClass = WisskiPathbuilderEntity::class;

  /**
   * {@inheritdoc}
   */
  public function normalize($object, $format = NULL, array $context = []): array {
    if ($object instanceof WisskiPathbuilderEntity) {
      return [
        'id' => $object->id(),
        'name' => $object->getName(),
        'adapter' => $object->getAdapterId(),
        'tree' => $object->getPathTree(),
        'paths' => $object->getPbPaths(),
      ];
      // return $this->normalizePathbuilder($object);
    }
    return NULL;
  }

  /**
   * Normalize a WisskiPathbuilderEntity.
   *
   * @param \Dupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity $pb
   *   The pathbuilder to be normalized.
   *
   * @return array
   *   Thr normalized pathbuilder.
   */
  protected function normalizePathbuilder(WisskiPathbuilderEntity $pb): array {
    $normalizedPb = [];
    foreach (self::PATHBUILDER_PROPERTIES as $key => $func) {
      $normalizedPb[$key] = [$pb, $func]();
    }
    $normalizedPb['paths'] = $this->mergePathTreeAndPbPaths($pb->getPathTree(), $pb->getPbPaths());
    return $normalizedPb;
  }

  /**
   * Combines the the pathtree and pbpaths of a pathbuilder.
   *
   * @param array $pathTree
   *   The pathtree of a pathbuilder.
   *   Contains the tree structure of the contained paths.
   * @param array $pbPaths
   *   The pbpaths of a pathbuilder.
   *   Contains meta information about each of the paths.
   *
   * @return array
   *   The pathtree with the information of the pbpaths.
   */
  protected function mergePathTreeAndPbPaths(array $pathTree, array $pbPaths): array {
    $normalizedTrees = [];
    foreach ($pathTree as $id => $data) {
      $normalizedPath = [];

      // Get the desired properties from the pbPaths.
      foreach ($pbPaths[$id] as $key => $value) {
        // Skip irrelevant or empty ones.
        if (!in_array($key, self::PBPATH_PROPERTIES)) {
          continue;
        }
        $normalizedPath[$key] = $value;
      }

      // Get the desired properties from the Path Entity.
      $path = WisskiPathEntity::load($id);
      foreach (self::PATH_PROPERTIES as $key => $func) {
        $normalizedPath[$key] = [$path, $func]();
      }

      // Handle children recursively.
      $children = $this->mergePathTreeAndPbPaths($data['children'], $pbPaths);
      $normalizedPath['children'] = $children;
      $normalizedTrees[$id] = $normalizedPath;
    }
    return $normalizedTrees;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = []): mixed {
    $pbPaths = [];
    $pb_data['pathtree'] = $this->splitPathTreeAndPbPaths($data['paths'], $pbPaths);
    $pb_data['pbpaths'] = $pbPaths;
    foreach (array_keys(self::PATHBUILDER_PROPERTIES) as $key) {
      if (array_key_exists($key, $data)) {
        $pb_data[$key] = $data[$key];
      }
    }
    return WisskiPathbuilderEntity::create($pb_data);
  }

  /**
   * Split pathtree and pbpaths.
   *
   * @param array $tree
   *   The combined pathtree and pbpaths.
   * @param array $pbPaths
   *   A reference to an array to which the pbpaths data will be stored.
   *
   * @return array
   *   The split pathtree.
   *
   * @see self::mergePathTreeAndPbPaths
   */
  private function splitPathTreeAndPbPaths(array $tree, array &$pbPaths) {
    $newTrees = [];
    foreach ($tree as $id => $data) {
      $newTree['id'] = $id;

      // Create a new pbPath entry and add ID.
      $pbPath['id'] = $id;
      $pathEntityData['id'] = $id;
      // @todo we should iterate over the PBPATH_PROPERTIES here
      // instead of data and see if smth is missing, as
      // iterating over the $data does not cover missing keys...
      foreach ($data as $key => $value) {
        // Skip the properties from the path entity.
        if (in_array($key, array_keys(self::PATH_PROPERTIES))) {
          $pathEntityData[$key] = $value;
          continue;
        }
        // Copy the relevant data to the new pbPath.
        if (in_array($key, self::PBPATH_PROPERTIES)) {
          $pbPath[$key] = $value;
        }
      }
      // And add it to the pbPaths list.
      $pbPaths[$id] = $pbPath;

      // See if a PathEntity with this id already exists.
      $path = WisskiPathEntity::load($id);
      if (!$path) {
        // Create a new one and save it if there is not.
        $path = WisskiPathEntity::create($pathEntityData);
        $path->save();
      }

      // Handle the children recursively.
      $newTree['children'] = [];
      if (array_key_exists('children', $data)) {
        $newTree['children'] = $this->splitPathTreeAndPbPaths($data['children'], $pbPaths);
      }
      $newTrees[$id] = $newTree;
    }
    return $newTrees;
  }

}
