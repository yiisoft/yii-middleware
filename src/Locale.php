<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware;

use DateInterval;
use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Yiisoft\Cookies\Cookie;
use Yiisoft\Http\Header;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Strings\WildcardPattern;
use Yiisoft\Yii\Middleware\Event\SetLocaleEvent;
use Yiisoft\Yii\Middleware\Exception\InvalidLocalesFormatException;

use function array_key_exists;
use function strlen;

/**
 * Locale middleware supports locale-based routing and configures URL generator. With {@see SetLocaleEvent} it's also
 * possible to configure locale in other services such as translator or session.
 *
 * You should place it before `Route` middleware in the middleware list.
 */
final class Locale implements MiddlewareInterface
{
    private const DEFAULT_LOCALE = 'en';
    private const DEFAULT_LOCALE_NAME = '_language';

    private bool $detectLocale = false;
    private string $defaultLocale = self::DEFAULT_LOCALE;
    private string $queryParameterName = self::DEFAULT_LOCALE_NAME;
    private string $cookieName = self::DEFAULT_LOCALE_NAME;
    /**
     * @psalm-var array<string, string>
     */
    private array $supportedLocales;

    /**
     * @param EventDispatcherInterface $eventDispatcher Event dispatcher instance to dispatch events.
     * @param UrlGeneratorInterface $urlGenerator URL generator instance to set locale for.
     * @param LoggerInterface $logger Logger instance to write debug logs to.
     * @param ResponseFactoryInterface $responseFactory Response factory used to create redirect responses.
     * @param array $supportedLocales List of supported locales in key-value format such as `['ru' => 'ru_RU', 'uz' => 'uz_UZ']`.
     * @param string[] $ignoredRequestUrlPatterns {@see WildcardPattern Patterns} for ignoring requests with URLs matching.
     * @param ?DateInterval $cookieDuration Locale cookie lifetime. `null` disables saving locale to cookies completely.
     * @param bool $secureCookie Whether middleware should flag locale cookie as secure. Effective only when
     * {@see $cookieDuration} isn't `null`.
     */
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private ResponseFactoryInterface $responseFactory,
        array $supportedLocales = [],
        private array $ignoredRequestUrlPatterns = [],
        private bool $secureCookie = false,
        private ?DateInterval $cookieDuration = null,
    ) {
        $this->assertSupportedLocalesFormat($supportedLocales);
        $this->supportedLocales = $supportedLocales;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (empty($this->supportedLocales) || $this->isRequestIgnored($request)) {
            return $handler->handle($request);
        }

        $requestLocale = new RequestLocale(
            $this->defaultLocale,
            $this->supportedLocales,
            $this->queryParameterName,
            $this->cookieDuration !== null,
            $this->cookieName,
            $this->detectLocale,
            $request,
            $this->logger,
        );

        if (!$requestLocale->isDefault()) {
            $locale = $requestLocale->getSupportedLocale();
            if ($locale !== null) {
                $this->eventDispatcher->dispatch(new SetLocaleEvent($locale));
            }
        }

        if ($request->getMethod() !== Method::GET) {
            return $this->handleInCurrentRequest($requestLocale, $request, $handler);
        }

        if ($requestLocale->isInPath()) {
            if (!$requestLocale->isDefault()) {
                return $this->handleInCurrentRequest($requestLocale, $request, $handler);
            }

            return $this->redirectToDefaultUrlWithCookie($requestLocale, $request);
        }

        if ($requestLocale->isDefault()) {
            return $this->handleInCurrentRequest($requestLocale, $request, $handler);
        }

        return $this->redirectToLocaleUrlWithCookie($requestLocale, $request);
    }

    private function redirectToDefaultUrlWithCookie(RequestLocale $requestLocale, ServerRequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        return $this->addLocaleCookieToResponseIfNeeded(
            $requestLocale,
            $this->createRedirectResponse(substr($path, strlen($requestLocale->getLocale()) + 1) ?: '/', $query)
        );
    }

    private function redirectToLocaleUrlWithCookie(RequestLocale $requestLocale, ServerRequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        return $this->addLocaleCookieToResponseIfNeeded(
            $requestLocale,
            $this->createRedirectResponse('/' . $requestLocale->getLocale() . $path, $query)
        );
    }

    private function handleInCurrentRequest(RequestLocale $requestLocale, ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->urlGenerator->setDefaultArgument($this->queryParameterName, $requestLocale->getLocale());
        $response = $handler->handle($request);
        $this->addLocaleCookieToResponseIfNeeded($requestLocale, $response);
        return $response;
    }

    private function createRedirectResponse(string $path, string $query): ResponseInterface
    {
        return $this
            ->responseFactory
            ->createResponse(Status::FOUND)
            ->withHeader(
                Header::LOCATION,
                $this->getBaseUrl() . $path . ($query !== '' ? '?' . $query : '')
            );
    }

    private function addLocaleCookieToResponseIfNeeded(RequestLocale $requestLocale, ResponseInterface $response): ResponseInterface
    {
        if ($this->cookieDuration === null || $requestLocale->isInCookie()) {
            return $response;
        }

        $this->logger->debug('Saving found locale to cookies.');
        $cookie = new Cookie(name: $this->cookieName, value: $requestLocale->getLocale(), secure: $this->secureCookie);
        $cookie = $cookie->withMaxAge($this->cookieDuration);

        return $cookie->addToResponse($response);
    }

    private function isRequestIgnored(ServerRequestInterface $request): bool
    {
        foreach ($this->ignoredRequestUrlPatterns as $ignoredRequest) {
            if ((new WildcardPattern($ignoredRequest))->match($request->getUri()->getPath())) {
                return true;
            }
        }
        return false;
    }

    /**
     * @psalm-assert array<string, string> $supportedLocales
     *
     * @throws InvalidLocalesFormatException
     */
    private function assertSupportedLocalesFormat(array $supportedLocales): void
    {
        foreach ($supportedLocales as $code => $locale) {
            if (!is_string($code) || !is_string($locale)) {
                throw new InvalidLocalesFormatException();
            }
        }
    }

    private function getBaseUrl(): string
    {
        return rtrim($this->urlGenerator->getUriPrefix(), '/');
    }

    /**
     * Return new instance with supported locales specified.
     *
     * @param array $locales List of supported locales in key-value format such as `['ru' => 'ru_RU', 'uz' => 'uz_UZ']`.
     *
     * @throws InvalidLocalesFormatException
     */
    public function withSupportedLocales(array $locales): self
    {
        $this->assertSupportedLocalesFormat($locales);
        $new = clone $this;
        $new->supportedLocales = $locales;
        return $new;
    }

    /**
     * Return new instance with default locale specified.
     *
     * @param string $defaultLocale Default locale. It must be present as a key in {@see $supportedLocales}.
     */
    public function withDefaultLocale(string $defaultLocale): self
    {
        if (!array_key_exists($defaultLocale, $this->supportedLocales)) {
            throw new InvalidArgumentException('Default locale allows only keys from supported locales.');
        }

        $new = clone $this;
        $new->defaultLocale = $defaultLocale;
        return $new;
    }

    /**
     * Return new instance with the name of the query string parameter to look for locale.
     *
     * @param string $queryParameterName Name of the query string parameter.
     */
    public function withQueryParameterName(string $queryParameterName): self
    {
        $new = clone $this;
        $new->queryParameterName = $queryParameterName;
        return $new;
    }

    /**
     * Return new instance with the name of cookie parameter to store found locale. Effective only when
     * {@see $cookieDuration} isn't `null`.
     *
     * @param string $cookieName Name of cookie parameter.
     */
    public function withCookieName(string $cookieName): self
    {
        $new = clone $this;
        $new->cookieName = $cookieName;
        return $new;
    }

    /**
     * Return new instance with enabled or disabled detection of locale based on `Accept-Language` header.
     *
     * @param bool $enabled Whether middleware should detect locale.
     */
    public function withDetectLocale(bool $enabled): self
    {
        $new = clone $this;
        $new->detectLocale = $enabled;
        return $new;
    }

    /**
     * Return new instance with {@see WildcardPattern patterns} for ignoring requests with URLs matching.
     *
     * @param string[] $patterns Patterns.
     */
    public function withIgnoredRequestUrlPatterns(array $patterns): self
    {
        $new = clone $this;
        $new->ignoredRequestUrlPatterns = $patterns;
        return $new;
    }

    /**
     * Return new instance with enabled or disabled secure cookies.
     *
     * @param bool $secure Whether middleware should flag locale cookie as secure.
     */
    public function withSecureCookie(bool $secure): self
    {
        $new = clone $this;
        $new->secureCookie = $secure;
        return $new;
    }

    /**
     * Return new instance with changed cookie duration.
     *
     * @param ?DateInterval $cookieDuration Locale cookie lifetime. When set to `null`, saving locale to cookies is
     * disabled completely.
     */
    public function withCookieDuration(?DateInterval $cookieDuration): self
    {
        $new = clone $this;
        $new->cookieDuration = $cookieDuration;
        return $new;
    }
}
