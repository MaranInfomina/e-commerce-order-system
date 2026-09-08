<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use OpenApi\Attributes as OA;

#[OA\Info(version: '1.0.0', title: 'COE Order System API')]
#[OA\Server(url: '/api/v1', description: 'Primary API')]
#[OA\SecurityScheme(securityScheme: 'bearerAuth', type: 'http', scheme: 'bearer', bearerFormat: 'JWT')]
#[OA\Schema(
    schema: 'ErrorEnvelope',
    properties: [
        new OA\Property(property: 'error', properties: [
            new OA\Property(property: 'code', type: 'string', example: 'VALIDATION_FAILED'),
            new OA\Property(property: 'message', type: 'string'),
            new OA\Property(property: 'details', type: 'object', nullable: true),
        ], type: 'object'),
    ],
)]
abstract class Controller
{
    use AuthorizesRequests;
}
