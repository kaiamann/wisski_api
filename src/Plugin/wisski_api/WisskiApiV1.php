<?php

namespace Drupal\wisski_api\Plugin\wisski_api;

use Drupal\Core\Entity\EntityBase;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\wisski_api\WisskiApiInterface;
use Drupal\wisski_core\Entity\WisskiBundle;
use Drupal\wisski_core\Entity\WisskiEntity;
use Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity;
use Drupal\wisski_salz\AdapterHelper;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides a WissKI API.
 *
 * @WisskiApi(
 *  id = "wisski_api_v1",
 *  version = 1,
 *  config = "wisski_api.v1"
 * )
 */
class WisskiApiV1 extends PluginBase implements WisskiApiInterface {

  /**
   * A current user instance which is logged in the session.
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The entity type manager
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The serializer.
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The WisskiEntity type.
   * @var string
   */
  protected string $entityType;

  /**
   * The WisskiPathbuilderEntity type id.
   * @var string
   */
  protected string $pathbuilderType;

  /**
   * The WisskiPathbuilderEntity type id.
   * @var string
   */
  protected string $pathType;

  /**
   * The version of the API
   * @var int
   */
  protected int $version = 0;

  /**
   * Constructs a Drupal\rest\Plugin\ResourceBase object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   A currently logged user instance.
   */
  public function __construct(
    $entityTypeManager,
    $currentUser,
    $serializer,
    $entityTypeRepository,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
    $this->serializer = $serializer;
    $this->entityType = $entityTypeRepository->getEntityTypeFromClass(WisskiEntity::class);
    $this->pathbuilderType = $entityTypeRepository->getEntityTypeFromClass(WisskiPathbuilderEntity::class);
  }

  // -----------------
  // -- Pathbuilder --
  // -----------------

  /**
   * {@inheritdoc}
   */
  public function getPathbuilder(string $pathbuilderId): WisskiPathbuilderEntity {
    $pathbuilder = WisskiPathbuilderEntity::load($pathbuilderId);
    if (!$pathbuilder) {
      throw new NoSuchEntityException("No {WisskiPathbuilderEntity::class} with ID: $pathbuilderId found");
    }
    return $pathbuilder;
  }

  public function getPathbuilders(?int $start = NULL, ?int $limit = NULL): array {
    return ["test"];
  }

