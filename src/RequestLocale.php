<?php

declare(strict_types=1);


namespace Yiisoft\Yii\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Yiisoft\Http\Header;

final class RequestLocale
{
    private const LOCALE_SEPARATORS = ['-', '_'];

    private ?string $locale = null;

    private bool $isInPath = false;
    private bool $isInQuery = false;
    private bool $isInCookie = false;
    public function __construct(
        private string $defaultLocale,
        private array $supportedLocales,
        private string $queryParameterName,
        private bool $readFromCookie,
        private string $cookieName,
        private bool $detectLocale,
        private ServerRequestInterface $request,
        private LoggerInterface $logger,
    ) {
    }

    public function getLocale(): string
    {
        if ($this->locale !== null) {
            return $this->locale;
        }

        $locale = $this->getLocaleFromPath($this->request->getUri()->getPath());
        if ($locale !== null) {
            $this->locale = $locale;
            $this->isInPath = true;
            return $this->locale;
        }

        /** @psalm-var array<string, string> $queryParameters */
        $queryParameters = $this->request->getQueryParams();
        $locale = $this->getLocaleFromQuery($queryParameters);
        if ($locale !== null) {
            $this->locale = $locale;
            return $this->locale;
        }

        if ($this->readFromCookie) {
            /** @psalm-var array<string, string> $cookieParameters */
            $cookieParameters = $this->request->getCookieParams();
            $locale = $this->getLocaleFromCookies($cookieParameters);
            if ($locale !== null) {
                $this->locale = $locale;
                $this->isInCookie = true;
                return $this->locale;
            }
        }

        if ($this->detectLocale) {
            $locale = $this->detectLocale($this->request);
            if ($locale !== null) {
                $this->locale = $locale;
                return $this->locale;
            }
        }

        $this->locale = $this->defaultLocale;
        return $this->locale;
    }

    public function isInPath(): bool
    {
        return $this->isInPath;
    }

    public function isInQuery(): bool
    {
        return $this->isInQuery;
    }

    public function isInCookie(): bool
    {
        return $this->isInCookie;
    }

    public function isDefault(): bool
    {
        return $this->locale === $this->defaultLocale;
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
        if (!isset($cookieParameters[$this->cookieName])) {
            return null;
        }

        $this->logger->debug(sprintf("Locale '%s' found in cookies.", $cookieParameters[$this->cookieName]));

        return $this->parseLocale($cookieParameters[$this->cookieName]);
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

    public function getSupportedLocale(): ?string
    {
        return $this->supportedLocales[$this->getLocale()] ?? null;
    }
}
