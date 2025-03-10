<?php

namespace Drupal\wisski_api\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\Url;
use Drupal\wisski_api\Controller\WisskiApiController;
use Drupal\wisski_api\WisskiApiPluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Form for configuring the WissKI API.
 */
class WisskiApiConfigForm extends ConfigFormBase {

  /**
   * The route builder.
   *
   * @var \Drupal\Core\Routing\RouteBuilderInterface
   */
  protected $routerBuilder;

  /**
   * The WissKI API manager.
   *
   * @var \Drupal\wisski_api\WisskiApiPluginManagerInterface
   */
  protected $apiManager;

  /**
   * Constructs a \Drupal\wisski_api\WisskiApiConfigForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface
   *   The typed configuration manager service.
   * @param \Drupal\Core\Routing\RouteBuilderInterface $router_builder
   *   The route builder.
   * @param \Drupal\wisski_api\WisskiApiPluginManagerInterface $api_manager
   *   The route builder.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    RouteBuilderInterface $router_builder,
    WisskiApiPluginManagerInterface $api_manager,
    RequestStack $request_stack) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->routerBuilder = $router_builder;
    $this->apiManager = $api_manager;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('router.builder'),
      $container->get('plugin.manager.wisski_api'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function getFormId(): string {
    return self::class;
  }

  /**
   * {@inheritDoc}
   */
  public function getEditableConfigNames(): array {
    return [
      'wisski_api.settings',
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Get the save button from the parent.
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('wisski_api.settings');

    $header = [
      'plugin_id' => [
        'data' => $this->t('plugin_id'),
      ],
      'version' => [
        'data' => $this->t('Version'),
        'field' => 'version',
        'sort' => 'asc',
      ],
      'status' => [
        'data' => $this->t('Status'),
      ],
      'documentation' => [
        'data' => $this->t('Documentation'),
      ],
      'permissions' => [
        'data' => $this->t('Custom Permissions'),
      ],
    ];
    // Get the available APIs from the plugin manager.
    $options = [];

    foreach ($this->apiManager->getDefinitions() as $pluginId => $definition) {
      $version = $definition['version'];

      // Defaults.
      $status = $this->t("Disabled");
      $documentation = $this->t("Not Available");

      // Check config if the APIs are enabled.
      $enabled = $config->get($pluginId);
      if ($enabled) {
        // Documentation route is the base prefix.
        $path = WisskiApiController::buildPrefix($version);
        // Create URL from route name.
        $routeName = WisskiApiController::getRouteNameFromPath($path);
        $url = Url::fromRoute($routeName);
        $documentation = Link::fromTextAndUrl($this->t("Show"), $url)->toRenderable();
        $status = $this->t("Enabled");
      }

      // See if there are any permissions and format them into a list.
      $permissions = $definition['permissions'] ?? $this->t("No custom permissions");
      if (is_array($permissions)) {
        $permissionTitles = [];
        foreach ($permissions as $id => $fields) {
          $permissionTitles[] = $fields['title'] ?? $id;
        }
        $permissions = "<ul><li>" . implode('</li><li>', $permissionTitles) . "</li></ul>";
      }

      $options[$pluginId] = [
        'plugin_id' => ['data' => $pluginId],
        'version' => ['data' => $version],
        'status' => ['data' => $status],
        'documentation' => ['data' => $documentation],
        'permissions' => ['data' => ['#markup' => $permissions]],
      ];
    }

    // Sort the options.
    $sort = $this->getRequest()->get('sort', 'asc');
    $comp = function (array $first, array $second) use ($sort) {
      if ($first['version'] == $second['version']) {
        return 0;
      }
      elseif ($first['version'] > $second['version']) {
        return $sort == 'asc' ? 1 : -1;
      }
      return $sort == 'asc' ? -1 : 1;
    };
    uasort($options, $comp);

    $form['manage'] = [
      '#type' => 'fieldset',
      // '#title' => $this->t("Manage WissKI APIs"),
      '#title' => $this->t("Enable or disable WissKI APIs"),
    ];

    $form['manage']['api_list'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $options,
      '#default_value' => $config->getRawData(),
    ];

    // Add fieldset for permission handling.
    $form['permissions'] = [
      '#type' => 'fieldset',
      '#title' => $this->t("Permissions"),
    ];
    $form['permissions'][] = [
      '#type' => 'link',
      '#title' => $this->t('Manage WissKI API Permissions'),
      '#url' => Url::fromRoute('user.admin_permissions.module', ['modules' => 'wisski_api']),
    ];

    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    foreach ($form_state->getValue('api_list') as $pluginId => $enabled) {
      $this->config('wisski_api.settings')
        ->set($pluginId, $enabled)
        ->save();
    }
    // Rebuild routes to apply changes.
    $this->routerBuilder->rebuild();
    parent::submitForm($form, $form_state);
  }

}
