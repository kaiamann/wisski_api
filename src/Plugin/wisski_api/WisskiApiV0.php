<?php

namespace Drupal\wisski_api\Plugin\wisski_api;

use Drupal\Core\Entity\EntityBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\wisski_api\WisskiApiInterface;
use Drupal\wisski_core\Entity\WisskiBundle;
use Drupal\wisski_core\Entity\WisskiEntity;
use Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity;
use Drupal\wisski_pathbuilder\Entity\WisskiPathEntity;
use Drupal\wisski_salz\AdapterHelper;
use Drupal\wisski_salz\Entity\Adapter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Provides a WissKI API.
 *
 * @WisskiApi(
 *  id = "wisski_api_v0",
 *  version = 0,
 *  config = "wisski_api.v0",
 *  permissions = {
 *      "wisski_api.v0.read"= {
 *          "title" = @Translation("Read V0"),
 *          "description" = @Translation("Read access via WissKI API V0."),
 *          "restrict access" = false,
 *      },
 *      "wisski_api.v0.write" = {
 *          "title" = @Translation("Write V0"),
 *          "description" = @Translation("Write access via WissKI API V0."),
 *          "restrict access" = true,
 *      },
 *  }
 * )
 */
class WisskiApiV0 extends PluginBase implements WisskiApiInterface, ContainerFactoryPluginInterface {

  /**
   * A current user instance which is logged in the session.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The serializer.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The WisskiEntity type.
   *
   * @var string
   */
  protected string $entityType;

  /**
   * The WisskiPathbuilderEntity type id.
   *
   * @var string
   */
  protected string $pathbuilderType;

  /**
   * The WisskiPathbuilderEntity type id.
   *
   * @var string
   */
  protected string $pathType;

  /**
   * The version of the API.
   *
   * @var int
   */
  protected int $version = 0;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    SerializerInterface $serializer,
    EntityTypeRepositoryInterface $entity_type_repository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->serializer = $serializer;
    $this->entityType = $entity_type_repository->getEntityTypeFromClass(WisskiEntity::class);
    $this->pathbuilderType = $entity_type_repository->getEntityTypeFromClass(WisskiPathbuilderEntity::class);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('serializer'),
      $container->get('entity_type.repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getPathbuilder(string $pathbuilderId): WisskiPathbuilderEntity {
    return $this->loadEntity(WisskiPathbuilderEntity::class, $pathbuilderId);
  }

