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
use Yiisoft\Http\Method;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Test\Support\Log\SimpleLogger;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\Yii\Middleware\Exception\BadUriPrefixException;
use Yiisoft\Yii\Middleware\Locale;
use Yiisoft\Yii\Middleware\SubFolder;

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
        $middleware = $this->createMiddleware([])->withDefaultLocale('uz');

        $this->process($middleware, $request);

        $this->assertSame('', $this->locale);
        $this->assertSame('/uz/' . $uri, $this->getRequestPath());
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

    private function createRequest(string $uri = '/'): ServerRequestInterface
    {
        return new ServerRequest([], [], [], [], null, Method::GET, $uri);
    }
}
