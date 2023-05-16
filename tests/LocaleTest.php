<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use DateInterval;
use DateTime;
use HttpSoft\Message\Response;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SlopeIt\ClockMock\ClockMock;
use Psr\Log\LoggerInterface;
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
    private ?string $translatorLocale;
    private ?string $urlGeneratorLocale;
    private string $uriPrefix = '';
    private array $session = [];
    private ?ServerRequestInterface $lastRequest;
    private LoggerInterface $logger;

    public function setUp(): void
    {
        $this->translatorLocale = null;
        $this->urlGeneratorLocale = null;
        $this->uriPrefix = '';
        $this->session = [];
        $this->lastRequest = null;
        $this->logger = new SimpleLogger();

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
        $localeMiddleware = $this->createMiddleware(['uz' => 'uz-UZ']);

        $this->assertNotSame($localeMiddleware->withSecureCookie(true), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withCookieDuration(new DateInterval('P31D')), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withDefaultLocale('uz'), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withDetectLocale(true), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withSaveLocale(false), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withSupportedLocales(['ru' => 'ru-RU', 'uz' => 'uz-UZ']), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withQueryParameterName('lang'), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withSessionName('lang'), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withIgnoredRequestUrlPatterns(['/auth**']), $localeMiddleware);
    }

    public function dataInvalidLocalesFormat(): array
    {
        return [
            [['en', 'ru', 'uz']],
            [['en' => 'en-US', 'ru' => ['ru-RU'], 'uz' => 'uz-UZ']],
            [['en' => 'en-US', 'ru-RU', 'uz' => 'uz-UZ']],
            [['en' => 'en-US', ['ru-RU'], 'uz' => 'uz-UZ']],
        ];
    }

    /**
     * @dataProvider dataInvalidLocalesFormat
     */
    public function testInvalidLocalesFormat(array $supportedLocales): void
    {
        $this->expectException(InvalidLocalesFormatException::class);
        $this->createMiddleware($supportedLocales);
    }

    public function dataLocaleFromPath(): array
    {
        return [
            'matches default locale' => ['en', 'en-US', 'en'],
            'does not match default locale' => ['uz', 'uz-UZ', 'uz'],
        ];
    }

    /**
     * @dataProvider dataLocaleFromPath
     */
    public function testLocaleFromPath(
        string $localeInPath,
        string $expectedFullLocale,
        string $expectedShortLocale,
    ): void {
        $request = $this->createRequest($uri = "/$localeInPath/home?test=1");
        $middleware = $this->createMiddleware(['en' => 'en-US', 'uz' => 'uz-UZ']);

        $response = $this->process($middleware, $request);

        $this->assertSame($expectedFullLocale, $this->translatorLocale);
        $this->assertSame($expectedShortLocale, $this->urlGeneratorLocale);
        $this->assertSame($uri, $this->getRequestPath());
        $this->assertSame('/home?test=1', $response->getHeaderLine(Header::LOCATION));
    }

    public function testWithDefaultLocale(): void
    {
        $request = $this->createRequest('/');
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ'])->withDefaultLocale('uz');

        $response = $this->process($middleware, $request);

        $this->assertSame(null, $this->translatorLocale);
        $this->assertSame(null, $this->urlGeneratorLocale);
        $this->assertSame('', $response->getHeaderLine(Header::LOCATION));
        $this->assertSame('/uz/', $this->getRequestPath());
        $this->assertSame(Status::OK, $response->getStatusCode());
    }

    public function dataWithDefaultLocaleAndNotSupportedLocale(): array
    {
        return [
            'full name is specified instead of short one' => ['uz-UZ'],
            'irrelevant / non-existing locale' => ['test'],
        ];
    }

    /**
     * @dataProvider dataWithDefaultLocaleAndNotSupportedLocale
     */
    public function testWithDefaultLocaleAndNotSupportedLocale(string $defaultLocale): void
    {
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Default locale allows only keys from supported locales');
        $middleware->withDefaultLocale($defaultLocale);
    }

    public function dataDefaultLocaleAndMultipleSupportedLocales(): array
    {
        return [
            'basic' => ['', '/home'],
            'with URI prefix' => ['/api', '/api/home'],
            'with URI prefix, trailing slash' => ['/api/', '/api/home'],
        ];
    }

    /**
     * @dataProvider dataDefaultLocaleAndMultipleSupportedLocales
     */
    public function testWithDefaultLocaleAndMultipleSupportedLocales(
        string $uriPrefix,
        string $expectedLocationHeaderValue,
    ): void {
        $request = $this->createRequest('/ru/home');
        $middleware = $this->createMiddleware(['en' => 'en-US', 'ru' => 'ru-RU'])->withDefaultLocale('ru');
        $this->uriPrefix = $uriPrefix;

        $response = $this->process($middleware, $request);

        $this->assertSame('ru-RU', $this->translatorLocale);
        $this->assertSame('ru', $this->urlGeneratorLocale);
        $this->assertSame($expectedLocationHeaderValue, $response->getHeaderLine(Header::LOCATION));
    }

    public function testDefaultLocaleWithOtherHttpMethod(): void
    {
        $request = $this->createRequest('/ru/home', Method::POST, queryParams: ['_language' => 'ru']);
        $middleware = $this->createMiddleware(['en' => 'en-US', 'ru' => 'ru-RU'])->withDefaultLocale('ru');

        $response = $this->process($middleware, $request);

        $this->assertSame('ru-RU', $this->translatorLocale);
        $this->assertSame('ru', $this->urlGeneratorLocale);
        $this->assertSame('', $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::OK, $response->getStatusCode());
    }

    public function testWithoutSupportedLocales(): void
    {
        $request = $this->createRequest($uri = '/ru');
        $middleware = $this->createMiddleware();

        $this->process($middleware, $request);

        $this->assertSame(null, $this->translatorLocale);
        $this->assertSame(null, $this->urlGeneratorLocale);
        $this->assertSame($uri, $this->getRequestPath());
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

        $this->assertSame('uz-UZ', $this->translatorLocale);
        $this->assertSame('uz', $this->urlGeneratorLocale);

        $this->assertArrayHasKey('_language', $this->session);
        $this->assertSame('uz', $this->session['_language']);

        $cookies = CookieCollection::fromResponse($response)->toArray();
        $this->assertArrayNotHasKey('_language', $cookies);

        $expectedLoggerMessages = [
            [
                'level' => 'debug',
                'message' => "Locale 'uz' found in URL.",
                'context' => [],
            ],
            [
                'level' => 'debug',
                'message' => 'Saving found locale to session.',
                'context' => [],
            ],
        ];
        $this->assertSame($expectedLoggerMessages, $this->logger->getMessages());
    }

    public function testSaveLocaleWithCustomArguments(): void
    {
        $request = $this->createRequest('/uz');
        $middleware = $this
            ->createMiddleware(['uz' => 'uz-UZ'])
            ->withSecureCookie(true)
            ->withCookieDuration(new DateInterval('P30D'));

        $response = $this->process($middleware, $request);

        $this->assertSame('uz-UZ', $this->translatorLocale);
        $this->assertSame('uz', $this->urlGeneratorLocale);

        $cookies = CookieCollection::fromResponse($response)->toArray();
        $this->assertArrayHasKey('_language', $cookies);

        $cookie = $cookies['_language'];
        $this->assertSame('_language', $cookie->getName());
        $this->assertSame('uz', $cookie->getValue());
        $this->assertEquals(new DateTime('2023-06-09 08:24:39'), $cookie->getExpires());
        $this->assertTrue($cookie->isSecure());

        $expectedLoggerMessages = [
            [
                'level' => 'debug',
                'message' => "Locale 'uz' found in URL.",
                'context' => [],
            ],
            [
                'level' => 'debug',
                'message' => 'Saving found locale to session.',
                'context' => [],
            ],
            [
                'level' => 'debug',
                'message' => 'Saving found locale to cookies.',
                'context' => [],
            ],
        ];
        $this->assertSame($expectedLoggerMessages, $this->logger->getMessages());
    }

    public function testDisabledSaveLocale(): void
    {
        $request = $this->createRequest('/uz');
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ'])->withSaveLocale(false);

        $response = $this->process($middleware, $request);

        $this->assertSame('uz-UZ', $this->translatorLocale);
        $this->assertSame('uz', $this->urlGeneratorLocale);

        $cookies = CookieCollection::fromResponse($response)->toArray();
        $this->assertArrayNotHasKey('_language', $cookies);

        $this->assertArrayNotHasKey('_language', $this->session);

        $expectedLoggerMessages = [
            [
                'level' => 'debug',
                'message' => "Locale 'uz' found in URL.",
                'context' => [],
            ],
        ];
        $this->assertSame($expectedLoggerMessages, $this->logger->getMessages());
    }

    public function testLocaleFromCookies(): void
    {
        $request = $this->createRequest($uri = '/home?test=1', cookieParams: ['_language' => 'uz']);
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ', 'en' => 'en-US']);

        $response = $this->process($middleware, $request);

        $this->assertSame('/uz' . $uri, $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());

        $expectedLoggerMessages = [
            [
                'level' => 'debug',
                'message' => "Locale 'uz' found in cookies.",
                'context' => [],
            ],
            [
                'level' => 'debug',
                'message' => 'Saving found locale to session.',
                'context' => [],
            ],
        ];
        $this->assertSame($expectedLoggerMessages, $this->logger->getMessages());
    }

    public function dataLocaleFromQueryParam(): array
    {
        return [
            'basic' => ['uz', 'uz'],
            'extended' => ['uz-UZ', 'uz'],
        ];
    }

    /**
     * @dataProvider dataLocaleFromQueryParam
     */
    public function testLocaleFromQueryParam(string $locale, string $expectedLocale): void
    {
        $request = $this->createRequest($uri = '/', queryParams: ['_language' => 'uz']);
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ']);

        $response = $this->process($middleware, $request);

        $this->assertSame("/$expectedLocale" . $uri, $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());

        $expectedLoggerMessages = [
            [
                'level' => 'debug',
                'message' => "Locale '$expectedLocale' found in query string.",
                'context' => [],
            ],
            [
                'level' => 'debug',
                'message' => 'Saving found locale to session.',
                'context' => [],
            ],
        ];
        $this->assertSame($expectedLoggerMessages, $this->logger->getMessages());
    }

    public function dataDetectLocale(): array
    {
        return [
            'dash separator' => ['uz', 'uz-UZ', '', '/uz'],
            'underscore separator' => ['uz', 'uz_UZ', '', '/uz'],
            'locale with more than separator' => ['zh', 'zh-Hant-TW', '', '/zh'],
            'locale without separator' => ['uz', 'uz', '', '/uz'],
            'with URI prefix' => ['uz', 'uz-UZ', '/api', '/api/uz'],
            'with URI prefix, trailing slash' => ['uz', 'uz-UZ', '/api/', '/api/uz'],
        ];
    }

    /**
     * @dataProvider dataDetectLocale
     */
    public function testDetectLocale(
        string $shortLocale,
        string $fullLocale,
        string $uriPrefix,
        string $expectedLocationHeaderValue,
    ): void {
        $request = $this->createRequest($uri = '/', headers: [Header::ACCEPT_LANGUAGE => $fullLocale]);
        $middleware = $this->createMiddleware([$shortLocale => $fullLocale])->withDetectLocale(true);
        $this->uriPrefix = $uriPrefix;

        $response = $this->process($middleware, $request);

        $this->assertSame($fullLocale, $this->translatorLocale);
        $this->assertSame($shortLocale, $this->urlGeneratorLocale);
        $this->assertSame($expectedLocationHeaderValue . $uri, $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());
    }

    public function testDetectLocaleWithQueryParam(): void
    {
        $request = $this->createRequest(
            $uri = '/',
            queryParams: ['_language' => 'ru'],
            headers: [Header::ACCEPT_LANGUAGE => 'uz-UZ'],
        );
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ', 'ru' => 'ru-RU'])->withDetectLocale(true);

        $response = $this->process($middleware, $request);

        $this->assertSame('ru-RU', $this->translatorLocale);
        $this->assertSame('ru', $this->urlGeneratorLocale);
        $this->assertSame('/ru' . $uri, $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());
    }

    public function testDetectLocaleWithoutHeader(): void
    {
        $request = $this->createRequest($uri = '/');
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ'])->withDetectLocale(true);
        $this->urlGeneratorLocale = 'uz';

        $response = $this->process($middleware, $request);

        $this->assertSame(null, $this->translatorLocale);
        $this->assertSame(null, $this->urlGeneratorLocale);
        $this->assertSame('/en' . $uri, $this->getRequestPath());
        $this->assertSame('', $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::OK, $response->getStatusCode());
    }

    public function testLocaleWithOtherHttpMethod(): void
    {
        $request = $this->createRequest('/', Method::POST, queryParams: ['_language' => 'uz']);
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ']);

        $response = $this->process($middleware, $request);

        $this->assertSame('uz-UZ', $this->translatorLocale);
        $this->assertSame('uz', $this->urlGeneratorLocale);
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
                $this->translatorLocale = $locale;
                return $translator;
            });

        $translator
            ->method('getLocale')
            ->willReturnReference($this->translatorLocale);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->method('setUriPrefix')
            ->willReturnCallback(function ($name) {
                $this->uriPrefix = $name;
            });

        $urlGenerator
            ->method('setDefaultArgument')
            ->willReturnCallback(function ($name, $value) {
                $this->urlGeneratorLocale = $value;
            });

        $urlGenerator
            ->method('getUriPrefix')
            ->willReturnReference($this->uriPrefix);

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
            $this->logger,
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
