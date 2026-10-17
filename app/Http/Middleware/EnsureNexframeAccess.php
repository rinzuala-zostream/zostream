<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNexframeAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('nexframe_dashboard_authenticated') !== true) {
            return response()->json(['message' => 'NextFrame dashboard authentication is required.'], 401);
        }

        return $next($request);
    }
}
