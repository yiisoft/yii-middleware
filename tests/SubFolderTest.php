<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware\Tests\Middleware;

use HttpSoft\Message\Response;
use HttpSoft\Message\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Http\Method;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\Middleware\SubFolder;

final class SubFolderTest extends TestCase
{
    private string $urlGeneratorUriPrefix;
    private Aliases $aliases;
    private ?ServerRequestInterface $lastRequest;

    public function setUp(): void
    {
        $this->urlGeneratorUriPrefix = '';
        $this->lastRequest = null;
        $this->aliases = new Aliases(['@baseUrl' => '/default/web']);
    }

    public function testDefault(): void
    {
        $request = $this->createRequest($uri = '/', $script = '/index.php');
        $mw = $this->createMiddleware('@baseUrl');

        $this->process($mw, $request);

        $this->assertSame('/default/web', $this->aliases->get('@baseUrl'));
        $this->assertSame('', $this->urlGeneratorUriPrefix);
        $this->assertSame($uri, $this->getRequestPath());
    }

    public function testAutoPrefix(): void
    {
        $request = $this->createRequest($uri = '/public/', $script = '/public/index.php');
        $mw = $this->createMiddleware('@baseUrl');

        $this->process($mw, $request);

        $this->assertSame('/public', $this->aliases->get('@baseUrl'));
        $this->assertSame('/public', $this->urlGeneratorUriPrefix);
        $this->assertSame('/', $this->getRequestPath());
    }

    public function testAutoPrefixLogn(): void
    {
        $prefix = '/root/php/dev-server/project-42/index_html/public/web';
        $uri = "{$prefix}/";
        $script = "{$prefix}/index.php";
        $request = $this->createRequest($uri, $script);
        $mw = $this->createMiddleware('@baseUrl');

        $this->process($mw, $request);

        $this->assertSame($prefix, $this->aliases->get('@baseUrl'));
        $this->assertSame($prefix, $this->urlGeneratorUriPrefix);
        $this->assertSame('/', $this->getRequestPath());
    }

    public function testAutoPrefixAndUriWithoutTrailingSlash(): void
    {
        $request = $this->createRequest($uri = '/public', $script = '/public/index.php');
        $mw = $this->createMiddleware('@baseUrl');

        $this->process($mw, $request);

        $this->assertSame('/public', $this->aliases->get('@baseUrl'));
        $this->assertSame('/public', $this->urlGeneratorUriPrefix);
        $this->assertSame('/', $this->getRequestPath());
    }

    public function testAutoPrefixFullUrl(): void
    {
        $request = $this->createRequest($uri = '/public/index.php?test', $script = '/public/index.php');
        $mw = $this->createMiddleware('@baseUrl');

        $this->process($mw, $request);

        $this->assertSame('/public', $this->aliases->get('@baseUrl'));
        $this->assertSame('/public', $this->urlGeneratorUriPrefix);
        $this->assertSame('/index.php', $this->getRequestPath());
    }

    public function testFailedAutoPrefix(): void
    {
        $request = $this->createRequest($uri = '/web/index.php', $script = '/public/index.php');
        $mw = $this->createMiddleware('@baseUrl');

        $this->process($mw, $request);

        $this->assertSame('/public', $this->aliases->get('@baseUrl'));
        $this->assertSame('/public', $this->urlGeneratorUriPrefix);
        $this->assertSame($uri, $this->getRequestPath());
    }

    public function testAutoPrefixDoesNotMatchCompletely(): void
    {
        $request = $this->createRequest($uri = '/public/web/', $script = '/pub/index.php');
        $mw = $this->createMiddleware('@baseUrl');

        $this->process($mw, $request);

        $this->assertSame('/pub', $this->aliases->get('@baseUrl'));
        $this->assertSame('/pub', $this->urlGeneratorUriPrefix);
        $this->assertSame($uri, $this->getRequestPath());
    }

    private function process(SubFolder $middleware, ServerRequestInterface $request): ResponseInterface
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

    private function createMiddleware(?string $alias = null): SubFolder
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->method('setUriPrefix')
            ->willReturnCallback(function ($prefix) {
                $this->urlGeneratorUriPrefix = $prefix;
            });

        $urlGenerator
            ->method('getUriPrefix')
            ->willReturnReference($this->urlGeneratorUriPrefix);
        return new SubFolder($urlGenerator, $this->aliases, $alias);
    }

    private function createRequest(string $uri = '/', string $scriptPath = '/'): ServerRequestInterface
    {
        return new ServerRequest(['SCRIPT_FILENAME' => $scriptPath], [], [], [], null, Method::GET, $uri);
    }
}
