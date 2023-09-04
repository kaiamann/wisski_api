# WissKI API
This module provides access to the main WissKI functionalities through an HTTP REST interface.

## Requirements
This module requires the [Swagger UI Field Formatter](https://www.drupal.org/project/swagger_ui_formatter) to be installed to be able to render the API documentation.
The module then saves the API configuration into `public://wisski_api/CONFIG_NAME.yaml`, (`/sites/default/files/` is usually the default expansion of `public://`) to be able to render the specification on the site.
It also requires the [HTTP Basic Authentication](https://www.drupal.org/docs/8/core/modules/basic_auth) module for enabling HTTP basic auth for the API routes.

## Configuration
The API can be managed in the configuration section which can be found by navigating to `Configuration` &rarr; `WissKI API`.
On this page users can en/disable the availabe API versions.

### Permissions
Access rights can be managed by klicking on `Mange WissKI API Permissions`.
Per default the module comes with two permissions.
These are `Read` and `Write`, which allow users to access `GET` (`Read`) and `POST`,`DELETE` (`Write`) HTTP requests respectively.
These default permssions grant the respective access rights to every activated API.
If an API version defines custom permissions, these are also listed here and can be granted as necessary.

## Custom Extension
If you want to create a custom API you should start by defining a new custom  [Swagger UI](https://swagger.io/tools/swagger-ui/) `.yml` file in `config/install`.
The module reads this config for each API and creates the routes accordingly.

The actual API class is defined in `src/Plugins/wisski_api`. Make sure to add the `@WisskiApi` plugin annotation to the class and set config name to the correct config in `config/install`.
In this plugin annotation you can also define custom permissions to be used in the API like this: 
```json
@WisskiAPI {
    // The Plugin Id. Required by Drupal.
    id = "wisski_api_v0",
    // Version of the API.
    version = 0,
    // Name of the config file in config/install
    config = "wisski_api.v0"
    // Custom permission definition.
    permissions = {
        "wisski_api.v0.read" = {
            // Displayed in the permissions overview.
            "title" = @Translation("Read V0"),
            // Displayed in the permissions overview.
            "description" = @Translation("Read access via WissKI API V0."),
            // Set this to true in case it is relevant for security.
            "restrict access" = false,
        },
    }
}
```
Since the module reads the Swagger UI file to create the routes, some special criteria have to be fulfilled for the module to work:
- The function name that should be called when a route is accessed has to specified in the `operationId` key of each path.
- Parameters for each path also have to be named like they are named in the signature in the correpsonding API function.
- Since each method can potentailly take query and path parameters the location where each parameter can be found shoud be specified in the parameter declarations' `in` key.
- Custom permissions defined in each API's plugin annotation can be set by adding them into the `security` key.
- In case a `POST` route should read data from the HTTP request body, the parameter of the API function has to be named `$data`.

Partial example for a path in the Swagger UI `.yml` config:
```yaml
/example/{exampleParameter}:
    post:
        ...
        operationId: exampleFunction
        parameters:
            - name: exampleParameter
              in: path
              description: An example parameter to be passed to the API function
              required: true
              explode: true
              schema:
                  type: string
        security:
            - ApiKey:
                - example_api.v0.write
```
This would call the corresponding API function:
```php
/**
 * An example function.
 * 
 * @param string $exampleParameter
 *   An example parameter. Name has to match the one specified in 'parameters'.
 * @param $data string|array $data
 *   The request body of the post request.
 */
public function exampleFunction(string $exampleParameter, string|array $data) {
    // do something...
}
```

### Development Workflows
In case you change the Swagger UI configuration you may need to reinstall the module using:
```
drush pm:uninstall wisski_api && drush pm:install wisski_api
```

