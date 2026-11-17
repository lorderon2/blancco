@extends('layout')
@section('title', 'Przypomnienie hasła')
@section('content')<section class="card login"><h1>Nie pamiętasz hasła?</h1><p class="muted">Wyślemy link do ustawienia nowego hasła.</p><form method="post" action="{{ route('password.email') }}">@csrf<label>E-mail<input name="email" type="email" autocomplete="username" required value="{{ old('email') }}"></label><button class="primary full">Wyślij link</button></form><p><a href="{{ route('login') }}">Wróć do logowania</a></p></section>@endsection
