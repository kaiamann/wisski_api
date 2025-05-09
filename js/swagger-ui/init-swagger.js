(function ($, Drupal, drupalSettings) {
    Drupal.behaviors.swaggerUI = {
      attach: function (context, settings) {
        const ui = SwaggerUIBundle({
          url: drupalSettings.swaggerUI.specUrl,
          dom_id: '#swagger-ui',
          deepLinking: true,
          docExpansion: "list",
          validator: "default",
          presets: [
            SwaggerUIBundle.presets.apis,
            SwaggerUIStandalonePreset.slice(1)
          ],
          plugins: [
            SwaggerUIBundle.plugins.DownloadUrl
          ],
          layout: "StandaloneLayout",
        });
      }
    };
  })(jQuery, Drupal, drupalSettings);
  console.log("outside?");