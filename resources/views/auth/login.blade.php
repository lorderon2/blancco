@extends('layout')
@section('title', 'Logowanie')
@section('content')
<section class="login card"><div class="eyebrow">CENTRUM INSTALACJI</div><h1>Zaloguj się</h1><p class="muted">Zarządzaj zleceniami i sprawdzaj historię urządzeń.</p><form method="post" action="{{ url('/login') }}">@csrf<label>E-mail<input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label><label>Hasło<input type="password" name="password" autocomplete="current-password" required></label><label class="check"><input type="checkbox" name="remember" value="1"> Zapamiętaj mnie</label><button class="primary full">Zaloguj</button></form><p><a href="{{ route('password.request') }}">Nie pamiętam hasła</a></p></section>
@endsection
