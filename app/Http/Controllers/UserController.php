<?php

namespace App\Http\Controllers;

use App\Domain\Installations\Audit;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', ['users' => User::orderBy('name')->paginate(30)]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::min(12)], 'role' => 'required|in:admin,user']);
        DB::transaction(function () use ($data) {
            $user = User::create($data);
            Audit::record('user', $user->id, 'created', ['role' => $user->role]);
        });

        return back()->with('status', 'Użytkownik został utworzony.');
    }
}
