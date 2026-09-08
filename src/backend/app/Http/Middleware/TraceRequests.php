<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\TracerProviderFactory;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Symfony\Component\HttpFoundation\Response;

class TraceRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $tracer = self::tracerProvider()->getTracer('coe-backend');

        $span = $tracer->spanBuilder($request->method().' '.($request->route()?->uri() ?? 'unmatched'))
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $scope = $span->activate();

        try {
            $response = $next($request);

            $span->setAttribute('http.status_code', $response->getStatusCode());

            if ($response->getStatusCode() >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            return $response;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    public static function tracerProvider(): TracerProviderInterface
    {
        static $provider = null;

        if ($provider === null) {
            // TracerProviderFactory::create() takes no serviceName/endpoint
            // arguments — the whole open-telemetry/sdk + open-telemetry/exporter-otlp
            // stack is configured entirely through OTEL_* environment variables
            // (OTEL_SERVICE_NAME, OTEL_TRACES_EXPORTER, OTEL_EXPORTER_OTLP_ENDPOINT,
            // OTEL_EXPORTER_OTLP_PROTOCOL, ...), which docker-compose.yml sets for
            // both php-fpm and queue-worker. Confirmed against the installed
            // vendor/open-telemetry/sdk 1.15.0 source (Trace/TracerProviderFactory.php,
            // Resource/ResourceInfoFactory.php) rather than assumed. The return type
            // is the interface, not the concrete TracerProvider class, because
            // create() falls back to a NoopTracerProvider (a different class
            // hierarchy) when OTEL_SDK_DISABLED=true.
            $provider = (new TracerProviderFactory)->create();
        }

        return $provider;
    }

    /**
     * The current span's context, serialized via the standard W3C
     * `traceparent` propagation format. This is OpenTelemetry's own
     * designed-for-exactly-this mechanism
     * (TextMapPropagatorInterface::inject()/::extract() with a plain-array
     * carrier) — every OTel SDK ships it for cross-process propagation, so
     * use it as-is rather than inventing a custom scheme.
     *
     * @return array<string, string>
     */
    public static function currentContext(): array
    {
        $carrier = [];

        TraceContextPropagator::getInstance()->inject($carrier);

        return $carrier;
    }
}
