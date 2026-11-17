<?php

namespace App\Console\Commands;

use App\Integrations\BitrixOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchBitrix extends Command
{
    protected $signature = 'blancco:dispatch-bitrix';

    protected $description = 'Wysyła jawnie zakolejkowane operacje Bitrix bez automatycznego ponawiania niepewnych zapisów';

    public function handle(BitrixOutbox $outbox): int
    {
        if (! config('blancco.bitrix.enabled')) {
            $this->warn('Integracja Bitrix jest wyłączona.');

            return self::FAILURE;
        }
        DB::table('integration_outbox')->where('destination', 'bitrix')->where('state', 'pending')->orderBy('id')->limit(100)->get()->each(fn ($row) => $outbox->dispatch($row->id));
        $this->info('Przetworzono kolejkę. Niepewne wyniki wymagają sprawdzenia.');

        return self::SUCCESS;
    }
}
