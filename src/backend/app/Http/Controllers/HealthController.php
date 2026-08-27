<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            $database = 'ok';
        } catch (Throwable) {
            // Deliberately not disclosing the exception reason here — the
            // point of this branch is that a caller learns the database is
            // unreachable, not why (connection string, credentials, etc).
            $database = 'unreachable';
        }

        $healthy = $database === 'ok';

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'database' => $database,
        ], $healthy ? 200 : 503);
    }
}
