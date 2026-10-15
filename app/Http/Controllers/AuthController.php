<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\DemoReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! app()->environment(['local', 'testing']) && str_ends_with(strtolower($data['email']), '@aldef.local')) {
            throw ValidationException::withMessages(['email' => 'Akun demo dinonaktifkan di production.']);
        }if (! Auth::attempt($data)) {
            throw ValidationException::withMessages(['email' => 'Email atau password tidak sesuai.']);
        }if (DemoReset::isDemo($data['email'])) {
            app(DemoReset::class)->onDemoLogin();
        }$r->session()->regenerate();
        Audit::record('auth.login', (string) auth()->id());

        return $this->me($r);
    }

    public function me(Request $r)
    {
        $u = $r->user();

        return ['user' => $u->load('roles', 'clients', 'warehouses'), 'permissions' => $u->permissions(), 'super_admin' => $u->hasRole('Super Admin')];
    }

    public function logout(Request $r)
    {
        Audit::record('auth.logout', (string) auth()->id());
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->noContent();
    }
}
