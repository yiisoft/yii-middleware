<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests\Middleware;

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
use Yiisoft\Yii\Middleware\Locale;

final class LocaleTest extends TestCase
{
    private string $locale = 'en';
    private array $session = [];
    private ?ServerRequestInterface $lastRequest;

    public function setUp(): void
    {
        $this->lastRequest = null;
    }

    public function testImmutability(): void
    {
        $locale = $this->createMiddleware();

        $this->assertNotSame($locale->withCookieSecure(true), $locale);
        $this->assertNotSame($locale->withDefaultLocale('uz'), $locale);
        $this->assertNotSame($locale->withEnableDetectLocale(true), $locale);
        $this->assertNotSame($locale->withEnableSaveLocale(false), $locale);
        $this->assertNotSame($locale->withLocales(['ru-RU', 'uz-UZ']), $locale);
        $this->assertNotSame($locale->withQueryParameterName('lang'), $locale);
        $this->assertNotSame($locale->withSessionName('lang'), $locale);
    }

    public function testDefaultLocale(): void
    {
        $request = $this->createRequest($uri = '/');
        $middleware = $this->createMiddleware([]);

        $this->process($middleware, $request);

        $this->assertSame('en', $this->locale);
        $this->assertSame($uri, $this->getRequestPath());
    }

    public function testLocale(): void
    {
        $request = $this->createRequest($uri = '/uz');
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ']);

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

    public function testLocaleWithQueryParam(): void
    {
        $request = $this->createRequest($uri = '/', queryParams: ['_language' => 'uz']);
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ']);

        $response = $this->process($middleware, $request);

        $this->assertSame('/uz' . $uri, $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());
    }

    public function testDetectLocale(): void
    {
        $request = $this->createRequest($uri = '/', headers: [Header::ACCEPT_LANGUAGE => 'uz']);
        $middleware = $this->createMiddleware(['uz' => 'uz-UZ'])->withEnableDetectLocale(true);

        $response = $this->process($middleware, $request);

        $this->assertSame('uz', $this->locale);
        $this->assertSame('/uz' . $uri, $response->getHeaderLine(Header::LOCATION));
        $this->assertSame(Status::FOUND, $response->getStatusCode());
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
        return $this->lastRequest
            ->getUri()
            ->getPath();
    }

    private function createMiddleware(array $locales = [], bool $secure = false): Locale
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('setLocale')
            ->willReturnCallback(function ($locale) {
                $this->locale = $locale;
            });

        $translator
            ->method('getLocale')
            ->willReturnReference($this->locale);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->method('setUriPrefix')
            ->willReturnCallback(function ($prefix) {
                $this->locale = $prefix;
            });

        $urlGenerator
            ->method('setDefaultArgument')
            ->willReturnCallback(function ($name, $value) {
                $this->locale = $value;
            });

        $urlGenerator
            ->method('getUriPrefix')
            ->willReturnReference($this->locale);

        $session = $this->createMock(SessionInterface::class);
        $session->method('set')
                ->willReturnCallback(function ($name, $value) {
                    $this->session[$name] = $value;
                });

        $session->method('get')
                ->willReturnCallback(function ($name) {
                    return $this->session[$name];
                });

        return new Locale(
            $translator,
            $urlGenerator,
            $session,
            new SimpleLogger(),
            new ResponseFactory(),
            $locales,
            $secure
        );
    }

    private function createRequest(string $uri = '/', string $method = Method::GET, array $queryParams = [], array $headers = []): ServerRequestInterface
    {
        return new ServerRequest([], [], [], $queryParams, null, $method, $uri, $headers);
    }
}
