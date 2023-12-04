# TYPO3 App Routes

## Route any URL to your application.

You use this package if you want to route certain URLs directly to your controllers, completely ignoring the TYPO3 page routing.<br>
This is especially useful to create REST APIs.

### Installation

```bash
composer req sinso/app-routes
```

### Configuration

This package will look for `Configuration/AppRoutes.yaml` files in any loaded extension. Creating this file is all you need to get started:

```yaml
myApp:
  prefix: /myApi/v2
  routes:
    - name: orders
      path: /orders
      defaults:
        handler: MyVendor\MyExtension\Api\OrdersEndpoint
    - name: order
      path: /order/{orderUid}
      defaults:
        handler: MyVendor\MyExtension\Api\OrderEndpoint
```

The class you provide as `defaults.handler` has to implement `\Psr\Http\Server\RequestHandlerInterface`.
The routing parameters will be available in `$request->getQueryParams()`.

### Options

Under the hood [symfony/routing](https://github.com/symfony/routing) is used.

Everything that is available as YAML configuration option in `symfony/routing` should work with this package out of the box.

This package offers these additional options:

* `defaults.cache: true` - If true, then responses are cached (see more details below). (default: `false`)
* `defaults.requiresTsfe: true` - If true, then `$GLOBALS['TSFE']` will be initialized before your handler is called (default: `false`).

### Generate Route URLs

To generate URLs you can use the `Sinso\AppRoutes\Service\Router`:

```php
$router = GeneralUtility::makeInstance(\Sinso\AppRoutes\Service\Router::class);
$url = $router->getUrlGenerator()->generate('myApp.order', ['orderUid' => 42]);
// https://www.example.com/myApi/v2/order/42
```

If you need to generate a URL in a Fluid template, there's also a ViewHelper for that:

```html
<html
	xmlns:ar="http://typo3.org/ns/Sinso/AppRoutes/ViewHelpers"
	data-namespace-typo3-fluid="true"
>

{ar:route(routeName: 'myApp.order', parameters: {orderUid: '42'})}

</html>
```

### Configuration Module

In the configuration module there's an entry "App Routes", that shows all configured routes.
***Requires TYPO3 v11***

### Caching

* Caching can be enabled per route via configuration `defaults.cache: true`.
* The TYPO3 `pages` cache is used to cache API responses.
* Your request handler will not be called at all if the request can be served from cache.
* Only responses for `GET` and `HEAD` requests can be cached.
* The cache key is built from all query parameters that were matched by your route.
* If `$GLOBALS['TSFE']` was involved in handling the request and cache tags were added to it via `$tsfe->addCacheTags($tags)`, those are applied to the cache entry.
* If you have `$GLOBALS['TYPO3_CONF_VARS']['FE']['debug']` enabled, the HTTP response contains headers describing its cache status.
* Responses with `Cache-Control: no-cache` or `Cache-Control: no-store` are not cached.
* Responses with `Cache-Control: max-age=300` overwrite the default TTL of the `pages` cache.
