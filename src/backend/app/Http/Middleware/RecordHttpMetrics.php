<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\Redis as RedisAdapter;
use Symfony\Component\HttpFoundation\Response;

class RecordHttpMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        $registry = self::registry();
        $route = $request->route()?->uri() ?? 'unmatched';

        $registry->getOrRegisterCounter(
            'coe', 'http_requests_total', 'Total HTTP requests',
            ['method', 'route', 'status'],
        )->inc([$request->method(), $route, (string) $response->getStatusCode()]);

        $registry->getOrRegisterHistogram(
            'coe', 'http_request_duration_seconds', 'HTTP request duration in seconds',
            ['method', 'route'],
            [0.01, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5],
        )->observe(microtime(true) - $start, [$request->method(), $route]);

        return $response;
    }

    public static function registry(): CollectorRegistry
    {
        static $registry = null;

        if ($registry === null) {
            $adapter = RedisAdapter::fromExistingConnection(
                app('redis')->connection('cache')->client()
            );
            $registry = new CollectorRegistry($adapter);
        }

        return $registry;
    }
}
