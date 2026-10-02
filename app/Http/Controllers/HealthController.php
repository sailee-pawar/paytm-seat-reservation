<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function ready(): JsonResponse
    {
        try {
            DB::connection()->getPdo();

            return response()->json([
                'status' => 'ready',
                'database' => 'connected',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'not_ready',
                'database' => 'unavailable',
            ], 503);
        }
    }
}