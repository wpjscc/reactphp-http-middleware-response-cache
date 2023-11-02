<?php

declare(strict_types=1);

namespace Wpjscc\React\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Cache\ArrayCache;
use React\Cache\CacheInterface;
use React\Http\Io\HttpBodyStream;
use function React\Promise\resolve;
use function RingCentral\Psr7\stream_for;

final class ResponseCacheMiddleware
{
    /**
     * @var CacheConfigurationInterface
     */
    private $cacheConfiguration;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @param CacheConfigurationInterface $cacheConfiguration
     * @param CacheInterface|null         $cache
     */
    public function __construct(CacheConfigurationInterface $cacheConfiguration, CacheInterface $cache = null)
    {
        $this->cacheConfiguration = $cacheConfiguration;
        $this->cache = $cache instanceof CacheInterface ? $cache : new ArrayCache();
    }

    public function __invoke(ServerRequestInterface $request, callable $next)
    {
        if (!$this->cacheConfiguration->requestIsCacheable($request)) {
            return resolve($next($request));
        }

        $key = $this->cacheConfiguration->cacheKey($request);

        return $this->cache->get($key)->then(function ($json) use ($next, $request, $key) {
            if ($json !== null) {
                return $this->cacheConfiguration->cacheDecode($json);
            }

            return resolve($next($request))->then(function (ResponseInterface $response) use ($request, $key) {
                if ($response->getBody() instanceof HttpBodyStream && !$this->cacheConfiguration->isSupportStreamedResponse()) {
                    return $response;
                }


                if (!$this->cacheConfiguration->responseIsCacheable($request, $response)) {
                    return $response;
                }

                $body =  $response->getBody();

                if ($response->getBody() instanceof HttpBodyStream) {
                    $buffer = '';
                    $body->on('data', function ($data) use (&$buffer) {
                        $buffer .= $data;
                    });

                    $body->on('end', function () use ($request, $response, &$buffer, $key) {
                        $ttl = $this->cacheConfiguration->cacheTtl($request, $response);
                        $encodedResponse = $this->cacheConfiguration->cacheEncode($response->withBody(stream_for($buffer)));
                        $buffer = null;
                        $this->cache->set($key, $encodedResponse, $ttl);
                    });
                } else {
                    $ttl = $this->cacheConfiguration->cacheTtl($request, $response);
                    $encodedResponse = $this->cacheConfiguration->cacheEncode($response->withBody(stream_for((string)$body)));
                    $this->cache->set($key, $encodedResponse, $ttl);
                }
                return $response;
            });
        });
    }
}
