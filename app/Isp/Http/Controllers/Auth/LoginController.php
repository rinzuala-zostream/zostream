<?php

namespace App\Isp\Http\Controllers\Auth;

use App\Isp\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('isp.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        if (! Auth::guard('isp')->attempt($credentials + ['is_active' => true], $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Email or password is incorrect.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('isp.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('isp')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('isp.login');
    }
}
