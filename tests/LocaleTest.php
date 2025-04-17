<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use HttpSoft\Message\Response;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Yiisoft\Cookies\Cookie;
use Yiisoft\Cookies\CookieCollection;
use Yiisoft\Http\Header;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Test\Support\Log\SimpleLogger;
use Yiisoft\Yii\Middleware\Event\SetLocaleEvent;
use Yiisoft\Yii\Middleware\Exception\InvalidLocalesFormatException;
use Yiisoft\Yii\Middleware\Locale;
use Yiisoft\Yii\Middleware\Tests\Support\StaticClock;

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
    }

    public function testImmutability(): void
    {
        $localeMiddleware = $this->createMiddleware(['uz' => 'uz-UZ']);

        $this->assertNotSame($localeMiddleware->withSecureCookie(true), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withCookieDuration(new DateInterval('P31D')), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withDefaultLocale('uz'), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withDetectLocale(true), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withSupportedLocales(['ru' => 'ru-RU', 'uz' => 'uz-UZ']), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withQueryParameterName('lang'), $localeMiddleware);
        $this->assertNotSame($localeMiddleware->withCookieName('lang'), $localeMiddleware);
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

    public function testLocaleFromPathMatchesDefaultLocale(): void
    {
        $request = $this->createRequest('/en/home?test=1');
        $middleware = $this->createMiddleware(
            locales: ['en' => 'en-US', 'uz' => 'uz-UZ'],
            cookieDuration: new DateInterval('P5D'),
        );

        $response = $this->process($middleware, $request);

        $cookies = CookieCollection::fromResponse($response)->toArray();

        $this->assertSame(Status::FOUND, $response->getStatusCode());
        $this->assertSame('/home?test=1', $response->getHeaderLine(Header::LOCATION));
        $this->assertArrayHasKey('_language', $cookies);
        $this->assertSame('en', $cookies['_language']->getValue());
    }

    public function testDefaultLocaleDoNotSaveToCookie(): void
    {
        $request = $this->createRequest('/home?test=1');

        $middleware = $this->createMiddleware(
            locales: ['en' => 'en-US', 'uz' => 'uz-UZ'],
            cookieDuration: new DateInterval('P5D'),
        );

        $response = $this->process($middleware, $request);

        $cookies = CookieCollection::fromResponse($response)->toArray();

        $this->assertSame(Status::OK, $response->getStatusCode());
        $this->assertEmpty($cookies);
    }

    public function testLocaleFromPathDoesNotMatchDefaultLocale(): void
    {
        $uri = '/uz/home?test=1';
        $request = $this->createRequest($uri);
        $middleware = $this->createMiddleware(['en' => 'en-US', 'uz' => 'uz-UZ']);

        $response = $this->process($middleware, $request);

        $this->assertSame(Status::OK, $response->getStatusCode());
        $this->assertSame('uz-UZ', $this->translatorLocale);
        $this->assertSame('uz', $this->urlGeneratorLocale);
        $this->assertSame($uri, $this->getRequestPath());
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

        $this->assertSame(Status::FOUND, $response->getStatusCode());
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

    public function testSaveLocaleWithDefaultArguments(): void
    {
        $request = $this->createRequest('/uz');
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ']);
        $response = $this->process($middleware, $request);

        $this->assertSame('uz-UZ', $this->translatorLocale);
        $this->assertSame('uz', $this->urlGeneratorLocale);

        $this->assertArrayNotHasKey('_language', $this->session);

        $cookies = CookieCollection::fromResponse($response)->toArray();
        $this->assertArrayNotHasKey('_language', $cookies);

        $expectedLoggerMessages = [
            [
                'level' => 'debug',
                'message' => "Locale 'uz' found in URL.",
                'context' => [],
            ],
        ];
        $this->assertSame($expectedLoggerMessages, $this->logger->getMessages());
    }

    public function dataSaveLocaleWithCustomArguments(): array
    {
        return [
            'cookie name: default, secureCookie: default' => [null, null],
            'cookie name: default, secureCookie: false' => [null, false],
            'cookie name: default, secureCookie: true' => [null, true],
            'cookie name: custom, secureCookie: default' => ['_cookie_language', null],
        ];
    }

    /**
     * @dataProvider dataSaveLocaleWithCustomArguments
     */
    public function testSaveLocaleWithCustomArguments(?string $cookieName, ?bool $secureCookie): void
    {
        $clock = new StaticClock(new DateTimeImmutable('2023-05-10 08:24:39'));
        $request = $this->createRequest('/uz');
        $middleware = $this
            ->createMiddleware(
                ['uz' => 'uz-UZ'],
                saveToSession: true,
                clock: $clock,
            )
            ->withCookieDuration(new DateInterval('P30D'));

        if ($cookieName !== null) {
            $middleware = $middleware->withCookieName($cookieName);
        }

        if ($secureCookie !== null) {
            $middleware = $middleware->withSecureCookie($secureCookie);
        }

        $cookieName ??= '_language';
        $expectedSecureCookie = $secureCookie ?? false;

        $response = $this->process($middleware, $request);

        $this->assertSame('uz-UZ', $this->translatorLocale);
        $this->assertSame('uz', $this->urlGeneratorLocale);

        $this->assertArrayHasKey('_language', $this->session);
        $this->assertSame('uz-UZ', $this->session['_language']);

        $cookies = [];
        foreach ($response->getHeader(Header::SET_COOKIE) as $cookieString) {
            $cookie = Cookie::fromCookieString($cookieString, $clock);
            $cookies[$cookie->getName()] = $cookie;
        }
        $this->assertArrayHasKey($cookieName, $cookies);

        $cookie = $cookies[$cookieName];
        $this->assertSame($cookieName, $cookie->getName());
        $this->assertSame('uz', $cookie->getValue());
        $this->assertEquals(new DateTime('2023-06-09 08:24:39'), $cookie->getExpires());
        $this->assertSame($expectedSecureCookie, $cookie->isSecure());

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

    public function dataLocaleFromCookies(): array
    {
        return [
            'default parameter name' => [null],
            'custom parameter name' => ['_cookies_language'],
        ];
    }

    /**
     * @dataProvider dataLocaleFromCookies
     */
    public function testLocaleFromCookies(?string $parameterName): void
    {
        $middleware = $this->createMiddleware(
            locales: ['uz' => 'uz-UZ', 'en' => 'en-US'],
            cookieDuration: new DateInterval('P5D'),
        );
        if ($parameterName !== null) {
            $middleware = $middleware->withCookieName($parameterName);
        }

        $parameterName ??= '_language';
        $request = $this->createRequest($uri = '/home?test=1', cookieParams: [$parameterName => 'uz']);
        $response = $this->process($middleware, $request);

        $this->assertSame('/uz' . $uri, $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());

        $expectedLoggerMessages = [
            [
                'level' => 'debug',
                'message' => "Locale 'uz' found in cookies.",
                'context' => [],
            ],
        ];
        $this->assertSame($expectedLoggerMessages, $this->logger->getMessages());
    }

    public function dataLocaleFromQueryParam(): array
    {
        return [
            'basic, default parameter name' => ['uz', 'uz', null],
            'basic, custom parameter name' => ['uz', 'uz', '_query_language'],
            'extended, default parameter name' => ['uz-UZ', 'uz', null],
        ];
    }

    /**
     * @dataProvider dataLocaleFromQueryParam
     */
    public function testLocaleFromQueryParam(string $locale, string $expectedLocale, ?string $parameterName): void
    {
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ']);
        if ($parameterName !== null) {
            $middleware = $middleware->withQueryParameterName($parameterName);
        }

        $parameterName ??= '_language';
        $request = $this->createRequest($uri = '/', queryParams: [$parameterName => 'uz']);
        $response = $this->process($middleware, $request);

        $this->assertSame("/$expectedLocale" . $uri, $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());

        $expectedLoggerMessages = [
            [
                'level' => 'debug',
                'message' => "Locale '$expectedLocale' found in query string.",
                'context' => [],
            ],
        ];
        $this->assertSame($expectedLoggerMessages, $this->logger->getMessages());
    }

    public function testLocaleFromQueryParamPriorityOverCookie(): void
    {
        $middleware = $this->createMiddleware(
            locales: ['uz' => 'uz-UZ', 'ru' => 'ru-RU'],
            cookieDuration: new DateInterval('P5D'),
        );

        $request = $this->createRequest(
            '/',
            queryParams: ['_language' => 'uz'],
            cookieParams: ['_language' => 'ru']
        );
        $response = $this->process($middleware, $request);

        $this->assertSame('/uz/', $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());
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

        $this->assertSame($uri, $this->getRequestPath());
        $this->assertSame(Status::OK, $response->getStatusCode());
    }

    public function testEventBeforeHandleRequest(): void
    {
        $stack = new class () {
            public array $data = [];

            public function add(mixed $value): void
            {
                $this->data[] = $value;
            }
        };

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(
                function (SetLocaleEvent $event) use ($stack) {
                    $stack->add('event');
                    $stack->add($event->getLocale());
                    return $event;
                }
            );

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->method('setDefaultArgument')
            ->willReturnCallback(
                function ($name, $value) use ($stack) {
                    $stack->add('urlGenerator');
                    $stack->add($value);
                }
            );

        $middleware = new Locale(
            $eventDispatcher,
            $urlGenerator,
            $this->logger,
            new ResponseFactory(),
            ['ru' => 'ru-RU'],
        );

        $handler = new class ($stack) implements RequestHandlerInterface {
            public function __construct(private $stack)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->stack->add('handler');
                return new Response();
            }
        };

        $middleware->process(
            $this->createRequest('/ru/test'),
            $handler
        );

        $this->assertSame(
            ['event', 'ru-RU', 'urlGenerator', 'ru', 'handler'],
            $stack->data
        );
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

    private function createMiddleware(
        array $locales = [],
        bool $saveToSession = false,
        ?DateInterval $cookieDuration = null,
        ?ClockInterface $clock = null,
    ): Locale {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function (SetLocaleEvent $event) use ($eventDispatcher, $saveToSession) {
                $this->translatorLocale = $event->getLocale();

                if ($saveToSession) {
                    $this->logger->debug('Saving found locale to session.');
                    $this->createSession()->set('_language', $event->getLocale());
                }

                return $eventDispatcher;
            });

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

        return new Locale(
            $eventDispatcher,
            $urlGenerator,
            $this->logger,
            new ResponseFactory(),
            $locales,
            cookieDuration: $cookieDuration,
            clock: $clock,
        );
    }

    private function createRequest(
        string $uri = '/',
        string $method = Method::GET,
        array $queryParams = [],
        array $headers = [],
        $cookieParams = []
    ): ServerRequestInterface {
        return new ServerRequest(
            cookieParams: $cookieParams,
            queryParams: $queryParams,
            method: $method,
            uri: $uri,
            headers: $headers,
        );
    }

    private function createSession(): SessionInterface
    {
        $session = $this->createMock(SessionInterface::class);
        $session
            ->method('set')
            ->willReturnCallback(function ($name, $value) {
                $this->session[$name] = $value;
            });

        $session
            ->method('get')
            ->willReturnCallback(fn ($name) => $this->session[$name]);

        return $session;
    }
}
