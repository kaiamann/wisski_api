<?php

namespace Drupal\wisski_api\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\wisski_api\WisskiApiPluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Generic controller for the WissKI API.
 *
 * This controller relies on a well-defined SwaggerUI config file:
 *  - For each path the `operationId` has to correspond to the function name
 *    that should be called in the WissKIAPI.
 *  - The parameters in the `parameters` section of the path config and the
 *    parameters of the corresponding API function HAVE to
 *    have the same names.
 *  - Each parameter should have the `in` key set to the location of the
 *    parameter:
 *      * `path` for path parameters
 *      * `query` for query parameters.
 *
 * High-level things that this Controller does:
 * - Generating routes: self::routes()
 *  - Firstly the functions registered in self::HANDLER_FUNCTIONS are inspected
 *    via reflection and their signature is saved into $this->handlers.
 *  - To generate the routes the controller parses the SwaggerUI config yml.
 *  - According to the number of arguments in this config a controller
 *    callback is chosen from self::HANDLER_FUNCTIONS
 *  - The parameter names in the path of the Swagger config is replaced by the
 *    parameter names of the previously chosen handler.
 *    E.g.:
 *    -callback:
 *        function controllerCallback(Request $request, $param1, $param2)
 *    - path:
 *        /{manufacturer}/{model}/... => /{param1}/{param2}/...
 *  - This mapping of paths and parameters is saved internally in $this->pathMap
 *    along some other configs from the SwaggerUI config.
 *
 * - Handing requests:
 *  - Whenever a request is recieved it is handled by one of the predefined
 *    handlers that pass the request on to the main handler.
 *  - The main handler gets the current route, looks up the API function that
 *    is assigned to this route.
 *  - With the help of the internal config it figures out which arguments the
 *    function takes and finally calls that function with the needed arguments.
 *  - Then the result is wrapped into a response and sent back.
 */
class WisskiApiController extends ControllerBase implements ContainerInjectionInterface {
  // Base api path prefix.
  const API_PREFIX = '/wisski/api';

  // Maps the Content-Type header values from a
  // request to the corresponding content type
  // for the Drupal serializer.
  const FORMAT_MAP = [
    'application/json' => 'json',
    'text/xml' => 'xml',
  ];

  // The handlers that each handle Routes with differing
  // amount of path parameters. The index in this array
  // indicates how many path parameters each handler takes.
  const HANDLER_FUNCTIONS = [
    'noParamHandler',
    'oneParamHandler',
    'twoParamHandler',
    'threeParamHandler',
  ];

  /**
   * The wisski api manager.
   *
   * @var \Drupal\wisski_api\WisskiApiPluginManagerInterface
   */
  protected $apiManager;

