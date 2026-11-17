<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email']);
        Password::sendResetLink($data + ['active' => true]);

        return back()->with('status', 'Jeśli aktywne konto istnieje, wysłaliśmy wiadomość z linkiem do zmiany hasła.');
    }

    public function reset(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->string('email')->value()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => 'required|string', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(12)]]);
        $status = Password::reset($data + ['active' => true], function ($user, $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'Link jest nieprawidłowy lub wygasł. Poproś o nowy link.']);
        }

        return redirect()->route('login')->with('status', 'Hasło zostało zmienione. Zaloguj się ponownie.');
    }
}
