<?php

namespace App\Isp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_active) {
            Auth::guard('isp')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('isp.login')->with('error', 'Your panel account is disabled.');
        }

        return $next($request);
    }
}
