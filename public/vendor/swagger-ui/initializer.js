window.onload = function () {
  window.ui = SwaggerUIBundle({
    url: '/api/documentations/openapi.json', dom_id: '#swagger-ui',
    deepLinking: true, filter: true, displayRequestDuration: true,
    docExpansion: 'none', persistAuthorization: false, validatorUrl: null,
    presets: [SwaggerUIBundle.presets.apis],
    requestInterceptor: function (request) {
      request.headers.Accept = 'application/json';
      return request;
    }
  });
};
