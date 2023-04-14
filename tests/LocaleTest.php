<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use HttpSoft\Message\Response;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Header;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Test\Support\Log\SimpleLogger;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\Yii\Middleware\Exception\InvalidLocalesFormatException;
use Yiisoft\Yii\Middleware\Locale;

final class LocaleTest extends TestCase
{
    private ?string $locale;
    private string $prefix = '';
    private array $session = [];
    private ?ServerRequestInterface $lastRequest;

    public function setUp(): void
    {
        $this->locale = null;
        $this->lastRequest = null;
    }

    public function testImmutability(): void
    {
        $localeMiddleware = $this->createMiddleware();

        $this->assertNotSame($localeMiddleware->withCookieSecure(true), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withDefaultLocale('uz'), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withEnableDetectLocale(true), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withEnableSaveLocale(false), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withLocales(['ru' => 'ru-RU', 'uz' => 'uz-UZ']), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withQueryParameterName('lang'), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withSessionName('lang'), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withIgnoredRequests(['/auth**']), $localeMiddleware);
    }

    public function testInvalidLocalesFormat(): void
    {
        $this->expectException(InvalidLocalesFormatException::class);

        $request = $this->createRequest('/');
        $middleware = $this->createMiddleware(['en', 'ru', 'uz']);

        $this->process($middleware, $request);
    }

    public function testDefaultLocale(): void
    {
        $request = $this->createRequest($uri = '/en/home?test=1');
        $middleware = $this->createMiddleware(['en' => 'en-US', 'uz' => 'uz-UZ']);

        $response = $this->process($middleware, $request);

        $this->assertSame('en', $this->locale);
        $this->assertSame($uri, $this->getRequestPath());
        $this->assertSame('/home?test=1', $response->getHeaderLine(Header::LOCATION));
    }

    public function testDefaultLocaleWithCountry(): void
    {
        $request = $this->createRequest($uri = '/uz');
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ'])->withDefaultLocale('uz-UZ');

        $response = $this->process($middleware, $request);

        $this->assertSame('uz', $this->locale);
        $this->assertSame('/', $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());
    }

    public function testWithoutLocales(): void
    {
        $request = $this->createRequest($uri = '/ru');
        $middleware = $this->createMiddleware([]);

        $this->process($middleware, $request);

        $this->assertNull($this->locale);
        $this->assertSame($uri, $this->getRequestPath());
    }

    public function testDefaultLocaleWithLocales(): void
    {
        $request = $this->createRequest($uri = '/ru/home');
        $middleware = $this->createMiddleware(['en' => 'en-US', 'ru' => 'ru-RU'])->withDefaultLocale('ru');

        $response = $this->process($middleware, $request);

        $this->assertSame('ru', $this->locale);
        $this->assertSame('/home', $response->getHeaderLine(Header::LOCATION));
    }

    public function testLocale(): void
    {
        $request = $this->createRequest($uri = '/uz');
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ']);

        $this->process($middleware, $request);

        $this->assertSame($uri, $this->getRequestPath());
    }

    public function testLocaleWithDash(): void
    {
        $request = $this->createRequest($uri = '/uz-UZ');
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ']);

        $this->process($middleware, $request);

        $this->assertSame($uri, $this->getRequestPath());
    }

    public function testLocaleWithUnderscore(): void
    {
        $request = $this->createRequest($uri = '/uz_UZ');
        $middleware = $this->createMiddleware(['uz' => 'uz_UZ']);

        $this->process($middleware, $request);

        $this->assertSame($uri, $this->getRequestPath());
    }

    public function testLocaleWithoutCountry(): void
    {
        $request = $this->createRequest($uri = '/uz');
        $middleware = $this->createMiddleware(['uz' => 'uz']);

        $this->process($middleware, $request);

        $this->assertSame($uri, $this->getRequestPath());
    }

    public function testLocaleWithSubtags(): void
    {
        $request = $this->createRequest($uri = '/en_us');
        $middleware = $this->createMiddleware(['en_us' => 'en-US', 'en_gb' => 'en-GB']);

        $this->process($middleware, $request);

        $this->assertSame($uri, $this->getRequestPath());
    }