  /**
   * Helper for loading any entity.
   *
   * @param string $class
   *   The class to be loaded.
   * @param string $id
   *   The Id of the entity that should be loaded.
   *
   * @return \Drupal\Core\Entity\EntityBase
   *   The loaded entity.
   */
  protected function loadEntity($class, $id): EntityBase {
    // @todo use the EntityTypeRepository and
    // EntityTypeManager to load if that turns out to be faster.
    $entity = NULL;
    $idType = "EID";
    switch ($class) {
      case WisskiEntity::class:
        $eid = AdapterHelper::getDrupalIdForUri($id);
        $entity = WisskiEntity::load($eid);
        $idType = "URI";
        break;

      default: $entity = [$class, 'load']($id);
    }

    if (empty($entity)) {
      throw new NoSuchEntityException("No such entity of class: {$class} with {$idType}: {$id}!");
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getPathbuilderIds(?int $start = NULL, ?int $limit = NULL): array {
    $query = $this->entityTypeManager->getStorage($this->pathbuilderType)->getQuery();
    // Use the the pagination args.
    $query->range($start, $limit);
    // Return only the machine names as a list.
    return array_keys($query->execute());
  }

  /**
   * Get a normalized path with additional information from its pathbuilder.
   *
   * @param string $pathbuilderId
   *   The pathbuilder the path is used in.
   * @param string $pathId
   *   The path to be normalized.
   *
   * @return array
   *   The normalized path.
   */
  public function getPath(string $pathbuilderId, string $pathId): array {
    $pathbuilder = $this->getPathbuilder($pathbuilderId);
    $path = $this->loadEntity(WisskiPathEntity::class, $pathId);
    $normalizedPath = $this->serializer->normalize($path);
    $pbPath = $pathbuilder->getPbPath($pathId);
    if (!$pbPath) {
      throw new NoSuchEntityException("No path with ID $pathId in pathbuilder $pathbuilderId");
    }
    $normalizedPath += $pbPath;
    $normalizedPath += $this->getSubPathTree($pathId, $pathbuilder->getPathTree());
    return $normalizedPath;
  }

  /**
   * Undocumented function.
   *
   * @return void
   *   Something..
   */
  public function debug() {
    $pb = $this->getPathbuilder("gemaeldesammlung");
    return $pb->uuid();
  }

  /**
   * TODO: move this to the PB.
   *
   * Gets a sub-tree for a specific path of the pathTree of a pathbuilder.
   *
   * @param string $pathId
   *   The path for which the sub-tree should be returned.
   * @param array $pathTree
   *   The complete pathtree of the pathbuilder.
   *
   * @return array
   *   The sub-tree, or an empty array if none was found.
   */
  protected static function getSubPathTree(string $pathId, array $pathTree): array {
    foreach ($pathTree as $keys) {
      if ($keys['id'] == $pathId) {
        return $pathTree[$pathId];
      }
      $rec = self::getSubPathTree($pathId, $keys['children']);
      if ($rec) {
        return $rec;
      }
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function createPathbuilder(array $data, $format = NULL): void {
    $pathbuilder = $this->serializer->denormalize($data, WisskiPathbuilderEntity::class, $format);
    $pathbuilder->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePathbuilder(string $pathbuilderId): void {
    $pathbuilder = $this->getPathbuilder($pathbuilderId);
    $pathbuilder->delete();
  }

  /**
   * {@inheritdoc}
   */
  public function importPathbuilder($data): void {
    // Check if the pathbuilder already exists.
    if ($this->loadEntity(WisskiPathbuilderEntity::class, $data['id'])) {
      throw new EntityAlreadyExistsException(WisskiPathbuilderEntity::class, $data['id']);
    }

    // Generate a new UUID for the new PB.
    // This needs to be done manually as it is apparently not done automatically
    // upon saving. Not setting UUID leads to deprecation warnings, when trying
    // to edit the field of a path in the pb. Warnings occur in:
    // /Drupal/Core/Config/Entity/Query/Condition.php:39.
    $uuid_service = \Drupal::service('uuid');
    $uuid = $uuid_service->generate();

    $importmode = $data['mode'];
    $values = [
      'uuid' => $uuid,
      'id' => $data['id'],
      'name' => $data['name'],
      'adapter' => $data['adapter'],
    ];

    $pb = new WisskiPathbuilderEntity($values, "wisski_pathbuilder");
    $xmldoc = new \SimpleXMLElement($data['xml'], 0, FALSE);

    // Message logging.
    // TODO: check if the messages should be returned to the API caller.
    $messages = [];

    // TODO: check this code from here on out...
    // it's more or less copy-pasta from wisski_pathbuilder/WisskiPathbuiderForm::import()
    foreach ($xmldoc->path as $path) {
      $parentid = html_entity_decode((string) $path->group_id);

      // if($parentid != 0)
      // $parentid = wisski_pathbuilder_check_parent($parentid, $xmldoc);.
      $uuid = html_entity_decode((string) $path->uuid);

      // if(empty($uuid))
      // Check if path already exists.
      $path_in_wisski = WisskiPathEntity::load((string) $path->id);

      // It exists, skip this...
      if (!empty($path_in_wisski)) {
        $messages[] = "Path with id " . $uuid . " was already existing - skipping.";
        $pb->addPathToPathTree($path_in_wisski->id(), $parentid, $path_in_wisski->isGroup());
        // continue;.
      }
      // Normal case - import the path!
      else {
        $path_array = [];
        $count = 0;
        foreach ($path->path_array->children() as $n) {
          $path_array[$count] = html_entity_decode((string) $n);
          $count++;
        }

        // It does not exist, create one!
        $pathdata = [
          'id' => html_entity_decode((string) $path->id),
          'name' => html_entity_decode((string) $path->name),
          'path_array' => $path_array,
          'datatype_property' => html_entity_decode((string) $path->datatype_property),
          'short_name' => html_entity_decode((string) $path->short_name),
          'length' => html_entity_decode((string) $path->length),
          'disamb' => html_entity_decode((string) $path->disamb),
          'description' => html_entity_decode((string) $path->description),
          'type' => (((int) $path->is_group) === 1) ? 'Group' : 'Path',
        // 'field' => Pathbuilder::GENERATE_NEW_FIELD,
        ];

        // In D8 we do no longer allow a path/group without a name.
        // we have to set it to a dummy value.
        if ($pathdata['name'] == '') {
          $pathdata['name'] = "_empty_";
          $messages[] = $this->t(
            'Path with id @id (@uuid) has no name. Name has been set to "_empty_".',
            [
              '@id' => $pathdata['id'],
              '@uuid' => $uuid,
            ],
          );
        }

        $path_in_wisski = WisskiPathEntity::create($pathdata);

        $path_in_wisski->save();

        $pb->addPathToPathTree($path_in_wisski->id(), $parentid, $path_in_wisski->isGroup());
      }

      // Check enabled or disabled.
      $pbpaths = $pb->getPbPaths();

      $pbpaths[$path_in_wisski->id()]['enabled'] = html_entity_decode((string) $path->enabled);
      $pbpaths[$path_in_wisski->id()]['weight'] = html_entity_decode((string) $path->weight);
      $pbpaths[$path_in_wisski->id()]['cardinality'] = html_entity_decode((string) $path->cardinality);
      if (html_entity_decode((string) $importmode) != "keep") {
        if (((int) $path->is_group) === 1) {
          $pbpaths[$path_in_wisski->id()]['bundle'] = html_entity_decode((string) $importmode);
        }
        else {
          $pbpaths[$path_in_wisski->id()]['field'] = html_entity_decode((string) $importmode);
        }
      }
      else {
        $pbpaths[$path_in_wisski->id()]['bundle'] = html_entity_decode((string) $path->bundle);
        $pbpaths[$path_in_wisski->id()]['field'] = html_entity_decode((string) $path->field);

        if ($path->fieldtype) {
          $pbpaths[$path_in_wisski->id()]['fieldtype'] = html_entity_decode((string) $path->fieldtype);
        }

        if ($path->displaywidget) {
          $pbpaths[$path_in_wisski->id()]['displaywidget'] = html_entity_decode((string) $path->displaywidget);
        }

        if ($path->formatterwidget) {
          $pbpaths[$path_in_wisski->id()]['formatterwidget'] = html_entity_decode((string) $path->formatterwidget);
        }
      }
      $pb->setPbPaths($pbpaths);

    }

    $pb->save();
  }

  /**
   * {@inheritDoc}
   */
  public function exportPathbuilder(string $pathbuilderId): array {
    /** @var \Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity */
    $pathbuilder = $this->loadEntity(WisskiPathbuilderEntity::class, $pathbuilderId);
    return [
      'id' => $pathbuilder->id(),
      'name' => $pathbuilder->getName(),
      'adapter' => $pathbuilder->getAdapterId(),
      'xml' => $pathbuilder->toXML(),
    ];
  }

  /**
   * This code should probably be moved the the pathbuilder itself...
   */
  public function generateBundlesAndFields(string $pathbuilderId) {
    // Get the pathbuilder.
    $pathbuilder = WisskiPathbuilderEntity::load($pathbuilderId);

    $pbPaths = $pathbuilder->getPbPaths();
    $pathTree = $pathbuilder->getPathTree();

    $traverseTree = function ($tree) use ($pbPaths, $pathbuilder, &$traverseTree) {
      // Variables for keeping track of the amount of generated fields.
      $bundles = 0;
      $subBundles = 0;
      $fields = 0;
      foreach ($tree as $pathId => $path) {
        $pbPath = $pbPaths[$pathId];

        // If the field is disables, delete old fields that might
        // still be around and skip it.
        if (!$pbPath['enabled']) {
          $pathEntity = WisskiPathEntity::load($pathId);

          $field = $pathEntity->isGroup() ? $pbPath['bundle'] : $pbPath['field'];

          // Delete old fields.
          $field_storages = \Drupal::service('entity_type.manager')->getStorage('field_storage_config')->loadByProperties(['field_name' => $field]);
          if (!empty($field_storages)) {
            foreach ($field_storages as $field_storage) {
              $field_storage->delete();
            }
          }

          $field_objects = \Drupal::service('entity_type.manager')->getStorage('field_config')->loadByProperties(['field_name' => $field]);
          if (!empty($field_objects)) {
            foreach ($field_objects as $field_object) {
              $field_object->delete();
            }
          }
          continue;
        }

        // Generate field for this path.
        $pathEntity = WisskiPathEntity::load($path['id']);
        if ($pathEntity->isGroup()) {
          // Save the original bundle id because
          // if it is overwritten in create process
          // we won't have it anymore.
          $pbpaths = $pathbuilder->getPbPaths();

          // Which group should I handle?
          $my_group = $pbpaths[$pathEntity->id()];

          // Original bundle.
          $ori_bundle = $my_group['bundle'];

          $pathbuilder->generateBundleForGroup($pathEntity->id());
          if (!in_array($pathEntity->id(), array_keys($pathbuilder->getMainGroups()))) {
            $pathbuilder->generateFieldForSubGroup($pathEntity->id(), $pathEntity->getName(), $ori_bundle);
            $subBundles++;
          }
          else {
            $bundles++;
          }
        }
        else {
          $pathbuilder->generateFieldForPath($pathEntity->id(), $pathEntity->getName());
          $fields++;
        }

        // Generate fields for children.
        $res = $traverseTree($path['children']);
        // Track stats.
        $bundles += $res['bundles'];
        $subBundles += $res['sub_bundles'];
        $fields += $res['fields'];
      }
      return [
        'bundles' => $bundles,
        'sub_bundles' => $subBundles,
        'fields' => $fields,
      ];
    };

    return $traverseTree($pathTree);
  }

  /**
   * Bundle.
   */

  /**
   * {@inheritdoc}
   */
  public function getBundles(): array {
    $bundleEntities = WisskiBundle::loadMultiple();
    $bundles = [];
    foreach ($bundleEntities as $bundle) {
      $bundles[$bundle->id] = $bundle->label;
    }
    return $bundles;
  }

  /**
   * {@inheritdoc}
   */
  public function getUrisForBundle(string $bundleId, ?int $start = NULL, ?int $limit = NULL): array {
    $entityQuery = \Drupal::entityQuery($this->entityType);
    $entityQuery->condition('bundle', $bundleId);
    $entityQuery->range($start, $limit);
    $eids = $entityQuery->execute();

    $uris = [];
    foreach ($eids as $eid) {
      $uris[] = current(AdapterHelper::getUrisForDrupalId($eid));
    }
    return $uris;
  }

  /**
   * Entity.
   */

  /**
   * {@inheritdoc}
   */
  public function getEntityLanguages(string $uri): array {
    /** @var \Drupal\wisski_core\Entity\WisskiEntity */
    $entity = $this->loadEntity(WisskiEntity::class, $uri);
    return array_keys($entity->getTranslationLanguages());
  }

  /**
   * {@inheritdoc}
   */
  public function getEntity(string $uri, ?string $lang = NULL): WisskiEntity {
    /** @var \Drupal\wisski_core\Entity\WisskiEntity */
    $entity = $this->loadEntity(WisskiEntity::class, $uri);

    // Get the translated entity if a langcode was specified.
    if ($lang) {
      // Check the available languages for the entity.
      $availableLanguages = $entity->getTranslationLanguages();
      if (!array_key_exists($lang, $availableLanguages)) {
        throw new NoSuchEntityException("Language with code $lang does not exist for this entity");
      }
      $entity = $entity->getTranslation($lang);
    }
    return $entity;
  }

  /**
   * {@inheritDoc}
   */
  public function getNormalizedEntity(string $uri, ?string $lang = NULL, ?bool $expand = FALSE, ?bool $meta = FALSE): array {
    $entity = $this->getEntity($uri, $lang);
    $context = [
      'expand' => $expand,
      'meta' => $meta,
    ];
    return $this->serializer->normalize($entity, context: $context);
  }

  /**
   * {@inheritdoc}
   */
  public function createEntity(array $data, bool $overwrite = FALSE): string {
    if (array_key_exists('eid', $data)) {
      throw new \Exception("The EID key is not supported!");
    }
    $uri = $data['wisski_uri'][0]['value'] ?? NULL;
    if ($uri) {
      if (!$overwrite) {
        throw new \Exception("Please call the API with the 'overwrite' query parameter set to 'true' if you wish to overwrite entities.");
      }
      // Replace 'wisski_uri' key with eid.
      // Only EID works when an entity is overwritten.
      $data['eid'][] = ['value' => AdapterHelper::getDrupalIdForUri($uri)];
      unset($data['wisski_uri']);
    }
    /** @var \Drupal\wisski_core\Entity\WisskiEntity */
    $entity = $this->serializer->denormalize($data, WisskiEntity::class);
    if ($entity) {
      $entity->save();
    }
    // TODO: see what's going on here... URI seems not to be set right after
    // saving the entity. However looking it up with the AdapterHelper seems to
    // work.
    $uri = $entity->get("wisski_uri")->value;
    if (!$uri) {
      $uri = current(AdapterHelper::getUrisForDrupalId($entity->id(), NULL, FALSE));
    }
    return $uri;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteEntity(string $uri): bool {
    $eid = AdapterHelper::getDrupalIdForUri($uri);
    $entity = WisskiEntity::load($eid);
    $entity->delete();
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityView(string $uri): string {
    // @todo see if this is the right thing to return here
    // or if we should just reuturn the link here.
    return new RedirectResponse("/wisski/get?uri=$uri");
  }

  /**
   * {@inheritdoc}
   */
  public function queryEntity(string $query): array {
    return [];
  }

  /**
   * Utils.
   */

  /**
   * Recursively build a query object from the parsed AST.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface|\Drupal\Core\Entity\Query\ConditionInterface $query
   *   The query object or condition group to add the AST to.
   * @param array $ast
   *   The parsed AST.
   */
  protected function doBuildQuery(QueryInterface|ConditionInterface &$query, array $ast): void {
    // We can only handle binary expressions.
    // @todo maybe add support for unary operators like "!".
    if ($ast['type'] === "BinaryExpression") {
      $left = $ast['left'];
      $right = $ast['right'];

      // Simple condition.
      if ($left['type'] === "Identifier") {
        $query->condition($left['name'], $right['value'], $ast['operator']);
      }
      // Multiple conditions.
      elseif ($left['type'] === "BinaryExpression" && $right['type'] === "BinaryExpression") {
        $conditionGroup = NULL;
        if ($ast['operator'] === '||') {
          $conditionGroup = $query->orConditionGroup();
        }
        elseif ($ast['operator'] === '&&') {
          $conditionGroup = $query->andConditionGroup();
        }
        else {
          throw new \Exception('Invalid query structure');
        }
        // Build groups for the children.
        $this->doBuildQuery($conditionGroup, $left);
        $this->doBuildQuery($conditionGroup, $right);
        // Add the condition group to the query.
        $query->condition($conditionGroup);
      }
    }
  }

}

/**
 * Error class indicating that an entity does not exist.
 */
class NoSuchEntityException extends \Exception {}

/**
 * Class indicating that a Pathbuilder already exists.
 */
class EntityAlreadyExistsException extends \Exception {

  /**
   * The class of the entity that already exists.
   *
   * @var string
   */
  public string $class;

  /**
   * The id of the entity that already exists.
   *
   * @var string
   */
  public string $id;

  /**
   * {@inheritDoc}
   */
  public function __construct(string $class, string $id, int $code = 0, \Throwable $previous = NULL) {
    $this->class = $class;
    $this->id = $id;
    $message = "Entity of class '{$class}' with id '{$id}' already exists!";
    parent::__construct($message, $code, $previous);
  }

}
