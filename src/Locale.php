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
use Yiisoft\Aliases\Aliases;
use Yiisoft\Cookies\Cookie;
use Yiisoft\Http\Header;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Strings\WildcardPattern;
use Yiisoft\Translator\TranslatorInterface;

final class Locale implements MiddlewareInterface
{
    private const DEFAULT_LOCALE = 'en';
    private const DEFAULT_LOCALE_NAME = '_language';

    private bool $enableSaveLocale = true;
    private bool $enableDetectLocale = false;
    private string $defaultLocale = self::DEFAULT_LOCALE;
    private string $queryParameterName = self::DEFAULT_LOCALE_NAME;
    private string $sessionName = self::DEFAULT_LOCALE_NAME;
    private ?DateInterval $cookieDuration;

    /**
     * @param array<array-key, string> $locales
     * @param string[] $ignoredRequests
     */
    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private SessionInterface $session,
        private Aliases $aliases,
        private LoggerInterface $logger,
        private ResponseFactoryInterface $responseFactory,
        private array $locales = [],
        private array $ignoredRequests = [],
        private bool $cookieSecure = false,
        private string $baseUrlAlias = '@baseUrl',
    ) {
        $this->cookieDuration = new DateInterval('P30D');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->locales === []) {
            return $handler->handle($request);
        }

        $uri = $request->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        [$locale, $country] = $this->getLocaleFromPath($path);

        if ($locale !== null) {
            $this->translator->setLocale($locale);
            $this->urlGenerator->setDefaultArgument($this->queryParameterName, $locale);

            $response = $handler->handle($request);
            $newPath = null;
            if ($this->isDefaultLocale($locale, $country) && $request->getMethod() === Method::GET) {
                $length = strlen($locale);
                $newPath = substr($path, $length + 1);
            }
            return $this->applyLocaleFromPath($locale, $response, $query, $newPath);
        }
        if ($this->enableSaveLocale) {
            [$locale, $country] = $this->getLocaleFromRequest($request);
        }
        if ($locale === null && $this->enableDetectLocale) {
            [$locale, $country] = $this->detectLocale($request);
        }
        if ($locale === null || $this->isDefaultLocale($locale, $country) || $this->isRequestIgnored($request)) {
            $this->urlGenerator->setDefaultArgument($this->queryParameterName, null);
            $request = $request->withUri($uri->withPath('/' . $this->defaultLocale . $path));
            return $handler->handle($request);
        }

        $this->translator->setLocale($locale);
        $this->urlGenerator->setDefaultArgument($this->queryParameterName, $locale);

        if ($request->getMethod() === Method::GET) {
            $location = rtrim($this->aliases->get($this->baseUrlAlias), '/') . '/'
                . $locale . $path . ($query !== '' ? '?' . $query : '');
            return $this->responseFactory
                ->createResponse(Status::FOUND)
                ->withHeader(Header::LOCATION, $location);
        }


        return $handler->handle($request);
    }

    private function applyLocaleFromPath(
        string $locale,
        ResponseInterface $response,
        string $query,
        ?string $newPath = null,
    ): ResponseInterface {
        if ($newPath === '') {
            $newPath = '/';
        }

        if ($newPath !== null) {
            $location = rtrim($this->aliases->get($this->baseUrlAlias), '/')
                . $newPath . ($query !== '' ? '?' . $query : '');
            $response = $this->responseFactory
                ->createResponse(Status::FOUND)
                ->withHeader(Header::LOCATION, $location);
        }
        if ($this->enableSaveLocale) {
            $response = $this->saveLocale($locale, $response);
        }
        return $response;
    }

    /**
     * @return array{0:string|null, 1:string|null}
     */
    private function getLocaleFromPath(string $path): array
    {
        $parts = [];
        foreach ($this->locales as $code => $locale) {
            $lang = is_string($code) ? $code : $locale;
            $parts[] = $lang;
        }

        $pattern = implode('|', $parts);
        if (preg_match("#^/($pattern)\b(/?)#i", $path, $matches)) {
            $matchedLocale = $matches[1];
            [$locale, $country] = $this->parseLocale($matchedLocale);
            if (isset($this->locales[$locale]) || in_array($matchedLocale, $this->locales, true)) {
                $this->logger->debug(sprintf("Locale '%s' found in URL", $locale));
                return [$locale, $country];
            }
        }
        return [null, null];
    }

    /**
     * @return array{0:string|null, 1:string|null}
     */
    private function getLocaleFromRequest(ServerRequestInterface $request): array
    {
        /** @var array<string, string> $cookies */
        $cookies = $request->getCookieParams();
        if (isset($cookies[$this->sessionName])) {
            $this->logger->debug(sprintf("Locale '%s' found in cookies", $cookies[$this->sessionName]));
            return $this->parseLocale($cookies[$this->sessionName]);
        }
        /** @var array<string, string> $queryParameters */
        $queryParameters = $request->getQueryParams();
        if (isset($queryParameters[$this->queryParameterName])) {
            $this->logger->debug(
                sprintf("Locale '%s' found in query string", $queryParameters[$this->queryParameterName])
            );
            return $this->parseLocale($queryParameters[$this->queryParameterName]);
        }
        return [null, null];
    }

    private function isDefaultLocale(string $locale, ?string $country): bool
    {
        return $locale === $this->defaultLocale || ($country !== null && $this->defaultLocale === "$locale-$country");
    }

    /**
     * @return array{0:string|null, 1:string|null}
     */
    private function detectLocale(ServerRequestInterface $request): array
    {
        foreach ($request->getHeader(Header::ACCEPT_LANGUAGE) as $language) {
            return $this->parseLocale($language);
        }
        return [null, null];
    }

    private function saveLocale(string $locale, ResponseInterface $response): ResponseInterface
    {
        $this->logger->debug('Saving found locale to cookies');
        $this->session->set($this->sessionName, $locale);
        $cookie = new Cookie(name: $this->sessionName, value: $locale, secure: $this->cookieSecure);
        if ($this->cookieDuration !== null) {
            $cookie = $cookie->withMaxAge($this->cookieDuration);
        }
        return $cookie->addToResponse($response);
    }

    /**
     * @return array{0:string, 1:string|null}
     */
    private function parseLocale(string $locale): array
    {
        $localeParts = preg_split('/[-_]/', $locale, 2, PREG_SPLIT_NO_EMPTY);

        if (isset($this->locales[$locale]) && (empty($localeParts) || count($localeParts) !== 2)) {
            $localeParts = preg_split('/[-_]/', $this->locales[$locale], 2, PREG_SPLIT_NO_EMPTY);
        }

        if (!empty($localeParts) && count($localeParts) === 2) {
            return $localeParts;
        }

        return [$locale, null];
    }

    private function isRequestIgnored(ServerRequestInterface $request): bool
    {
        foreach ($this->ignoredRequests as $ignoredRequest) {
            if ((new WildcardPattern($ignoredRequest))->match($request->getUri()->getPath())) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<array-key, string> $locales
     *
     * @return $this
     */
    public function withLocales(array $locales): self
    {
        $new = clone $this;
        $new->locales = $locales;
        return $new;
    }

    public function withDefaultLocale(string $defaultLocale): self
    {
        $new = clone $this;
        $new->defaultLocale = $defaultLocale;
        return $new;
    }

    public function withQueryParameterName(string $queryParameterName): self
    {
        $new = clone $this;
        $new->queryParameterName = $queryParameterName;
        return $new;
    }

    public function withSessionName(string $sessionName): self
    {
        $new = clone $this;
        $new->sessionName = $sessionName;
        return $new;
    }

    public function withEnableSaveLocale(bool $enableSaveLocale): self
    {
        $new = clone $this;
        $new->enableSaveLocale = $enableSaveLocale;
        return $new;
    }

    public function withEnableDetectLocale(bool $enableDetectLocale): self
    {
        $new = clone $this;
        $new->enableDetectLocale = $enableDetectLocale;
        return $new;
    }

    /**
     * @param string[] $ignoredRequests
     *
     * @return $this
     */
    public function withIgnoredRequests(array $ignoredRequests): self
    {
        $new = clone $this;
        $new->ignoredRequests = $ignoredRequests;
        return $new;
    }

    public function withCookieSecure(bool $secure): self
    {
        $new = clone $this;
        $new->cookieSecure = $secure;
        return $new;
    }

    public function withBaseUrlAlias(string $baseUrlAlias): self
    {
        $new = clone $this;
        $new->baseUrlAlias = $baseUrlAlias;
        return $new;
    }
}
