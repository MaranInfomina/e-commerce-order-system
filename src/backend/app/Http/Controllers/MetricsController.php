<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RecordHttpMetrics;
use Illuminate\Http\Response;
use Prometheus\RenderTextFormat;

class MetricsController extends Controller
{
    public function __invoke(): Response
    {
        $renderer = new RenderTextFormat;
        $body = $renderer->render(RecordHttpMetrics::registry()->getMetricFamilySamples());

        return response($body, 200)->header('Content-Type', RenderTextFormat::MIME_TYPE);
    }
}
