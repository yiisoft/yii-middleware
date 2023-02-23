<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\Middleware\Exception\BadUriPrefixException;

use function basename;
use function dirname;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

/**
 * This middleware supports routing when webroot is not the same folder as public.
 */
final class SubFolder implements MiddlewareInterface
{
    /**
     * @param UrlGeneratorInterface $uriGenerator The URI generator instance.
     * @param Aliases $aliases The aliases instance.
     * @param string|null $prefix URI prefix the specified immediately after the domain part.
     * The prefix value usually begins with a slash and must not end with a slash.
     * @param string|null $baseUrlAlias The base url alias {@see Aliases::get()}. Default "@baseUrl".
     */
    public function __construct(
        private UrlGeneratorInterface $uriGenerator,
        private Aliases $aliases,
        private ?string $prefix = null,
        private ?string $baseUrlAlias = '@baseUrl',
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $baseUrl = $this->prefix ?? $this->getBaseUrl($request);
        $length = strlen($baseUrl);

        if ($this->prefix !== null) {
            if ($baseUrl[-1] === '/') {
                throw new BadUriPrefixException('Wrong URI prefix value.');
            }

            if (!str_starts_with($path, $baseUrl)) {
                throw new BadUriPrefixException('URI prefix does not match.');
            }
        }

        if ($length > 0) {
            $newPath = substr($path, $length);

            if ($newPath === '') {
                $newPath = '/';
            }

            if ($newPath[0] === '/') {
                $request = $request->withUri($uri->withPath($newPath));
            } elseif ($this->prefix !== null) {
                throw new BadUriPrefixException('URI prefix does not match completely.');
            }

            $this->uriGenerator->setUriPrefix($baseUrl);
            if ($this->baseUrlAlias !== null) {
                $this->aliases->set($this->baseUrlAlias, $baseUrl);
            }
        }

        return $handler->handle($request);
    }

    public function getBaseUrl(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        $scriptUrl = $serverParams['SCRIPT_FILENAME'];
        $scriptName = basename($scriptUrl);

        if (isset($serverParams['PHP_SELF']) && basename($serverParams['PHP_SELF']) === $scriptName) {
            $scriptUrl = $serverParams['PHP_SELF'];
        } elseif (
            isset($serverParams['ORIG_SCRIPT_NAME']) &&
            basename($serverParams['ORIG_SCRIPT_NAME']) === $scriptName
        ) {
            $scriptUrl = $serverParams['ORIG_SCRIPT_NAME'];
        } elseif (
            isset($serverParams['PHP_SELF']) &&
            ($pos = strpos($serverParams['PHP_SELF'], '/' . $scriptName)) !== false
        ) {
            $scriptUrl = substr($serverParams['PHP_SELF'], 0, $pos) . '/' . $scriptName;
        } elseif (
            !empty($serverParams['DOCUMENT_ROOT']) &&
            str_starts_with($scriptUrl, $serverParams['DOCUMENT_ROOT'])
        ) {
            $scriptUrl = str_replace([$serverParams['DOCUMENT_ROOT'], '\\'], ['', '/'], $scriptUrl);
        }

        return rtrim(dirname($scriptUrl), '\\/');
    }
}