  /**
   * {@inheritdoc}
   */
  public function getPathbuilderIds(?int $start = NULL, ?int $limit = NULL): array {
    // TODO: move this into class variable pathbuilderStorage or smth like that.
    $query = $this->entityTypeManager->getStorage($this->pathbuilderType)->getQuery();
    // Use the the pagination args
    $query->range($start, $limit);
    return $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getGroups(string $pathbuilderId, bool $main = False): array {
    $groupNames = [];
    $pathbuilder = $this->getPathbuilder($pathbuilderId);
    if (!empty($pathbuilder)) {
      $groups = $pathbuilder->getAllGroups();
      foreach ($groups as $group) {
        $groupNames[$group->id()] = $group->label();
      }
    }
    return $groupNames;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupPaths(string $pathbuilderId, string $groupId): array {
    $pathbuilder = $this->getPathbuilder($pathbuilderId);

    /** @var \Drupal\wisski_pathbuilder\Entity\WisskiPathEntity[] **/
    return $pathbuilder->getPathsAndGroupsForGroupId($groupId, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function createPathbuilder(array $data, $format = NULL): string {
    $pathbuilder = $this->serializer->denormalize($data, WisskiPathbuilderEntity::class, $format);
    $pathbuilder->save();
    return $pathbuilder->id();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePathbuilder(string $pathbuilderId): bool {
    try {
      $pathbuilder = $this->getPathbuilder($pathbuilderId);
    } catch (NoSuchEntityException) {
      return false;
    }
    $pathbuilder->delete();
    return true;
  }

  // -------------
  // -- Bundle --
  // ------------

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
  public function getUrisForBundle(string $bundleId, ?int $start, ?int $limit): array {
    $entityQuery = \Drupal::entityQuery($this->entityType);
    $entityQuery->condition('bundle', $bundleId);
    $entityQuery->range($start, $limit);
    $eids = $entityQuery->execute();

    $uris = [];
    foreach ($eids as $eid) {
      // TODO: see if the Adapter helper is adequate,
      // or if this should be reimplemented.
      $uris[] = current(AdapterHelper::getUrisForDrupalId($eid));
    }
    return $uris;
  }

  // ------------
  // -- Entity --
  // ------------

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
    //  TODO: use the EntityTypeRepository and
    //  EntityTypeManager to load if that turns out to be faster
    $entity = [$class, 'load']($id);
    if (empty($entity)) {
      throw new NoSuchEntityException("No such entity of class: $class with ID: $id!");
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityLanguages(string $uri): array {
    // get EID and load Entity
    $eid = AdapterHelper::getDrupalIdForUri($uri);
    /** @var \Drupal\wisski_core\Entity\WisskiEntity */
    $entity = $this->loadEntity(WisskiEntity::class, $eid);
    return array_keys($entity->getTranslationLanguages());
  }

  /**
   * {@inheritdoc}
   */
  public function getEntity(string $uri, ?string $langcode = NULL): WisskiEntity {
    // get EID and load Entity
    $eid = AdapterHelper::getDrupalIdForUri($uri);
    /** @var \Drupal\wisski_core\Entity\WisskiEntity */
    $entity = $this->loadEntity(WisskiEntity::class, $eid);

    // get the translated entity if a langcode was specified
    if ($langcode) {
      // check the available languages for the entity
      $availableLanguages = $entity->getTranslationLanguages();
      if (!array_key_exists($langcode, $availableLanguages)) {
        throw new NoSuchEntityException($this->t("Language with code @code does not exist for this entity", array('@code' => $langcode)));
      }
      $entity = $entity->getTranslation($langcode);
    }

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function createEntity(array $data): string {
    $entity = WisskiEntity::create($data);
    #      dpm(microtime(), "entity create!");
    #      dpm($entity_fields, "fields!");
    if ($entity) {
      $entity->save();
    }
    return $entity->id();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteEntity(string $uri): bool {
    $eid = AdapterHelper::getDrupalIdForUri($uri);
    $entity = WisskiEntity::load($eid);
    $entity->delete();
    return True;
  }


  /**
   * {@inheritdoc}
   */
  public function getEntityView(string $uri): string {
    // TODO: see if this is the right thing to return here
    // or if we should just reuturn the link here.
    return new RedirectResponse("/wisski/get?uri=$uri");
  }

  /**
   * {@inheritdoc}
   */
  public function queryEntity(string $query): array {
    return [];
  }

  // -------------
  // --- Utils ---
  // -------------

  /**
   * Recursively build a query object from the parsed AST.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface|\Drupal\Core\Entity\Query\ConditionInterface $query
   *   The query object or condition group to add the AST to.
   * @param array $ast
   *   The parsed AST.
   */
  function doBuildQuery(QueryInterface|ConditionInterface &$query, array $ast): void {
    // We can only handle binary expressions.
    // TODO: maybe add support for unary operators like "!".
    if ($ast['type'] === "BinaryExpression") {
      $left = $ast['left'];
      $right = $ast['right'];

      // Simple condition.
      if ($left['type'] === "Identifier") {
        $query->condition($left['name'], $right['value'], $ast['operator']);
      }
      // Multiple conditions.
      elseif ($left['type'] === "BinaryExpression" && $right['type'] === "BinaryExpression") {
        if ($ast['operator'] === '||') {
          $conditionGroup = $query->orConditionGroup();
        } elseif ($ast['operator'] === '&&') {
          $conditionGroup = $query->andConditionGroup();
        } else {
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

class NoSuchEntityException extends \Exception {}