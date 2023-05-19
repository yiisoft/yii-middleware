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
use Yiisoft\Http\Header;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Strings\WildcardPattern;
use Yiisoft\Yii\Middleware\Event\LocaleEvent;
use Yiisoft\Yii\Middleware\Exception\InvalidLocalesFormatException;
use Yiisoft\Yii\Middleware\Storage\LocaleStorageInterface;
use Yiisoft\Yii\Middleware\Storage\LocaleStorageWithResponseInterface;

use function array_key_exists;

/**
 * Locale middleware supports locale-based routing and configures URL generator. With event dispatcher it's also
 * possible to configure translator's locale.
 *
 * You should place it before `Route` middleware in the middleware list.
 */
final class Locale implements MiddlewareInterface
{
    private const DEFAULT_LOCALE = 'en';
    private const DEFAULT_LOCALE_NAME = '_language';
    private const LOCALE_SEPARATORS = ['-', '_'];

    private bool $detectLocale = false;
    private string $defaultLocale = self::DEFAULT_LOCALE;
    private string $queryParameterName = self::DEFAULT_LOCALE_NAME;
    /**
     * @psalm-var array<string, string>
     */
    private array $supportedLocales;
    /**
     * @var LocaleStorageInterface[]
     */
    private array $storages;

    /**
     * @param EventDispatcherInterface $eventDispatcher Event dispatcher instance to dispatch events.
     * @param UrlGeneratorInterface $urlGenerator URL generator instance to set locale for.
     * @param LoggerInterface $logger Logger instance to write debug logs to.
     * @param ResponseFactoryInterface $responseFactory Response factory used to create redirect responses.
     * @param array $supportedLocales List of supported locales in key-value format such as `['ru' => 'ru_RU', 'uz' => 'uz_UZ']`.
     * @param string[] $ignoredRequestUrlPatterns {@see WildcardPattern Patterns} for ignoring requests with URLs matching.
     */
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private ResponseFactoryInterface $responseFactory,
        array $supportedLocales = [],
        private array $ignoredRequestUrlPatterns = [],
    ) {
        $this->assertSupportedLocalesFormat($supportedLocales);
        $this->supportedLocales = $supportedLocales;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (empty($this->supportedLocales)) {
            return $handler->handle($request);
        }

        foreach ($this->storages as $storage) {
            if (!$storage instanceof LocaleStorageInterface) {
                $interfaceName = LocaleStorageInterface::class;

                throw new InvalidArgumentException("Locale storage must implement \"$interfaceName\".");
            }

            if ($storage instanceof LocaleStorageWithResponseInterface) {
                $storage->withRequest($request);
            }
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
                foreach ($this->storages as $storage) {
                    $locale = $storage->get();
                    if ($locale !== null) {
                        $this->logger->debug("Locale '$locale' found in cookies.");

                        break;
                    }
                }
            }

            if ($locale === null && $this->detectLocale) {
                $locale = $this->detectLocale($request);
            }

            if ($locale === null || $locale === $this->defaultLocale || $this->isRequestIgnored($request)) {
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
        $this->eventDispatcher->dispatch(new LocaleEvent($this->supportedLocales[$locale]));
        $this->urlGenerator->setDefaultArgument($this->queryParameterName, $locale);

        foreach ($this->storages as $storage) {
            if ($storage instanceof LocaleStorageWithResponseInterface) {
                $storage->withResponse($response);
            }

            $storageName = $storage->getName();
            $this->logger->debug("Saving found locale to $storageName.");
            $storage->set($locale);

            if ($storage instanceof LocaleStorageWithResponseInterface) {
                $response = $storage->getResponse();
            }
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

    public function withStorages(array $storages): self
    {
        $new = clone $this;
        $new->storages = $storages;
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
}
