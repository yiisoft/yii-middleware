<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests;

use HttpSoft\Message\Response;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequestFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Header;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Yii\Middleware\ForceSecureConnection;

final class ForceSecureConnectionTest extends TestCase
{
    // Immutability
    public function testWithCSPImmutability(): void
    {
        $middleware = new ForceSecureConnection(new ResponseFactory());
        $new = $middleware->withCSP();

        $this->assertNotSame($middleware, $new);
    }

    public function testWithHSTSImmutability(): void
    {
        $middleware = new ForceSecureConnection(new ResponseFactory());
        $new = $middleware->withHSTS();

        $this->assertNotSame($middleware, $new);
    }

    public function testWithRedirectionImmutability(): void
    {
        $middleware = new ForceSecureConnection(new ResponseFactory());
        $new = $middleware->withRedirection();

        $this->assertNotSame($middleware, $new);
    }

    public function testWithoutCSPImmutability(): void
    {
        $middleware = new ForceSecureConnection(new ResponseFactory());
        $new = $middleware->withoutCSP();

        $this->assertNotSame($middleware, $new);
    }

    public function testWithoutHSTSImmutability(): void
    {
        $middleware = new ForceSecureConnection(new ResponseFactory());
        $new = $middleware->withoutHSTS();

        $this->assertNotSame($middleware, $new);
    }

    public function testWithoutRedirectionImmutability(): void
    {
        $middleware = new ForceSecureConnection(new ResponseFactory());
        $new = $middleware->withoutRedirection();

        $this->assertNotSame($middleware, $new);
    }

    public function testRedirectionFromHttp(): void
    {
        $middleware = (new ForceSecureConnection(new ResponseFactory()))
            ->withoutCSP()
            ->withoutHSTS()
            ->withRedirection(Status::SEE_OTHER);
        $request = $this->createServerRequest();
        $request = $request->withUri($request->getUri()->withScheme('http'));
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);

        $this->assertFalse($handler->isCalled);
        $this->assertTrue($response->hasHeader(Header::LOCATION));
        $this->assertSame(Status::SEE_OTHER, $response->getStatusCode());
        $this->assertSame('https://test.org/index.php', $response->getHeaderLine(Header::LOCATION));
    }

    public function testWithHSTS(): void
    {
        $middleware = (new ForceSecureConnection(new ResponseFactory()))
            ->withoutRedirection()
            ->withoutCSP()
            ->withHSTS(42, true);
        $request = $this->createServerRequest();
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);

        $this->assertTrue($handler->isCalled);
        $this->assertTrue($response->hasHeader(Header::STRICT_TRANSPORT_SECURITY));
        $this->assertSame('max-age=42; includeSubDomains', $response->getHeaderLine(Header::STRICT_TRANSPORT_SECURITY));
    }

    public function testWithHSTSNoSubdomains(): void
    {
        $middleware = (new ForceSecureConnection(new ResponseFactory()))
            ->withoutRedirection()
            ->withoutCSP()
            ->withHSTS(1440, false);
        $request = $this->createServerRequest();
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);

        $this->assertTrue($handler->isCalled);
        $this->assertTrue($response->hasHeader(Header::STRICT_TRANSPORT_SECURITY));
        $this->assertSame('max-age=1440', $response->getHeaderLine(Header::STRICT_TRANSPORT_SECURITY));
    }

    public function testWithCSP(): void
    {
        $middleware = (new ForceSecureConnection(new ResponseFactory()))
            ->withoutRedirection()
            ->withoutHSTS()
            ->withCSP();
        $request = $this->createServerRequest();
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);

        $this->assertTrue($handler->isCalled);
        $this->assertTrue($response->hasHeader(Header::CONTENT_SECURITY_POLICY));
    }

    public function testWithCSPCustomDirectives(): void
    {
        $middleware = (new ForceSecureConnection(new ResponseFactory()))
            ->withoutRedirection()
            ->withoutHSTS()
            ->withCSP('default-src https:; report-uri /csp-violation-report-endpoint/');
        $request = $this->createServerRequest();
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);

        $this->assertTrue($handler->isCalled);
        $this->assertTrue($response->hasHeader(Header::CONTENT_SECURITY_POLICY));
        $this->assertSame(
            $response->getHeaderLine(Header::CONTENT_SECURITY_POLICY),
            'default-src https:; report-uri /csp-violation-report-endpoint/'
        );
    }

    public function testSecurityHeadersOnRedirection(): void
    {
        $middleware = (new ForceSecureConnection(new ResponseFactory()))
            ->withRedirection()
            ->withCSP()
            ->withHSTS();
        $request = $this->createServerRequest();
        $request = $request->withUri($request->getUri()->withScheme('http'));
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);

        $this->assertFalse($handler->isCalled);
        $this->assertTrue($response->hasHeader(Header::LOCATION));
        $this->assertTrue($response->hasHeader(Header::STRICT_TRANSPORT_SECURITY));
        $this->assertFalse($response->hasHeader(Header::CONTENT_SECURITY_POLICY));
    }

    public function testWithoutRedirection(): void
    {
        $middleware = (new ForceSecureConnection(new ResponseFactory()))->withoutRedirection();
        $request = $this->createServerRequest();
        $request = $request->withUri($request->getUri()->withScheme('http'));
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);

        $this->assertFalse($response->hasHeader(Header::LOCATION));
    }

    public function testWithoutCSP(): void
    {
        $middleware = (new ForceSecureConnection(new ResponseFactory()))->withoutCSP();
        $request = $this->createServerRequest();
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);

        $this->assertFalse($response->hasHeader(Header::CONTENT_SECURITY_POLICY));
    }

    public function testWithoutHSTS(): void
    {
        $middleware = (new ForceSecureConnection(new ResponseFactory()))->withoutHSTS();
        $request = $this->createServerRequest();
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);

        $this->assertTrue($handler->isCalled);
        $this->assertFalse($response->hasHeader(Header::STRICT_TRANSPORT_SECURITY));
    }

    private function createHandler(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public bool $isCalled = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->isCalled = true;
                return new Response();
            }
        };
    }

    private function createServerRequest(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(Method::GET, 'https://test.org/index.php');
    }
}
