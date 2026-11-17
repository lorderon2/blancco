<?php

namespace App\Console\Commands;

use App\Integrations\DepartmentOrders;
use App\Integrations\SynchronizeAssignments;
use App\Models\Order;
use App\Models\SyncRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncAssignments extends Command
{
    protected $signature = 'blancco:sync {source : bc lub departments}';

    protected $description = 'Synchronizuje skonfigurowane źródło; nie łączy się z wyłączonymi integracjami';

    public function handle(SynchronizeAssignments $bc, DepartmentOrders $departments): int
    {
        $source = $this->argument('source');
        if (! in_array($source, ['bc', 'departments'], true)) {
            $this->error('Nieznane źródło.');

            return self::FAILURE;
        }
        if (! config('blancco.sync_enabled') || ! config('blancco.'.($source === 'bc' ? 'bc' : 'departments').'.enabled') || ! config('blancco.import_from')) {
            $this->warn('Synchronizacja wyłączona lub nie ustawiono BLANCCO_IMPORT_FROM.');

            return self::FAILURE;
        }
        $lock = Cache::lock('blancco:sync:'.$source, 600);
        if (! $lock->get()) {
            $this->warn('Synchronizacja tego źródła już trwa.');

            return self::FAILURE;
        }
        $run = SyncRun::create(['source' => $source, 'state' => 'running', 'started_at' => now()]);
        try {
            $count = 0;
            if ($source === 'departments') {
                $count = $departments->sync();
            } else {
                Order::whereIn('source', ['idosell', 'document'])->where('created_at', '>=', now()->subDays(3))->orderBy('id')->chunkById(50, function ($orders) use ($bc, &$count) {
                    $bc->preload($orders->pluck('document_number')->all());
                    foreach ($orders as $order) {
                        $count += $bc->document($order);
                    }
                });
            }
            $run->update(['state' => $bc->inventoryFailures > 0 ? 'degraded' : 'succeeded', 'finished_at' => now(), 'counts' => ['processed' => $count, 'inventory_unavailable' => $bc->inventoryFailures], 'safe_error' => $bc->inventoryFailures > 0 ? 'Część danych AIKEN jest niedostępna. Zlecenia wymagające COA pozostają zablokowane.' : null]);
            $this->info('Przetworzono: '.$count);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $run->update(['state' => 'failed', 'finished_at' => now(), 'safe_error' => 'Synchronizacja nie powiodła się. Sprawdź dostępność i konfigurację źródła.', 'counts' => ['processed' => $count ?? 0]]);
            $this->error('Błąd synchronizacji. Szczegóły połączenia i dane licencji nie są logowane.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
