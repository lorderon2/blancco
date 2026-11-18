@extends('layout')
@section('title', 'Raporty urządzenia')
@section('content')
<div class="page-head"><div><a href="{{ route('jobs.index', ['q'=>$device->serial_original]) }}">← Zlecenia urządzenia</a><h1>Raporty Blancco</h1><p class="muted">{{ $device->serial_original }} · Pełna dostępna historia dla tego serialu, bez ograniczenia do ostatniego miesiąca.</p></div></div>
@if($error)<div class="notice error" role="alert">{{ $error }}</div>@else
@if($latest)
<section class="card report-summary">
    <div class="page-head report-summary-header"><div><div class="eyebrow">PODSUMOWANIE URZĄDZENIA</div><h2>{{ $latest['date'] ? 'Dane z najnowszego raportu' : 'Dane raportu — brak daty' }}</h2><p class="muted"><strong class="report-summary-model">{{ $latest['model'] ?: $device->serial_original }}</strong><span class="report-summary-date">{{ $dateLabel($latest['date']) }} · czas polski</span></p></div><a class="button secondary" href="{{ route('reports.pdf', $latest['id']) }}">Pobierz PDF najnowszego raportu</a></div>
    <div class="report-summary-grid">@foreach($sections as $title => $rows)
    <section class="report-summary-section"><h3>{{ $title }}</h3><table class="report-summary-table"><tbody>@foreach($rows as $label => $value)<tr><th scope="row">{{ $label }}</th><td>{{ $value }}</td></tr>@endforeach</tbody></table></section>
    @endforeach</div>
    <p class="muted report-summary-note">Dane pochodzą wyłącznie z tego raportu. Brakujące pola nie są uzupełniane starszymi odczytami.</p>
</section>
@endif
<section class="card"><h2>Raporty PDF i historia</h2><p class="muted">{{ count($reports) }} raportów dla tego urządzenia.</p>@forelse($reports as $report)<article class="report"><div class="page-head"><div><h2>{{ $dateLabel($report['date']) }} · {{ $report['model'] ?: $device->serial_original }}</h2><small class="mono">{{ $report['id'] }}</small></div><a class="button secondary" href="{{ route('reports.pdf',$report['id']) }}">Pobierz PDF</a></div><details><summary>Szczegóły raportu</summary><pre>{{ json_encode($report['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details></article>@empty<div class="empty"><strong>Brak raportów dla tego serialu</strong><p>Blancco nie zwróciło pasujących raportów.</p></div>@endforelse</section>@endif
@endsection
