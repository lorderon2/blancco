<?php

namespace App\Http\Controllers;

use App\Domain\Installations\Audit;
use App\Domain\Installations\LegacyConfiguration;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentController extends Controller
{
    public function index(): View
    {
        return view('documents.index', ['orders' => Order::withCount('assignments')->latest('id')->paginate(30), 'windows' => LegacyConfiguration::WINDOWS, 'countries' => array_keys(LegacyConfiguration::COUNTRIES)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['document_number' => 'required|string|max:120', 'country' => 'required|string|max:100',
            'windows_version' => 'required|in:'.implode(',', LegacyConfiguration::WINDOWS), 'use_dpk' => 'nullable|boolean']);
        DB::transaction(function () use ($data, $request) {
            $order = Order::firstOrCreate(['source' => 'document', 'external_id' => $data['document_number']], ['document_number' => $data['document_number'], 'country' => $data['country'], 'windows_version' => $data['windows_version'], 'source_data' => ['use_dpk' => $request->boolean('use_dpk')]]);
            if ($order->wasRecentlyCreated) {
                Audit::record('order', $order->id, 'requested');
            }
        });

        return back()->with('status', 'Dokument zapisano. Synchronizacja BC pobierze przypisane urządzenia po skonfigurowaniu źródła.');
    }
}
