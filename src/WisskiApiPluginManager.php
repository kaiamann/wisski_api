<?php

namespace Drupal\wisski_api;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * A manager for WisskiApi Plugins.
 *
 * @see \Drupal\wisski_api\WisskiApiInterface
 */
class WisskiApiPluginManager extends DefaultPluginManager implements WisskiApiPluginManagerInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManger;

  /**
   * A current user instance which is logged in the session.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The serializer.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The entityTypeRepository.
   *
   * @var \Drupal\Core\Entity\EntityTypeRepositoryInterface
   */
  protected $entityTypeRepository;

  /**
   * {@inheritDoc}
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
    EntityTypeManagerInterface $entityTypeManger,
    AccountProxyInterface $currentUser,
    Serializer $serializer,
    EntityTypeRepositoryInterface $entityTypeRepository
  ) {
    parent::__construct(
      'Plugin/wisski_api',
      $namespaces,
      $module_handler,
      'Drupal\wisski_api\WisskiApiInterface',
      'Drupal\wisski_api\Annotation\WisskiApi'
    );
    $this->entityTypeManger = $entityTypeManger;
    $this->currentUser = $currentUser;
    $this->serializer = $serializer;
    $this->entityTypeRepository = $entityTypeRepository;
    $this->alterInfo('wisski_api_info');
    $this->setCacheBackend($cache_backend, 'wisski_api_info_plugins');
  }

}
