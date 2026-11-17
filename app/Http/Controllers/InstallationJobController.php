<?php

namespace App\Http\Controllers;

use App\Domain\Installations\CancelInstallation;
use App\Domain\Installations\ImportAssignment;
use App\Domain\Installations\LegacyConfiguration;
use App\Domain\Installations\SelectInstallation;
use App\Integrations\BcLedger;
use App\Models\AuditEvent;
use App\Models\InstallationJob;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstallationJobController extends Controller
{
    public function index(Request $request): View
    {
        $query = InstallationJob::with('assignment.order', 'assignment.device');
        if ($s = $request->string('q')->trim()->value()) {
            $query->whereHas('assignment', fn ($a) => $a->where(fn ($q) => $q->whereHas('device', fn ($d) => $d->where('serial_lookup', 'like', '%'.$s.'%'))->orWhereHas('order', fn ($o) => $o->where('document_number', 'like', '%'.$s.'%'))));
        }
        $state = $request->get('state');
        if ($state === 'expired') {
            $query->expiredAt(now());
        } elseif ($state === 'cancelled') {
            $query->where(fn ($q) => $q->whereNotNull('cancelled_at')->orWhere('lifecycle_status', 'cancelled'));
        } elseif (in_array($state, ['open', 'completed'], true)) {
            $query->whereNull('cancelled_at')->where('lifecycle_status', '!=', 'cancelled')->where('first_valid_until', '>', now());
            if ($state === 'open') {
                $query->whereNull('first_success_at');
            } else {
                $query->whereNotNull('first_success_at')->where('retry_until', '>', now());
            }
        }
        if (in_array($request->get('preparation'), ['pending', 'ready', 'blocked'], true)) {
            $query->where('preparation_status', $request->get('preparation'));
        }
        if (in_array($request->get('source'), ['idosell', 'document', 'manual', 'department'], true)) {
            $query->where('source_type', $request->get('source'));
        }

        return view('jobs.index', ['jobs' => $query->latest('id')->paginate(30)->withQueryString(),
            'outbox' => DB::table('integration_outbox')->latest('id')->limit(20)->get(),
            'total' => InstallationJob::count(), 'pending' => InstallationJob::where('preparation_status', 'pending')->count(),
            'blocked' => InstallationJob::where('preparation_status', 'blocked')->count(), 'runs' => SyncRun::latest('id')->limit(5)->get()]);
    }

    public function create(): View
    {
        return view('jobs.create', ['windows' => LegacyConfiguration::WINDOWS, 'countries' => array_keys(LegacyConfiguration::COUNTRIES)]);
    }

    public function bcSerials(Request $request, BcLedger $ledger): JsonResponse
    {
        $data = $request->validate(['document_number' => 'required|string|max:120']);
        try {
            $serials = array_values(array_unique(array_column($ledger->assignments(trim($data['document_number'])), 'serial')));
        } catch (\Throwable) {
            return response()->json(['message' => 'Nie udało się pobrać seriali z BC. Spróbuj ponownie lub wpisz je ręcznie.'], 502);
        }
        if (count($serials) > 100) {
            return response()->json(['message' => 'Dokument zawiera ponad 100 urządzeń. Podziel je na mniejsze zlecenia.'], 422);
        }

        return response()->json(['serials' => $serials, 'message' => $serials === [] ? 'Brak przypisanych urządzeń w BC dla tego dokumentu.' : 'Pobrano '.count($serials).' seriali. Sprawdź konfigurację i zapisz zlecenie.']);
    }

    public function store(Request $request, ImportAssignment $importer): RedirectResponse
    {
        $data = $request->validate(['serials' => 'required|string|max:10000', 'document_number' => 'required|string|max:120',
            'country' => 'required|string|max:100', 'windows_version' => 'required|in:'.implode(',', LegacyConfiguration::WINDOWS),
            'source' => 'required|in:manual,department', 'use_dpk' => 'nullable|boolean']);
        $serials = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $data['serials'])))));
        if (count($serials) > 100 || count($serials) === 0 || collect($serials)->contains(fn ($s) => mb_strlen($s) > 255)) {
            return back()->withInput()->withErrors(['serials' => 'Podaj od 1 do 100 numerów, maksymalnie 255 znaków każdy.']);
        }
        $ids = DB::transaction(function () use ($serials, $data, $request, $importer) {
            $ids = [];
            foreach ($serials as $serial) {
                $job = $importer->import(['source' => $data['source'], 'external_id' => $data['document_number'], 'document_number' => $data['document_number'],
                    'serial' => $serial, 'country' => $data['country'], 'windows_version' => $data['windows_version'], 'use_dpk' => $request->boolean('use_dpk'),
                    'assigned_at' => CarbonImmutable::now('UTC')]);
                $ids[] = $job->id;
            }

            return $ids;
        });

        return redirect()->route('jobs.show', $ids[0])->with('status', 'Zapisano zlecenia dla '.count($ids).' urządzeń. Ponowne dodanie tego samego dokumentu i serialu nie odnawia terminu.');
    }

    public function show(InstallationJob $job, SelectInstallation $selector): View
    {
        $job->load('assignment.order.items', 'assignment.device', 'attempts');

        return view('jobs.show', ['job' => $job, 'selection' => $selector->forDevice($job->assignment->device),
            'events' => AuditEvent::where('entity_type', 'installation_job')->where('entity_id', (string) $job->id)->latest('id')->get()]);
    }

    public function cancel(Request $request, InstallationJob $job, CancelInstallation $service): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        $service->cancel($job, $data['reason']);

        return back()->with('status', 'Zlecenie anulowano. Workflow może wybrać starsze, nadal ważne zlecenie.');
    }
}
