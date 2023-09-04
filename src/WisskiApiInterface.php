<?php

namespace Drupal\wisski_api;

use Drupal\wisski_core\Entity\WisskiEntity;
use Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity;

/**
 * Provides an interface for a WissKI API.
 */
interface WisskiApiInterface {

  /**
   * Get a pathbuilder by ID.
   *
   * @return \Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity
   *   The pathbuilder.
   *
   * @throws \Drupal\wisski_api\Api\NoSuchEntityException
   *   If no pathbuilder with the Id exists.
   */
  public function getPathbuilder(string $pathbuilderId): WisskiPathbuilderEntity;

  /**
   * List all available pathbuilder IDs.
   *
   * @param int|null $start
   *   Number of pathbuilders to skip.
   * @param int|null $limit
   *   Number of pathbuilders to return.
   *
   * @return string[]
   *   A list of pathbuilder IDs.
   */
  public function getPathbuilderIds(?int $start = NULL, ?int $limit = NULL): array;

  /**
   * Create a new pathbuilder.
   *
   * @param array $data
   *   The normalized pathbuilder data.
   * @param array $format
   *   The format the given data was extracted from.
   */
  public function createPathbuilder(array $data, $format = NULL): void;

  /**
   * Import a pathbuilder in the xml format.
   *
   * @param array $data
   *   Data that describes the pathbuilder:
   *   Array with the following keys:
   *    - id: Id of the pathbuilder.
   *    - name: display name of the pathbuilder.
   *    - adapter: the adapter to which the pathbuilder belongs.
   *    - xml: the pathbuilder represented in an xml format.
   */
  public function importPathbuilder(array $data): void;

  /**
   * Export a pathbuilder in xml format.
   *
   * @param string $pathbuilderId
   *   The id of the pathbuilder to be exported.
   *
   * @return array
   *   Data that describes the pathbuilder:
   *   Array with the following keys:
   *    - id: Id of the pathbuilder.
   *    - name: display name of the pathbuilder.
   *    - adapter: the adapter to which the pathbuilder belongs.
   *    - xml: the pathbuilder represented in an xml format.
   */
  public function exportPathbuilder(string $pathbuilderId): array;

  /**
   * Delete a pathbuilder.
   *
   * @param string $pathbuilderId
   *   The ID of the pathbuilder to delete.
   */
  public function deletePathbuilder(string $pathbuilderId): void;

  /**
   * List all available bundles.
   *
   * @return string[]
   *   The response.
   */
  public function getBundles(): array;

  /**
   * Return the URIs of all entities within a bundle.
   *
   * @param string $bundleId
   *   The ID of the bundle.
   * @param int|null $start
   *   Number of entities to skip.
   * @param int|null $limit
   *   Number of entities to return.
   *
   * @return string[]
   *   A list of URIs
   */
  public function getUrisForBundle(string $bundleId, ?int $start, ?int $limit): array;

  /**
   * Return the available language for a particular entity.
   *
   * @param string $uri
   *   The URI of the desired entity.
   *
   * @return string[]
   *   A list of languages that are available for the entity.
   */
  public function getEntityLanguages(string $uri): array;

  /**
   * Returns a WissKI entity.
   *
   * @param string $uri
   *   The URI of the desired entity.
   * @param string $lang
   *   The desired language.
   *
   * @return \Drupal\wisski_core\Entity\WisskiEntity
   *   The WissKI Entity.
   */
  public function getEntity(string $uri, ?string $lang = NULL): WisskiEntity;

  /**
   * Returns a serailized entity.
   *
   * @param string $uri
   *   The URI of the desired entity.
   * @param string $lang
   *   The desired language.
   * @param bool $expand
   *   If sub-entities should be expanded.
   * @param bool $meta
   *   If metadata should be included.
   *
   * @return array
   *   The normalized WissKI Entity.
   */
  public function getNormalizedEntity(string $uri, ?string $lang = NULL, ?bool $expand = FALSE, ?bool $meta = TRUE): array;

  /**
   * Creates an new WissKI Entity.
   *
   * @param array $data
   *   The normalized entity data.
   * @param bool $overwrite
   *   If existing entities should be overwritten.
   *
   * @return string
   *   The URI of the new Entity.
   */
  public function createEntity(array $data, bool $overwrite = FALSE): string;

  /**
   * Delete an entity.
   *
   * @param string $uri
   *   The URI of the entity to delete.
   *
   * @return bool
   *   True if successful, false otherwise.
   */
  public function deleteEntity(string $uri): bool;

  /**
   * Get the view link to for an entity.
   *
   * @param string $uri
   *   The URI of the entity that should be redirected to.
   *
   * @return string
   *   The URL to the Entity view.
   */
  public function getEntityView(string $uri): string;

  /**
   * Performs an entity query.
   *
   * @param string $query
   *   The query to perform.
   *
   * @return string[]
   *   The EIDs of the matching Entities.
   */
  public function queryEntity(string $query): array;

}