  /**
   * The serializer.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The path map config.
   *
   * This config saves the mapping from generated paths
   * back to callable functions with the right arguments.
   * It also saves some other configuration data.
   *
   * Example for an entry with the following controller callback signature:
   * function twoParamHandler(Request $request, $first, $second)
   *
   * /api/v0/{first}/{second} => array(
   *  'apiFunction' => getManufacturer,
   *  'handler' => twoParamHandler
   *  'paramMap' => array(
   *      'first' => 'manufacturer',
   *      'second' => 'model'
   *      ),
   *  'pluginId' => car.api.v0,
   *  'queryParameters' => [color, mileage],
   * )
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $pathMap;

  /**
   * Config containing handler signatures.
   *
   * Contains a map that contains the parameter
   * List for each handler in self::HANDLER_FUNCTIONS.
   *
   * Example:
   * twoParamHandler => array(
   *   'frist',
   *   'second'
   * )
   *
   * It is generated from the signature of these handlers
   * using reflection and is created when the routes are
   * registered to Drupal, which usually happens on a
   * cache rebuild.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $handlers;

  /**
   * The route match interface for getting the current route.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a Drupal\rest\Plugin\ResourceBase object.
   *
   * @param \Drupal\wisski_api\WisskiApiPluginManagerInterface $apiManager
   *   The wisski api.
   * @param \Symfony\Component\Serializer\SerializerInterface $serializer
   *   The serializer.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factoy.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The route match.
   */
  public function __construct(
    WisskiApiPluginManagerInterface $apiManager,
    SerializerInterface $serializer,
    ConfigFactoryInterface $configFactory,
    RouteMatchInterface $routeMatch,
  ) {
    $this->apiManager = $apiManager;
    $this->serializer = $serializer;
    $this->configFactory = $configFactory;
    $this->pathMap = $configFactory->getEditable('wisski_api.path_map');
    $this->handlers = $configFactory->getEditable('wisski_api.handlers');
    $this->routeMatch = $routeMatch;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.wisski_api'),
      $container->get('serializer'),
      $container->get('config.factory'),
      $container->get('current_route_match'),
    );
  }

  /**
   * Request Handlers.
   */

  /**
   * Handler for routes with no path parameters.
   *
   * Drupal forces controller callbacks to explicitly
   * have the path parameters with the matching parameter
   * name in the function signature.
   * Also dynamically adding functions to a PHP class
   * is not possible before actually initializing the
   * class. This makes it impossible to use dynamically
   * added functions as route callbacks, which leaves
   * us no choice but to cope with this ugly solution.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to be processed.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function noParamHandler(Request $request): Response {
    return $this->handler($request);
  }

  /**
   * Handler for routes with one path parameter.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to be processed.
   * @param string $first
   *   The first path parameter.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function oneParamHandler(Request $request, string $first): Response {
    return $this->handler($request, $first);
  }

  /**
   * Handler for routes with two path parameters.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to be processed.
   * @param string $first
   *   The first path parameter.
   * @param string $second
   *   The second path parameter.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function twoParamHandler(Request $request, string $first, string $second): Response {
    return $this->handler($request, $first, $second);
  }

  /**
   * Handler for routes with three path parameters.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to be processed.
   * @param string $first
   *   The first path parameter.
   * @param string $second
   *   The second path parameter.
   * @param string $third
   *   The third path parameter.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function threeParamHandler(Request $request, string $first, string $second, string $third): Response {
    return $this->handler($request, $first, $second, $third);
  }

  /**
   * General request handler.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to be processed.
   * @param string[] $params
   *   The path parameters path parameter.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  protected function handler(Request $request, ...$params): Response {
    $path = $this->routeMatch->getRouteObject()->getPath();

    $pathMethods = $this->pathMap->get($path);
    // This should not be able to happen but sanity check anyway.
    if (!$pathMethods) {
      return $this->buildErrorResponse("No such API route: $path");
    }
    $currentMethod = strtolower($request->getMethod());

    // Check if this path supports the current method.
    // This should not even happen since the Route itself
    // should already return a `405 Method Not Allowed` if
    // the wrong method is requested before this hanlder
    // is even called.
    if (!in_array($currentMethod, array_keys($pathMethods))) {
      return $this->buildErrorResponse("Endpoint: $path does not handle $currentMethod requests.");
    }
    $pathConfig = $pathMethods[$currentMethod];

    // Unpack the config for this path.
    $apiFunction = $pathConfig['apiFunction'];
    $handler = $pathConfig['handler'];
    $paramMap = $pathConfig['parameterMap'];
    $pluginId = $pathConfig['pluginId'];
    $queryParameters = $pathConfig['queryParameters'];

    // For each passed parameter to look up the original function parameter
    // name, to be able to call the corresponding API function.
    $methodParams = [];
    foreach ($params as $idx => $value) {
      // Look up the actual function name in the config.
      $key = $this->handlers->get($handler)[$idx];
      $methodParams[$paramMap[$key]] = $value;
    }

    // Add the body if the current request is a post request.
    if ($currentMethod === "post") {
      // @todo figure out the content type and deserialize accordingly.
      // TODO: just pass the raw body and let each function handle
      // it differently?
      $decodedData = Json::decode($request->getContent());
      if ($decodedData) {
        $methodParams['data'] = $decodedData;
      }
      else {
        $methodParams['data'] = $request->getContent();
      }
      // TODO: add error handling here.
    }

    // Also add the query params.
    foreach ($queryParameters as $queryParam) {
      $param = $request->get($queryParam);
      // Only set the param if it is actually supplied.
      if ($param !== NULL) {
        $methodParams[$queryParam] = $param;
      }
    }

    // Get the correct API from the APIManager, call the registered
    // function with the extracted paramters and return the result.
    /** @var \Drupal\wisski_api\WisskiApiInterface */
    $api = $this->apiManager->createInstance($pluginId);
    try {
      $result = [$api, $apiFunction](...$methodParams);
      return $this->buildResponse($result, $request);
    }
    catch (\Exception $exception) {
      return $this->buildErrorResponse($exception->getMessage());
    }
  }

  /**
   * Dynamic Callbacks for Routes/Permsissions.
   */

  /**
   * Callback for getting custom permissions from the individual APIs.
   *
   * Reads the API's Plugin definition in the class Annotation and
   * extracts the defined permissions within.
   *
   * @see wisski_api.permissions.yml
   *
   * @return array
   *   A list of permission definitions.
   */
  public function permissions(): array {
    $permissions = [];
    $apiDefintions = $this->apiManager->getDefinitions();
    foreach ($apiDefintions as $pluginDefinition) {
      $permissions += $pluginDefinition['permissions'] ?? [];
    }
    return $permissions;
  }

  /**
   * Generates the routes to access the API dynamically.
   *
   * Iterates over all WisskiApi plugins and
   * creates the routes for each of one.
   *
   * @see wisski_api.routing.yml
   *
   * @return \Symfony\Component\Routing\RouteCollection
   *   A collection of routes for all plugins.
   */
  public function routes(): RouteCollection {
    // Extract the signatures and save them for later.
    $this->extractHandlerSignatures();

    $routeCollection = new RouteCollection();

    // Iterate over all found WissKI API Plugins.
    $apiDefintions = $this->apiManager->getDefinitions();
    foreach ($apiDefintions as $pluginId => $pluginDefinition) {
      $config = $pluginDefinition['config'];
      $version = $pluginDefinition['version'];
      $prefix = $this->buildPrefix($version);

      // See if this API plugin is actually enabled.
      if (!$this->configFactory->get('wisski_api.settings')->get($pluginId)) {
        continue;
      }
      $routeCollection->addCollection($this->buildRoutes($config, $prefix, $pluginId));
    }
    return $routeCollection;
  }

  /**
   * Extracts the signatures for each handler.
   *
   * Extract the parameters for each handler registered
   * to this class via self::HANDLER_FUNCTIONS. The
   * signature is then saved in the local $handlers config.
   *
   * The parameters are put into the config in the order
   * they are declared in the functions signature.
   * Also parameters of type \Symfony\Component\HttpFoundation\Request
   * are not included into the config.
   *
   * Example for an entry:
   * "towParamHandler" => ['first', 'second']
   */
  protected function extractHandlerSignatures(): void {
    foreach (self::HANDLER_FUNCTIONS as $handler) {
      $method = new \ReflectionMethod(self::class, $handler);
      $parameters = [];
      foreach ($method->getParameters() as $parameter) {
        // Skip parameters of type \Symfony\Component\HttpFoundation\Request.
        $type = $parameter->getType();
        if (
          $type instanceof \ReflectionNamedType && $type == Request::class ||
          ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) && in_array(Request::class, $type->getTypes())
        ) {
          continue;
        }
        $parameters[] = $parameter->getName();
      }
      $this->handlers->set($handler, $parameters);
    }
    $this->handlers->save();
  }

  /**
   * Route Building.
   */

  /**
   * Generates the routes to access an API plugin dynamically.
   *
   * Iterates over the SwaggerUI config file and generates
   * new Routes accordingly.
   *
   * The route parameters of routes that are generated are
   * renamed to match the parameter names of the generic
   * callback handlers in self::HANDLER_FUNCTIONS
   *
   * E.g.
   * callback: function callback(Request $request, $param1, $param2)
   * path: /{manufacturer}/{model}/... => /{param1}/{param2}/...
   *
   * This mapping is also stored in a config for later,
   * when a request is handled.
   *
   * @param string $config
   *   The name of the SwaggerUI config file that defines the API routes.
   * @param string $prefix
   *   The API prefix.
   * @param string $pluginId
   *   The pluginId.
   *
   * @return \Symfony\Component\Routing\RouteCollection
   *   A collection of routes for the pratcular API plugin.
   */
  protected function buildRoutes(string $config, string $prefix, string $pluginId): RouteCollection {
    $routeCollection = new RouteCollection();

    // Look though all paths of the Swagger config yml.
    foreach ($this->configFactory->get($config)->get('paths') as $path => $methods) {
      // Iterate over all supported methods of each path.
      foreach ($methods as $method => $settings) {
        // Extract query and path parameters.
        $pathParameters = [];
        $queryParameters = [];
        if (array_key_exists('parameters', $settings)) {
          $params = $settings['parameters'];
          foreach ($params as $param) {
            if ($param['in'] === 'query') {
              // TODO: add option to add secondary parameters like, minimum, maximum, default.
              $queryParameters[] = $param['name'];
            }
            elseif ($param['in'] === 'path') {
              $pathParameters[] = $param['name'];
            }
          }
        }

        // Get the correct handler from the number of path parameters.
        if (count($pathParameters) > count(self::HANDLER_FUNCTIONS)) {
          throw new \Exception("Too many path parameters. Please implement a handler that can handle " . count($pathParameters) . " parameters.}");
        }
        $handler = self::HANDLER_FUNCTIONS[count($pathParameters)];

        // Replace the actual parameter names in the path
        // to fit the chosen handler's method signature.
        $substitutedPath = $path;
        $paramMap = [];
        foreach ($pathParameters as $idx => $pathParam) {
          $substitutedPath = str_replace($pathParam, $this->handlers->get($handler)[$idx], $substitutedPath);
          $paramMap[$this->handlers->get($handler)[$idx]] = $pathParam;
        }
        // Add the API prefix to the path.
        $substitutedPath = $prefix . $substitutedPath;

        // Save the relevant elements to the pathMap config.
        $pathConfig = $this->pathMap->get($substitutedPath);
        $pathConfig[$method] = [
          'apiFunction' => $settings['operationId'],
          'handler' => $handler,
          'parameterMap' => $paramMap,
          'pluginId' => $pluginId,
          'queryParameters' => $queryParameters,
        ];
        $this->pathMap->set($substitutedPath, $pathConfig)->save();

        // Permission handling.
        $permissions = [];
        // Add default permission for HTTP method.
        $permissions[] = $this->getDefaultPermission($method);
        // Add additional permissions in case there are any in the path config.
        if (array_key_exists('security', $settings)) {
          $permissions = array_merge($permissions, $this->getPermissionsForPath($settings['security']));
        }
        // Eliminate eventual duplicate permissions.
        $permissions = array_unique($permissions);

        // Create the route with new params.
        $route = $this->buildRoute($substitutedPath, $handler, $method, $permissions);

        // Prefix the supported method name to avoid overwriting
        // in case of multiple methods per path.
        $routeName = $method . "." . self::getRouteNameFromPath($substitutedPath);
        $routeCollection->add($routeName, $route);
      }
    }

    // Add the route for the API documentation.
    $routeCollection->addCollection($this->buildDocumentationRoute($prefix, $pluginId));
    return $routeCollection;
  }

  /**
   * Extracts the required permissions from a paths `security` setting.
   *
   * @param array $security
   *   The security setting for the path from the API's yml congfig.
   *
   * @return string[]
   *   The required permissions.
   */
  private function getPermissionsForPath(array $security): array {
    // Get the required permissions.
    // For now this ignores the used authentication scheme.
    $permissions = [];
    foreach ($security as $authScheme) {
      // Key in the followig loop is the name of the auth scheme.
      foreach ($authScheme as $schemePermissions) {
        // @todo See if we can filter by authentication scheme or if that is impossible.
        $permissions = array_merge($permissions, $schemePermissions);
      }
    }
    return $permissions;
  }

  /**
   * Gets the default permission for a particular HTTP method.
   *
   * @param string $method
   *   The HTTP method.
   *
   * @return string
   *   The name of the permission.
   */
  private function getDefaultPermission(string $method): string {
    // Unsafe methods require write access.
    switch ($method) {
      case 'get':
        return "wisski_api.read";

      case 'post':
      case 'delete':
        return "wisski_api.write";
    }
    throw new \Exception("Method: \"$method\" not supported!");
  }

  /**
   * Build new Route.
   *
   * @param string $path
   *   The path that this route should respond to.
   * @param string $handler
   *   The name of the function that is called on a request.
   * @param string $method
   *   The HTTP method that the route should handle.
   *   Defaults to GET.
   * @param string[] $permissions
   *   A list of permissions that are required to access the route.
   *
   * @return \Symfony\Component\Routing\Route
   *   The Route.
   */
  protected function buildRoute(string $path, string $handler, string $method = 'get', array $permissions = []): Route {
    $parameters['path'] = $path;

    // Set controller and page title.
    $parameters['defaults'] = [
      '_controller' => self::class . '::' . $handler,
    ];

    // Build the permission string.
    // Concatenating the permissions with '+' implies a logical OR.
    // Concatenating the permissions with ',' implies a logical AND.
    $permissionString = implode("+", $permissions);

    // Corresponds to the requirements entry in a routing.yml.
    $parameters['requirements'] = [
      '_user_is_logged_in' => "TRUE",
      '_permission' => $permissionString,
      // If certain custom permissions should be checked use the custom access.
      // '_custom_access' => self::class . '::access',
      // '_access' => "TRUE" // This makes the Route accessible by ANYONE.
    ];

    $parameters['options'] = [
      '_auth' => [
        // Always enable basic auth.
        'basic_auth',
        // Always enable cookie auth.
        'cookie',
      ],
      'no_cache' => 'TRUE',
    ];

    // Leave host empty to set to current host.
    $parameters['host'] = "";
    $parameters['schemes'] = [];
    // Set accepted request methods.
    $parameters['methods'] = [$method];

    return new Route(...$parameters);
  }

  /**
   * Build the route for the documentation.
   *
   * @param string $path
   *   The path under which the documentation should be displayed.
   * @param string $pluginId
   *   The pluginId of the respective API.
   *
   * @return \Symfony\Component\Routing\RouteCollection
   *   A route collection containing the documentation route.
   */
  protected function buildDocumentationRoute(string $path, string $pluginId): RouteCollection {
    $collection = new RouteCollection();
    $routeName = $this->getRouteNameFromPath($path);
    // Add a route for the `renderDocumentation` handler.
    $route = $this->buildRoute($path, 'renderDocumentation', 'get', ['wisski_api.read']);
    // Set the page title.
    $route->setDefault('_title', "Documentation");
    $collection->add($routeName, $route);
    // Save the pluginId for later use.
    // Also set up the read permission.
    $this->pathMap->set($path, ['get' => ['pluginId' => $pluginId]])->save();
    return $collection;
  }

  /**
   * Gets the route name for a specific path.
   *
   * This is the name of the route as you would define them
   * in the routing.yml file.
   *
   * @param string $path
   *   The URL path.
   *
   * @return string
   *   The corresponding route name.
   */
  public static function getRouteNameFromPath(string $path): string {
    // Trim leading slash.
    $path = ltrim($path, '/');
    // Remove everything between brackets.
    $path = preg_replace('/[\[{\(].*?[\]}\)]/', '', $path);
    // Combine multiple slashes.
    $path = preg_replace('/\/{2,}/', '/', $path);
    // Trim trailing slash.
    $path = rtrim($path, '/');
    // Replace slashes with dots.
    return str_replace('/', '.', $path);
  }

  /**
   * Build the API path prefix for this api.
   *
   * @param int $version
   *   The version of the API.
   *
   * @return string
   *   The API prefix.
   */
  public static function buildPrefix(int $version): string {
    return self::API_PREFIX . '/v' . $version;
  }

  /**
   * Custom Controller Callbacks.
   */

  /**
   * Renders the file behind the passed URL as a swagger file.
   *
   * @see https://git.drupalcode.org/project/swagger_ui_formatter/-/blob/8.x-3.x/src/Plugin/Field/FieldFormatter/SwaggerUIFormatterTrait.php#L159
   *
   * @return array
   *   A Drupal render array.
   */
  public function renderDocumentation(Request $request): array {
    $path = $this->routeMatch->getRouteObject()->getPath();
    // Check if the called path exists in the config.
    $pathConfig = $this->pathMap->get($path)['get'];
    if (!$path) {
      return $this->buildErrorResponse("No such API route: $path");
    }
    // Unpack the config for this path.
    $pluginId = $pathConfig['pluginId'];
    $definition = $this->apiManager->getDefinition($pluginId);
    $configName = $definition['config'];

    $swaggerFileUrl = \Drupal::service('file_url_generator')->generate("public://wisski_api/$configName.yaml")->toString();

    $element = [];
    $library_name = 'swagger_ui_formatter.swagger_ui_integration';
    /** @var \Drupal\Core\Asset\LibraryDiscoveryInterface $library_discovery */
    $library_discovery = \Drupal::service('library.discovery');
    /** @var \Drupal\swagger_ui_formatter\Service\SwaggerUiLibraryDiscoveryInterface $swagger_ui_library_discovery */
    $swagger_ui_library_discovery = \Drupal::service('swagger_ui_formatter.swagger_ui_library_discovery');

    // The Swagger UI library integration is only registered if the Swagger UI
    // library directory and version is correct.
    if ($library_discovery->getLibraryByName('swagger_ui_formatter', $library_name) === FALSE) {
      $element = [
        '#theme' => 'status_messages',
        '#message_list' => [
          'error' => [$this->t('The Swagger UI library is missing, incorrectly defined or not supported.')],
        ],
      ];
    }
    else {
      $library_dir = $swagger_ui_library_discovery->libraryDirectory();
      // Set the oauth2-redirect.html file path for OAuth2 authentication.
      $oauth2_redirect_url = $request->getSchemeAndHttpHost() . '/' . $library_dir . '/dist/oauth2-redirect.html';

      $fieldName = 'field_swaggerlink';
      $delta = 0;
      $element[$delta] = [
        '#delta' => $delta,
        '#field_name' => $fieldName,
      ];
      // It's the user's responsibility to set up field settings correctly
      // and use this field formatter with valid Swagger files. Although, it
      // could happen that a URL could not be generated from a field value.
      if ($swaggerFileUrl === NULL) {
        $element[$delta] += [
          '#theme' => 'status_messages',
          '#message_list' => [
            'error' => [$this->t('Could not create URL to file.')],
          ],
        ];
      }
      else {
        $element[$delta] += [
          '#theme' => 'swagger_ui_field_item',
          '#attached' => [
            'library' => [
              'swagger_ui_formatter/' . $library_name,
            ],
            'drupalSettings' => [
              'swaggerUIFormatter' => [
                "{$fieldName}-{$delta}" => [
                  'oauth2RedirectUrl' => $oauth2_redirect_url,
                  'swaggerFile' => $swaggerFileUrl,
                  // 'validator' => 'default',
                  // 'validatorUrl' => '',
                  'docExpansion' => 'list',
                  'showTopBar' => FALSE,
                  'sortTagsByName' => FALSE,
                  'supportedSubmitMethods' => [
                    'get',
                    'put',
                    'post',
                    'delete',
                    'options',
                    'head',
                    'patch',
                  ],
                ],
              ],
            ],
          ],
        ];
      }
    }

    // @todo see if this even does anything.
    $cacheable_metadata = CacheableMetadata::createFromRenderArray($element)->merge(CacheableMetadata::createFromObject($swagger_ui_library_discovery));
    $cacheable_metadata->applyTo($element);
    return $element;
  }

  /**
   * Response Handlers.
   */

  /**
   * Build a HttpResponse indicating that an error has occured.
   *
   * @param string $message
   *   A descirption of the error that occured.
   * @param int $statusCode
   *   The HTTP status code that should be returned.
   *   Defaults to Response::HTTP_BAD_REQUEST.
   * @param bool $cacheing
   *   If this response should be cached.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   *
   * @see \Symfony\Component\Response.php
   */
  protected function buildErrorResponse(string $message, int $statusCode = Response::HTTP_BAD_REQUEST, bool $cacheing = FALSE): Response {
    $response = $this->buildResponse($message, NULL, $cacheing);
    $response->setStatusCode($statusCode);
    return $response;
  }

  /**
   * Build a HttpResponse from the passed data.
   *
   * This function automatically serializes multi-dimensional
   * data structures into a the format that is requested by
   * the request, or JSON if none is provided.
   *
   * If no request was provided also automatically parameters to JSON.
   *
   * @param string|array|int|\Drupal\Core\Entity\EntityInterface $data
   *   The data that came back from the API.
   * @param \Symfony\Component\HttpFoundation\Request|Null $request
   *   The request containing the Content-Type that should be returned.
   * @param bool $cacheing
   *   Indicates of the response to the request should be cached.
   *   If cacheing is enabled it also takes query parameters into account.
   *
   * @return \Symfony\Component\HttpFoundation\Response|\Drupal\Core\Cache\CacheableResponse
   *   The response.
   */
  protected function buildResponse(mixed $data, Request|Null $request = NULL, bool $cacheing = FALSE): Response {
    /** @var \Symfony\Component\Serializer\Serializer **/
    $serializer = $this->serializer;
    $responseData = $data;
    $statusCode = Response::HTTP_OK;
    $headers = [];

    // Normalize entities.
    if ($data instanceof EntityInterface) {
      $responseData = $serializer->normalize($data);
    }
    elseif (is_bool($data)) {
      $responseData = $data ? "TRUE" : "FALSE";
    }

    // In this case we have something serializable (an array).
    if (is_array($responseData)) {
      // Default to application/json if there was no reuqest supplied.
      $contentType = "application/json";
      // If there was a request supplied we have
      // to take into account its Content-Type.
      if ($request) {
        // Default to application/json if no Content-Type header was specified.
        $contentType = strtolower($request->headers->get("Content-Type", "application/json"));
      }
      $format = NULL;
      // Look up the correct Format from the map.
      if (array_key_exists($contentType, self::FORMAT_MAP)) {
        $format = self::FORMAT_MAP[$contentType];
      }
      // Check if the header contained a supported format.
      if ($format) {
        $responseData = $serializer->serialize($responseData, $format);
        $headers = ['Content-Type' => $contentType];
      }
      else {
        $responseData = "This API does not support the requested content-type: {$contentType}";
        $statusCode = Response::HTTP_BAD_REQUEST;
      }
    }
    // Data is not serializable (a plain string).
    else {
      $headers = ['Content-Type' => 'text/plain'];
      $responseData = $data;
    }

    // Build response.
    $response = new Response();
    if ($cacheing) {
      $response = new CacheableResponse();
      $response->getCacheableMetadata()->addCacheContexts(
        ['url.query_args', 'url.path']
      );
    }

    // Set status code.
    $response->setStatusCode($statusCode);
    // Set body.
    $response->setContent($responseData);
    // Attach headers.
    foreach ($headers as $header => $value) {
      $response->headers->set($header, $value);
    }
    return $response;
  }

}
