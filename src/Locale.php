<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware;

use DateInterval;
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
use Yiisoft\Session\SessionInterface;
use Yiisoft\Strings\WildcardPattern;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\Yii\Middleware\Exception\InvalidLocalesFormatException;

/**
 * Locale middleware supports locale-based routing and configures translator and URL generator.
 * You should place it before `Route` middleware in the middleware list.
 */
final class Locale implements MiddlewareInterface
{
    private const DEFAULT_LOCALE = 'en';
    private const DEFAULT_LOCALE_NAME = '_language';
    private const LOCALE_SEPARATORS = ['-', '_'];

    private bool $saveLocale = true;
    private bool $detectLocale = false;
    private string $defaultLocale = self::DEFAULT_LOCALE;
    private string $queryParameterName = self::DEFAULT_LOCALE_NAME;
    private string $sessionName = self::DEFAULT_LOCALE_NAME;
    /**
     * @psalm-var array<string, string>
     */
    private array $supportedLocales;

    /**
     * @param TranslatorInterface $translator Translator instance to set locale for.
     * @param UrlGeneratorInterface $urlGenerator URL generator instance to set locale for.
     * @param SessionInterface $session Session instance to save locale to.
     * @param LoggerInterface $logger Logger instance to write debug logs to.
     * @param ResponseFactoryInterface $responseFactory Response factory used to create redirect responses.
     * @param array $supportedLocales List of supported locales in key-value format such as `['ru' => 'ru_RU', 'uz' => 'uz_UZ']`.
     * @param string[] $ignoredRequestUrlPatterns {@see WildcardPattern Patterns} for ignoring requests with URLs matching.
     * @param ?DateInterval $cookieDuration Locale cookie lifetime. Effective only when {@see $saveLocale} is set to
     * `true`. `null` disables saving locale to cookies completely.
     * @param bool $secureCookie Whether middleware should flag locale cookie as "secure". Effective only when
     * {@see $saveLocale} is set to `true` and {@see $cookieDuration} is not `null`.
     */
    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private SessionInterface $session,
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
        if (empty($this->supportedLocales)) {
            return $handler->handle($request);
        }

        $uri = $request->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();
        $newPath = null;
        $locale = $this->getLocaleFromPath($path);

        if ($locale !== null) {
            if ($request->getMethod() === Method::GET) {
                $newPath = substr($path, strlen($locale) + 1) ?: '/';
            }
        } else {
            /** @psalm-var array<string, string> $queryParameters */
            $queryParameters = $request->getQueryParams();
            $locale = $this->getLocaleFromQuery($queryParameters);

            if ($locale === null) {
                /** @psalm-var array<string, string> $cookieParameters */
                $cookieParameters = $request->getCookieParams();
                $locale = $this->getLocaleFromCookies($cookieParameters);
            }

            if ($locale === null && $this->detectLocale) {
                $locale = $this->detectLocale($request);
            }

            if ($locale === null || $this->isDefaultLocale($locale) || $this->isRequestIgnored($request)) {
                $this->urlGenerator->setDefaultArgument($this->queryParameterName, null);
                $request = $request->withUri($uri->withPath('/' . $this->defaultLocale . $path));

                return $handler->handle($request);
            }

            if ($request->getMethod() === Method::GET) {
                $newPath = '/' . $locale . $path;
            }
        }

        $response = $handler->handle($request);
        if ($newPath !== null) {
            $location = $this->getBaseUrl() . $newPath . ($query !== '' ? '?' . $query : '');
            $response = $this
                ->responseFactory
                ->createResponse(Status::FOUND)
                ->withHeader(Header::LOCATION, $location);
        }

        /** @var string $locale */
        $this->translator->setLocale($this->supportedLocales[$locale]);
        $this->urlGenerator->setDefaultArgument($this->queryParameterName, $locale);

        if ($this->saveLocale) {
            $response = $this->saveLocale($locale, $response);
        }

        return $response;
    }

    private function getLocaleFromPath(string $path): ?string
    {
        $parts = [];
        foreach ($this->supportedLocales as $code => $locale) {
            $parts[] = $code;
            $parts[] = $locale;
        }

        $pattern = implode('|', $parts);
        if (preg_match("#^/($pattern)\b(/?)#i", $path, $matches)) {
            $matchedLocale = $matches[1];
            if (!isset($this->supportedLocales[$matchedLocale])) {
                $matchedLocale = $this->parseLocale($matchedLocale);
            }
            if (isset($this->supportedLocales[$matchedLocale])) {
                $this->logger->debug(sprintf("Locale '%s' found in URL.", $matchedLocale));
                return $matchedLocale;
            }
        }
        return null;
    }

    /**
     * @psalm-param array<string, string> $queryParameters
     */
    private function getLocaleFromQuery($queryParameters): ?string
    {
        if (!isset($queryParameters[$this->queryParameterName])) {
            return null;
        }

        $this->logger->debug(
            sprintf("Locale '%s' found in query string.", $queryParameters[$this->queryParameterName]),
        );

        return $this->parseLocale($queryParameters[$this->queryParameterName]);
    }

    /**
     * @psalm-param array<string, string> $cookieParameters
     */
    private function getLocaleFromCookies($cookieParameters): ?string
    {
        if (!isset($cookieParameters[$this->sessionName])) {
            return null;
        }

        $this->logger->debug(sprintf("Locale '%s' found in cookies.", $cookieParameters[$this->sessionName]));

        return $this->parseLocale($cookieParameters[$this->sessionName]);
    }

    private function isDefaultLocale(string $locale): bool
    {
        return $locale === $this->defaultLocale || $this->supportedLocales[$locale] === $this->defaultLocale;
    }

    private function detectLocale(ServerRequestInterface $request): ?string
    {
        foreach ($request->getHeader(Header::ACCEPT_LANGUAGE) as $language) {
            if (!isset($this->supportedLocales[$language])) {
                $language = $this->parseLocale($language);
            }
            if (isset($this->supportedLocales[$language])) {
                return $language;
            }
        }
        return null;
    }

    private function saveLocale(string $locale, ResponseInterface $response): ResponseInterface
    {
        $this->logger->debug('Saving found locale to session.');
        $this->session->set($this->sessionName, $locale);

        if ($this->cookieDuration === null) {
            return $response;
        }

        $this->logger->debug('Saving found locale to cookies.');
        $cookie = new Cookie(name: $this->sessionName, value: $locale, secure: $this->secureCookie);
        $cookie = $cookie->withMaxAge($this->cookieDuration);

        return $cookie->addToResponse($response);
    }

    private function parseLocale(string $locale): string
    {
        foreach (self::LOCALE_SEPARATORS as $separator) {
            $separatorPosition = strpos($locale, $separator);
            if ($separatorPosition !== false) {
                return substr($locale, 0, $separatorPosition);
            }
        }

        return $locale;
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
     * @param string $defaultLocale Default locale.
     */
    public function withDefaultLocale(string $defaultLocale): self
    {
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
     * Return new instance with the name of session parameter to store found locale.
     *
     * @param string $sessionName Name of session parameter.
     */
    public function withSessionName(string $sessionName): self
    {
        $new = clone $this;
        $new->sessionName = $sessionName;
        return $new;
    }

    /**
     * Return new instance with enabled or disabled saving of locale. Locale is saved to session and optionally - to
     * cookies (when {@see $cookieDuration} is not `null`).
     *
     * @param bool $enabled Whether middleware should save locale.
     */
    public function withSaveLocale(bool $enabled): self
    {
        $new = clone $this;
        $new->saveLocale = $enabled;
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
     * @param bool $secure Whether middleware should flag locale cookie as "secure."
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
