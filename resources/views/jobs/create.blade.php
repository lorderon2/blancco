@extends('layout')
@section('title', 'Nowe zlecenie')
@section('content')
<div class="page-head"><div><div class="eyebrow">PRZYGOTOWANIE</div><h1>Nowe zlecenie</h1><p class="muted">Wpisz seriale lub pobierz je z BC po numerze dokumentu. Wszystkie otrzymają tę samą konfigurację.</p></div></div>
<section class="card narrow"><form method="post" action="{{ route('jobs.store') }}">@csrf<div class="grid"><label>Dokument / oznaczenie zlecenia<input name="document_number" value="{{ old('document_number') }}" maxlength="120" required><small>Ten sam dokument i serial nie tworzą kolejnego zlecenia.</small></label><label>Rodzaj<select name="source"><option value="manual" @selected(old('source')==='manual')>Ręczne</option><option value="department" @selected(old('source')==='department')>Oddziałowe</option></select></label></div><div class="actions"><button type="button" id="fetch-bc">Pobierz S/N z BC</button><span id="bc-status" role="status"></span></div><label>Numery seryjne<textarea name="serials" rows="6" placeholder="Jeden numer w wierszu" required>{{ old('serials') }}</textarea><small>Do 100 urządzeń. Numery Lenovo pozostają bez zmian.</small></label><div class="grid"><label>System<select name="windows_version">@foreach($windows as $windowsVersion)<option value="{{ $windowsVersion }}" @selected(old('windows_version')===$windowsVersion)>{{ str_replace('_', ' ', $windowsVersion) }}</option>@endforeach</select></label><label>Kraj<select name="country">@foreach($countries as $country)<option @selected(old('country', 'Polska')===$country)>{{ $country }}</option>@endforeach</select></label></div><label class="check"><input type="checkbox" name="use_dpk" value="1" @checked(old('use_dpk'))> Instalacja z DPK (dla oddziałów obowiązują reguły źródła)</label><div class="notice">Zlecenie jest ważne do końca trzeciego dnia, licząc dzisiaj. Po sukcesie ponowienie jest możliwe tylko tego samego dnia.</div><div class="actions"><button class="primary">Zapisz zlecenie</button><a href="{{ route('jobs.index') }}">Anuluj dodawanie</a></div></form></section>
<script>
const button = document.getElementById('fetch-bc');
button.addEventListener('click', async () => {
    const form = button.closest('form');
    const status = document.getElementById('bc-status');
    const documentNumber = form.elements.document_number.value.trim();
    if (!documentNumber) { status.textContent = 'Wpisz numer dokumentu.'; return; }
    if (form.elements.serials.value.trim() && !window.confirm('Zastąpić obecną listę serialami pobranymi z BC?')) return;
    button.disabled = true;
    status.textContent = 'Pobieranie z BC…';
    try {
        const response = await fetch(@json(route('jobs.bc-serials')), {
            method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': form.elements._token.value},
            body: JSON.stringify({document_number: documentNumber})
        });
        const data = await response.json();
        status.textContent = data.message || 'Nie udało się pobrać seriali.';
        if (response.ok && data.serials.length) form.elements.serials.value = data.serials.join('\n');
    } catch { status.textContent = 'Brak połączenia. Spróbuj ponownie.'; }
    finally { button.disabled = false; }
});
</script>
@endsection
