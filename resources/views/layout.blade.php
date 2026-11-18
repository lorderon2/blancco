<!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>@yield('title', 'Zlecenia') · Blancco</title><link rel="stylesheet" href="{{ asset('app.css') }}?v={{ filemtime(public_path('app.css')) }}"></head>
<body><header><a class="brand" href="{{ url('/') }}"><span class="brand-icon">b</span> Blancco <small>Centrum instalacji</small></a>@auth<nav><a href="{{ route('jobs.index') }}">Zlecenia</a><a href="{{ route('jobs.create') }}">Nowe zlecenie</a><a href="{{ route('users.index') }}">Użytkownicy</a></nav><div class="account"><span>{{ auth()->user()->name }}</span><form method="post" action="{{ route('logout') }}">@csrf<button class="link">Wyloguj</button></form></div>@endauth</header>
<main>@if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice error" role="alert"><strong>Sprawdź dane</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')</main><footer>Blancco · Przygotowanie i historia instalacji <span>Czas lokalny: Europe/Warsaw</span></footer></body></html>
