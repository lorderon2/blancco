<?php

namespace App\Integrations;

use Illuminate\Support\Facades\DB;

class AikenInventory
{
    public function device(string $serial, string $item): array
    {
        if (! config('blancco.aiken.enabled')) {
            throw new \RuntimeException('AIKEN_NOT_CONFIGURED');
        }
        $connection = str_contains(strtoupper($item), 'NOT') ? 'aiken_lap' : 'aiken_pc';
        $db = DB::connection($connection);
        $units = $db->table('Units')->where('SerialNumber', $serial)->limit(2)->get(['UnitID', 'PartNumber']);
        if ($units->count() > 1) {
            throw new \RuntimeException('AIKEN_AMBIGUOUS_SERIAL');
        }
        if ($units->isEmpty()) {
            return ['model' => null, 'coa' => null, 'source' => $connection];
        }
        $parts = $db->table('Units_Devices')->where('UnitId', $units[0]->UnitID)->whereIn('Category', ['COA', 'Board'])->get(['Category', 'Serial', 'Model']);
        $coa = $parts->where('Category', 'COA')->pluck('Serial')->filter(fn ($s) => is_string($s) && trim($s) !== '')->unique()->values();
        if ($coa->count() > 1) {
            throw new \RuntimeException('AIKEN_AMBIGUOUS_COA');
        }

        return ['model' => $parts->firstWhere('Category', 'Board')?->Model, 'coa' => $coa->first(), 'source' => $connection];
    }
}
