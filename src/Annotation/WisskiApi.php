<?php

namespace Drupal\wisski_api\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Annotation for WissKI API Plugins.
 *
 * @see \Drupal\wisski_api\WisskiApiInterface
 * @see \Drupal\wisski_api\WisskiApiPluginManager
 * @see wisski_api
 *
 * @Annotation
 */
class WisskiApi extends Plugin {

  /**
   * The human readable label.
   *
   * @var string
   */
  public string $label;

  /**
   * The version of the api.
   *
   * @var string
   */
  public int $version;

  /**
   * The name of the SwaggerUI config.
   *
   * @var string
   */
  public string $config;

  /**
   * Custom permissions that are defined by this API.
   *
   * @var array
   */
  public string $permissions;

}
