<?php

namespace App\Console\Commands;

use App\Integrations\IdosellOrders;
use App\Models\SyncRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncIdosell extends Command
{
    protected $signature = 'blancco:sync-idosell';

    protected $description = 'Pobiera zamówienia IdoSell od jawnej daty startu';

    public function handle(IdosellOrders $source): int
    {
        if (! config('blancco.sync_enabled') || ! config('blancco.idosell.enabled')) {
            $this->warn('Integracja IdoSell jest wyłączona.');

            return self::FAILURE;
        }
        $lock = Cache::lock('blancco:sync:idosell', 600);
        if (! $lock->get()) {
            $this->warn('Synchronizacja IdoSell już trwa.');

            return self::FAILURE;
        }
        $run = SyncRun::create(['source' => 'idosell', 'state' => 'running', 'started_at' => now()]);
        try {
            $count = $source->sync();
            $run->update(['state' => 'succeeded', 'finished_at' => now(), 'counts' => ['processed' => $count]]);
            $this->info('Przetworzono: '.$count);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $run->update(['state' => 'failed', 'finished_at' => now(), 'safe_error' => 'Nie udało się pobrać zamówień. Sprawdź konfigurację, mapowanie produktów i dostępność IdoSell.']);
            $this->error('Nie udało się pobrać zamówień.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
