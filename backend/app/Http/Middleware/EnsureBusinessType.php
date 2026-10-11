<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessType
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        if (! $request->user()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($request->user()->business_type !== $type) {
            return response()->json([
                'message' => "Unauthorized. This dashboard is reserved for {$type} business owners.",
            ], 403);
        }

        return $next($request);
    }
}
