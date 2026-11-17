<?php

namespace App\Http\Controllers;

use App\Domain\Installations\AttemptConflict;
use App\Domain\Installations\Audit;
use App\Domain\Installations\ManageAttempts;
use App\Models\InstallationAttempt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttemptController extends Controller
{
    public function resolve(Request $request, InstallationAttempt $attempt, ManageAttempts $manager): RedirectResponse
    {
        $data = $request->validate(['result' => 'required|in:succeeded,failed', 'reason' => 'required|string|min:3|max:1000']);
        try {
            DB::transaction(function () use ($attempt, $manager, $data) {
                if ($attempt->refresh()->state !== 'unknown') {
                    throw new AttemptConflict('Tę próbę już rozstrzygnięto albo nadal trwa.');
                }
                $manager->finish($attempt, $data['result'] === 'succeeded', 'OPERATOR_CONFIRMED_FAILURE');
                Audit::record('installation_attempt', $attempt->id, 'operator_resolved', ['reason' => $data['reason'], 'result' => $data['result']]);
            }, 3);
        } catch (AttemptConflict $e) {
            return back()->withErrors(['attempt' => $e->getMessage()]);
        }

        return back()->with('status', 'Zapisano wynik po sprawdzeniu urządzenia.');
    }
}