    public function testSaveLocale(): void
    {
        $request = $this->createRequest($uri = '/uz');
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ']);

        $response = $this->process($middleware, $request);

        $this->assertSame('uz', $this->locale);
        $this->assertStringContainsString('_language=uz', $response->getHeaderLine(Header::SET_COOKIE));
    }

    public function testSavedLocale(): void
    {
        $request = $this->createRequest($uri = '/home?test=1', cookieParams: ['_language' => 'uz']);
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ', 'en' => 'en-US']);

        $response = $this->process($middleware, $request);

        $this->assertSame('/uz' . $uri, $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());
    }

    public function testLocaleWithQueryParam(): void
    {
        $request = $this->createRequest($uri = '/', queryParams: ['_language' => 'uz']);
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ']);

        $response = $this->process($middleware, $request);

        $this->assertSame('/uz' . $uri, $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());
    }

    public function testLocaleWithQueryParamCountry(): void
    {
        $request = $this->createRequest($uri = '/', queryParams: ['_language' => 'uz-UZ']);
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ']);

        $response = $this->process($middleware, $request);

        $this->assertSame('/uz' . $uri, $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());
    }

    public function testDetectLocale(): void
    {
        $request = $this->createRequest($uri = '/', headers: [Header::ACCEPT_LANGUAGE => 'uz-UZ']);
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ'])->withEnableDetectLocale(true);

        $response = $this->process($middleware, $request);

        $this->assertSame('uz', $this->locale);
        $this->assertSame('/uz' . $uri, $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());
    }

    public function testDetectLocaleWithoutHeader(): void
    {
        $request = $this->createRequest($uri = '/');
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ'])->withEnableDetectLocale(true);

        $response = $this->process($middleware, $request);

        $this->assertNull($this->locale);
        $this->assertSame('/en' . $uri, $this->getRequestPath());
        $this->assertSame('', $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::OK, $response->getStatusCode());
    }

    public function testLocaleWithOtherMethod(): void
    {
        $request = $this->createRequest($uri = '/', Method::POST, queryParams: ['_language' => 'uz']);
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ']);

        $response = $this->process($middleware, $request);

        $this->assertSame('uz', $this->locale);
        $this->assertSame('', $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::OK, $response->getStatusCode());
    }

    public function testIgnoredRequests(): void
    {
        $request = $this->createRequest($uri = '/auth/login', queryParams: ['_language' => 'uz']);
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ'])->withIgnoredRequests(['/auth/**']);

        $response = $this->process($middleware, $request);

        $this->assertSame('/en' . $uri, $this->getRequestPath());
        $this->assertSame(Status::OK, $response->getStatusCode());
    }

    private function process(Locale $middleware, ServerRequestInterface $request): ResponseInterface
    {
        $handler = new class () implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return new Response();
            }
        };

        $this->lastRequest = &$handler->request;
        return $middleware->process($request, $handler);
    }

    private function getRequestPath(): string
    {
        $uri = $this->lastRequest->getUri();
        return $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
    }

    private function createMiddleware(array $locales = [], bool $secure = false): Locale
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('setLocale')
            ->willReturnCallback(function ($locale) use ($translator) {
                $this->locale = $locale;
                return $translator;
            });

        $translator
            ->method('getLocale')
            ->willReturnReference($this->locale);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->method('setUriPrefix')
            ->willReturnCallback(function ($prefix) {
                $this->prefix = $prefix;
            });

        $urlGenerator
            ->method('setDefaultArgument')
            ->willReturnCallback(function ($name, $value) {
                $this->locale = $value;
            });

        $urlGenerator
            ->method('getUriPrefix')
            ->willReturnReference($this->prefix);

        $session = $this->createMock(SessionInterface::class);
        $session->method('set')
                ->willReturnCallback(function ($name, $value) {
                    $this->session[$name] = $value;
                });

        $session->method('get')
                ->willReturnCallback(fn ($name) => $this->session[$name]);

        return new Locale(
            $translator,
            $urlGenerator,
            $session,
            new SimpleLogger(),
            new ResponseFactory(),
            $locales,
            [],
            $secure
        );
    }

    private function createRequest(
        string $uri = '/',
        string $method = Method::GET,
        array $queryParams = [],
        array $headers = [],
        $cookieParams = []
    ): ServerRequestInterface {
        return new ServerRequest([], [], $cookieParams, $queryParams, null, $method, $uri, $headers);
    }
}
