<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use DateTime;
use HttpSoft\Message\Response;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SlopeIt\ClockMock\ClockMock;
use Yiisoft\Cookies\CookieCollection;
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

        if (str_starts_with($this->getName(), 'testSaveLocale')) {
            ClockMock::freeze(new DateTime('2023-05-10 08:24:39'));
        }
    }

    public function tearDown(): void
    {
        if (str_starts_with($this->getName(), 'testSaveLocale')) {
            ClockMock::reset();
        }
    }

    public function testImmutability(): void
    {
        $localeMiddleware = $this->createMiddleware();

        $this->assertNotSame($localeMiddleware->withSecureCookie(true), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withDefaultLocale('uz'), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withDetectLocale(true), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withSaveLocale(false), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withSupportedLocales(['ru' => 'ru-RU', 'uz' => 'uz-UZ']), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withQueryParameterName('lang'), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withSessionName('lang'), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withIgnoredRequestUrlPatterns(['/auth**']), $localeMiddleware);
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
        $request = $this->createRequest('/uz');
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ'])->withDefaultLocale('uz-UZ');

        $response = $this->process($middleware, $request);

        $this->assertSame('uz', $this->locale);
        $this->assertSame('/', $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());
    }

    public function testWithoutLocales(): void
    {
        $request = $this->createRequest($uri = '/ru');
        $middleware = $this->createMiddleware();

        $this->process($middleware, $request);

        $this->assertNull($this->locale);
        $this->assertSame($uri, $this->getRequestPath());
    }

    public function testDefaultLocaleWithLocales(): void
    {
        $request = $this->createRequest('/ru/home');
        $middleware = $this->createMiddleware(['en' => 'en-US', 'ru' => 'ru-RU'])->withDefaultLocale('ru');

        $response = $this->process($middleware, $request);

        $this->assertSame('ru', $this->locale);
        $this->assertSame('/home', $response->getHeaderLine(Header::LOCATION));
    }

    public function dataLocale(): array
    {
        return [
            'basic' => ['/uz', ['uz' => 'uz-UZ']],
            'with dash' => ['/uz-UZ', ['uz' => 'uz-UZ']],
            'with underscore' => ['/uz_UZ', ['uz' => 'uz_UZ']],
            'without country' => ['/uz', ['uz' => 'uz']],
            'with subtags' => ['/en_us', ['en_us' => 'en-US', 'en_gb' => 'en-GB']],
        ];
    }

    /**
     * @dataProvider dataLocale
     */
    public function testLocale(string $requestUri, array $locales): void
    {
        $request = $this->createRequest($requestUri);
        $middleware = $this->createMiddleware($locales);

        $this->process($middleware, $request);

        $this->assertSame($requestUri, $this->getRequestPath());
    }

    public function testSaveLocale(): void
    {
        $request = $this->createRequest('/uz');
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ']);

        $response = $this->process($middleware, $request);

        $this->assertSame('uz', $this->locale);

        $cookies = CookieCollection::fromResponse($response)->toArray();
        $this->assertArrayHasKey('_language', $cookies);

        $cookie = $cookies['_language'];
        $this->assertSame('_language', $cookie->getName());
        $this->assertSame('uz', $cookie->getValue());
        $this->assertEquals(new DateTime('2023-06-09 08:24:39'), $cookie->getExpires());
        $this->assertFalse($cookie->isSecure());
    }

    public function testSaveLocaleWithCustomArguments(): void
    {
        $request = $this->createRequest('/uz');
        $middleware = $this
            ->createMiddleware(['uz' => 'uz-UZ'])
            ->withSecureCookie(true)
            ->withCookieDuration(new DateInterval('P31D'));

        $response = $this->process($middleware, $request);

        $this->assertSame('uz', $this->locale);

        $cookies = CookieCollection::fromResponse($response)->toArray();
        $this->assertArrayHasKey('_language', $cookies);

        $cookie = $cookies['_language'];
        $this->assertSame('_language', $cookie->getName());
        $this->assertSame('uz', $cookie->getValue());
        $this->assertEquals(new DateTime('2023-06-10 08:24:39'), $cookie->getExpires());
        $this->assertTrue($cookie->isSecure());
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
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ'])->withDetectLocale(true);

        $response = $this->process($middleware, $request);

        $this->assertSame('uz', $this->locale);
        $this->assertSame('/uz' . $uri, $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());
    }

    public function testDetectLocaleWithoutHeader(): void
    {
        $request = $this->createRequest($uri = '/');
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ'])->withDetectLocale(true);

        $response = $this->process($middleware, $request);

        $this->assertNull($this->locale);
        $this->assertSame('/en' . $uri, $this->getRequestPath());
        $this->assertSame('', $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::OK, $response->getStatusCode());
    }

    public function testLocaleWithOtherMethod(): void
    {
        $request = $this->createRequest('/', Method::POST, queryParams: ['_language' => 'uz']);
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ']);

        $response = $this->process($middleware, $request);

        $this->assertSame('uz', $this->locale);
        $this->assertSame('', $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::OK, $response->getStatusCode());
    }

    public function testIgnoredRequests(): void
    {
        $request = $this->createRequest($uri = '/auth/login', queryParams: ['_language' => 'uz']);
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ'])->withIgnoredRequestUrlPatterns(['/auth/**']);

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

    private function createMiddleware(array $locales = []): Locale
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
        $session
            ->method('set')
            ->willReturnCallback(function ($name, $value) {
                $this->session[$name] = $value;
            });

        $session
            ->method('get')
            ->willReturnCallback(fn ($name) => $this->session[$name]);

        return new Locale(
            $translator,
            $urlGenerator,
            $session,
            new SimpleLogger(),
            new ResponseFactory(),
            $locales,
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
