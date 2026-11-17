<?php

namespace App\Console\Commands;

use App\Models\InstallationJob;
use Illuminate\Console\Command;

class ExpireInstallations extends Command
{
    protected $signature = 'blancco:expire';

    protected $description = 'Aktualizuje widoczne statusy wygasłych zleceń; API zawsze sprawdza daty';

    public function handle(): int
    {
        $count = InstallationJob::whereNull('cancelled_at')->whereIn('lifecycle_status', ['open', 'completed'])
            ->where(fn ($q) => $q->where('first_valid_until', '<=', now())->orWhere(fn ($q) => $q->whereNotNull('first_success_at')->where('retry_until', '<=', now())))
            ->update(['lifecycle_status' => 'expired']);
        $this->info('Wygaszono: '.$count);

        return self::SUCCESS;
    }
}
