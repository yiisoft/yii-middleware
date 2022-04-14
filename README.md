<p align="center">
    <a href="https://github.com/yiisoft" target="_blank">
        <img src="https://yiisoft.github.io/docs/images/yii_logo.svg" height="100px">
    </a>
    <h1 align="center">Yii Middleware</h1>
    <br>
</p>

[![Latest Stable Version](https://poser.pugx.org/yiisoft/yii-middleware/v/stable.png)](https://packagist.org/packages/yiisoft/yii-middleware)
[![Total Downloads](https://poser.pugx.org/yiisoft/yii-middleware/downloads.png)](https://packagist.org/packages/yiisoft/yii-middleware)
[![Build status](https://github.com/yiisoft/yii-middleware/workflows/build/badge.svg)](https://github.com/yiisoft/yii-middleware/actions?query=workflow%3Abuild)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/yiisoft/yii-middleware/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/yiisoft/yii-middleware/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/yiisoft/yii-middleware/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/yiisoft/yii-middleware/?branch=master)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fyiisoft%2Fyii-middleware%2Fmaster)](https://dashboard.stryker-mutator.io/reports/github.com/yiisoft/yii-middleware/master)
[![static analysis](https://github.com/yiisoft/yii-middleware/workflows/static%20analysis/badge.svg)](https://github.com/yiisoft/yii-middleware/actions?query=workflow%3A%22static+analysis%22)
[![type-coverage](https://shepherd.dev/github/yiisoft/yii-middleware/coverage.svg)](https://shepherd.dev/github/yiisoft/yii-middleware)

The package provides middleware classes that implement [PSR 15](https://github.com/php-fig/http-server-middleware).
For more information on how to use middleware in the [Yii Framework](http://www.yiiframework.com/),
see the [Yii middleware guide](https://github.com/yiisoft/docs/blob/master/guide/en/structure/middleware.md).

## Requirements

- PHP 8.0 or higher.

## Installation

The package could be installed with composer:

```shell
composer require yiisoft/yii-middleware --prefer-dist
```

## General usage

All classes are separate implementations of [PSR 15](https://github.com/php-fig/http-server-middleware)
middleware and do not interact with each other in any way.

### `BasicNetworkResolver`

Updates an instance of server request with protocol from special headers.

It can be used in if your server is behind a trusted load balancer or a proxy that is setting a special header.

```php
use Yiisoft\Yii\Middleware\BasicNetworkResolver;

/**
 * @var Psr\Http\Message\ServerRequestInterface $request
 * @var Psr\Http\Server\RequestHandlerInterface $handler
 */

$middleware = new BasicNetworkResolver();

$middleware = $middleware->withAddedProtocolHeader('x-forwarded-proto', [
    'http' => ['http'],
    'https' => ['https', 'on'],
]);
// Disable previous settings:
$middleware = $middleware->withoutProtocolHeader('x-forwarded-proto');

$response = $middleware->process($request, $handler);
```

### `ForceSecureConnection`

Redirects insecure requests from HTTP to HTTPS, and adds headers necessary to enhance security policy.

```php
use Yiisoft\Yii\Middleware\ForceSecureConnection;

/**
 * @var Psr\Http\Message\ResponseFactoryInterface $responseFactory
 * @var Psr\Http\Message\ServerRequestInterface $request
 * @var Psr\Http\Server\RequestHandlerInterface $handler
 */

$middleware = new ForceSecureConnection($responseFactory);

// Enables redirection from HTTP to HTTPS:
$middleware = $middleware->withRedirection(301);
// Disables redirection from HTTP to HTTPS:
$middleware = $middleware->withoutRedirection();

$response = $middleware->process($request, $handler);
```

The `Content-Security-Policy` (CSP) header can force the browser to load page resources only through
a secure connection, even if links in the page layout are specified with an unprotected protocol.

```php
$middleware = $middleware->withCSP('upgrade-insecure-requests; default-src https:');
// Or without the `Content-Security-Policy` header in response:
$middleware = $middleware->withoutCSP();
```

HTTP `Strict-Transport-Security` (HSTS) header is added to each response
and tells the browser that your site works on HTTPS only.

```php
$maxAge = 3600; // Default is 31_536_000 (12 months).
$subDomains = false; // Whether to add the `includeSubDomains` option to the header value.

$middleware = $middleware->withHSTS($maxAge, $subDomains);
// Or without the `Strict-Transport-Security` header in response:
$middleware = $middleware->withoutHSTS();
```

### `HttpCache`

Implements client-side caching by utilizing the `Last-Modified` and `ETag` HTTP headers.

```php
use Yiisoft\Yii\Middleware\HttpCache;

/**
 * @var Psr\Http\Message\ServerRequestInterface $request
 * @var Psr\Http\Server\RequestHandlerInterface $handler
 */

$middleware = new HttpCache();

// Specify callable that generates the last modified:
$middleware = $middleware->withLastModified(function (ServerRequestInterface $request, mixed $params): int {
    $defaultLastModified = 3600;
    // Some actions.
    return $defaultLastModified;
});
// Specify callable that generates the ETag seed string:
$middleware = $middleware->withEtagSeed(function (ServerRequestInterface $request, mixed $params): string {
    $defaultEtagSeed = '33a64df551425fcc55e4d42a148795d9f25f89d4';
    // Some actions.
    return $defaultEtagSeed;
});

$response = $middleware->process($request, $handler);
```

Additionally, you can specify the following options:

```php
// Specify additional parameters for ETag seed string generation:
$middleware = $middleware->withParams(['parameter' => 'value']);

// Specify value of the `Cache-Control` HTTP header:
$middleware = $middleware->withCacheControlHeader('public, max-age=31536000');
// Default is `public, max-age=3600`. If null, the header will not be sent.

// Enable weak ETags generation (disabled by default):
$middleware = $middleware->withWeakTag();
// Weak ETags should be used if the content should be considered semantically equivalent, but not byte-equal.
```

### `IpFilter`

Validates the IP received in the request.

```php
use Yiisoft\Yii\Middleware\IpFilter;

/**
 * @var Psr\Http\Message\ResponseFactoryInterface $responseFactory
 * @var Psr\Http\Message\ServerRequestInterface $request
 * @var Psr\Http\Server\RequestHandlerInterface $handler
 * @var Yiisoft\Validator\Rule\ValidatorInterface $validator
 */

// Name of the client IP address request attribute:
$clientIpAttribute = 'client-ip';
// If `null`, then `REMOTE_ADDR` value of the server parameters is processed. If the value is not `null`,
// then the attribute specified must have a value, otherwise the request will be closed with forbidden.

$middleware = new IpFilter($validator, $responseFactory, $clientIpAttribute);

// Change client IP validator:
$middleware = $middleware->withValidator($validator);

$response = $middleware->process($request, $handler);
```

### `Redirect`

Generates and adds a `Location` header to the response.

```php
use Yiisoft\Yii\Middleware\Redirect;

/**
 * @var Psr\Http\Message\ResponseFactoryInterface $responseFactory
 * @var Psr\Http\Message\ServerRequestInterface $request
 * @var Psr\Http\Server\RequestHandlerInterface $handler
 * @var Yiisoft\Router\UrlGeneratorInterface $urlGenerator
 */

$middleware = new Redirect($ipValidator, $urlGenerator);

// Specify URL for redirection:
$middleware = $middleware->toUrl('/login');
// Or specify route data for redirection:
$middleware = $middleware->toRoute('auth/login', ['parameter' => 'value']);
// If a redirect URL has been set a `toUrl()` method, the route data will be ignored, since the URL is a priority.

$response = $middleware->process($request, $handler);
```

You can also set the status of the response code for redirection.

```php
// For permanent redirection (301):
$middleware = $middleware->permanent();

// For temporary redirection (302):
$middleware = $middleware->permanent();

// Or specify the status code yourself:
$middleware = $middleware->withStatus(303);
```

### `SubFolder`

Supports routing when `webroot` is not the same folder as public.

```php
use Yiisoft\Yii\Middleware\SubFolder;

/**
 * @var Psr\Http\Message\ServerRequestInterface $request
 * @var Psr\Http\Server\RequestHandlerInterface $handler
 * @var Yiisoft\Aliases\Aliases $aliases
 * @var Yiisoft\Router\UrlGeneratorInterface $urlGenerator
 */
 
// URI prefix the specified immediately after the domain part (default is `null`):
$prefix = '/blog';
// The prefix value usually begins with a slash and must not end with a slash.

$middleware = new SubFolder($urlGenerator, $aliases, $prefix);

$response = $middleware->process($request, $handler);
```

### `TagRequest`

Tags request with a random value that could be later used for identifying it.

```php
use Yiisoft\Yii\Middleware\TagRequest;

/**
 * @var Psr\Http\Message\ServerRequestInterface $request
 * @var Psr\Http\Server\RequestHandlerInterface $handler
 */

$middleware = new TagRequest();
// In the process, a request attribute with the name `requestTag`
// and the generated value by the function `uniqid()` will be added.
$response = $middleware->process($request, $handler);
```

### `TrustedHostsNetworkResolver`

Adds and resolvers trusted hosts and related headers.

The header lists are evaluated in the order they were specified. If you specify multiple headers by type
(e.g. IP headers), you must ensure that the irrelevant header is removed e.g. web server application,
otherwise spoof clients can be use this vulnerability.

```php
/**
 * @var Psr\Http\Message\ServerRequestInterface $request
 * @var Psr\Http\Server\RequestHandlerInterface $handler
 * @var Yiisoft\Yii\Middleware\TrustedHostsNetworkResolver $middleware
 */

$middleware = $middleware->withAddedTrustedHosts(
    // List of secure hosts including `$_SERVER['REMOTE_ADDR']`, can specify IPv4, IPv6, domains and aliases.
    ['1.1.1.1', '2.2.2.1/3', '2001::/32', 'localhost'],
    // Headers containing IP lists. Headers containing multiple sub-elements (e.g. RFC 7239) must for
    // other relevant types (e.g. host headers), otherwise they will only be used as an IP list.
    ['x-forwarded-for', [TrustedHostsNetworkResolver::IP_HEADER_TYPE_RFC7239, 'forwarded']],
    // Protocol headers with accepted protocols and values. Matching of values is case-insensitive.
    ['x-forwarded-proto' => ['https' => 'on']],
    // List of headers containing HTTP host.
    ['forwarded', 'x-forwarded-for'],
    // List of headers containing HTTP URL.
    ['x-rewrite-url'],
    // List of headers containing port number.
    ['x-rewrite-port'],
    // List of trusted headers. Removed from the request, if in checking process are classified as untrusted by hosts.
    ['x-forwarded-for', 'forwarded'],
);
// Disable previous settings:
$middleware = $middleware->withoutTrustedHosts();

$response = $middleware->process($request, $handler);
```

Additionally, you can specify the following options:

```php
/**
 * Specify request attribute name to which trusted path data is added.
 * 
 * @var Yiisoft\Yii\Middleware\TrustedHostsNetworkResolver $middleware
 * @var string|null $attribute
 */
$middleware = $middleware->withAttributeIps($attribute);

/**
 * Specify client IP validator.
 * 
 * @var Yiisoft\Validator\ValidatorInterface $validator
 */
$middleware = $middleware->withValidator($validator);
```

## Testing

### Unit testing

The package is tested with [PHPUnit](https://phpunit.de/). To run tests:

```shell
./vendor/bin/phpunit
```

### Mutation testing

The package tests are checked with [Infection](https://infection.github.io/) mutation framework with
[Infection Static Analysis Plugin](https://github.com/Roave/infection-static-analysis-plugin). To run it:

```shell
./vendor/bin/roave-infection-static-analysis-plugin
```

### Static analysis

The code is statically analyzed with [Psalm](https://psalm.dev/). To run static analysis:

```shell
./vendor/bin/psalm
```

## License

The Yii Middleware is free software. It is released under the terms of the BSD License.
Please see [`LICENSE`](./LICENSE.md) for more information.

Maintained by [Yii Software](https://www.yiiframework.com/).

## Support the project

[![Open Collective](https://img.shields.io/badge/Open%20Collective-sponsor-7eadf1?logo=open%20collective&logoColor=7eadf1&labelColor=555555)](https://opencollective.com/yiisoft)

## Follow updates

[![Official website](https://img.shields.io/badge/Powered_by-Yii_Framework-green.svg?style=flat)](https://www.yiiframework.com/)
[![Twitter](https://img.shields.io/badge/twitter-follow-1DA1F2?logo=twitter&logoColor=1DA1F2&labelColor=555555?style=flat)](https://twitter.com/yiiframework)
[![Telegram](https://img.shields.io/badge/telegram-join-1DA1F2?style=flat&logo=telegram)](https://t.me/yii3en)
[![Facebook](https://img.shields.io/badge/facebook-join-1DA1F2?style=flat&logo=facebook&logoColor=ffffff)](https://www.facebook.com/groups/yiitalk)
[![Slack](https://img.shields.io/badge/slack-join-1DA1F2?style=flat&logo=slack)](https://yiiframework.com/go/slack)
