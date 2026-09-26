<?php

namespace Modules\Core\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Modules\Core\Http\Requests\LoginRequest;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('core::auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->autenticar();

        $request->session()->regenerate();

        return redirect()->intended(route('core.painel'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
