<?php

namespace App\Http\Controllers;

use App\Integrations\BlanccoReports;
use App\Integrations\ReportSummary;
use App\Models\Device;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function index(Device $device, BlanccoReports $cloud, ReportSummary $summary): View
    {
        $reports = [];
        $error = null;
        try {
            $reports = $cloud->forSerial($device->serial_original);
        } catch (\Throwable $e) {
            $error = 'Nie udało się pobrać pełnej listy raportów. Sprawdź konfigurację i dostępność Blancco. Lokalne zlecenia pozostają dostępne.';
        }

        $latest = $summary->latest($reports);
        $sections = $latest ? $summary->sections($latest) : [];

        return view('reports.index', compact('device', 'reports', 'error', 'latest', 'sections'));
    }

    public function pdf(string $uuid, BlanccoReports $cloud): Response|RedirectResponse
    {
        abort_unless(Str::isUuid($uuid), 404);
        try {
            $body = $cloud->pdf($uuid);
        } catch (\Throwable $e) {
            return back()->withErrors(['report' => 'Nie udało się pobrać raportu PDF z Blancco.']);
        }

        return response($body, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="blancco-'.$uuid.'.pdf"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
