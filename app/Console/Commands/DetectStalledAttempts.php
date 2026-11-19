<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DeviceExecutionLock;
use App\Models\InstallationAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DetectStalledAttempts extends Command
{
    protected $signature = 'blancco:detect-stalled';

    protected $description = 'Oznacza przeterminowane próby jako nierozstrzygnięte bez blokowania kolejnego uruchomienia';

    public function handle(): int
    {
        $count = 0;
        DeviceExecutionLock::where('timeout_at', '<=', now())->orderBy('device_id')->chunkById(100, function ($locks) use (&$count) {
            foreach ($locks as $lock) {
                DB::transaction(function () use ($lock, &$count) {
                    Device::query()->lockForUpdate()->findOrFail($lock->device_id);
                    $attempt = InstallationAttempt::query()->lockForUpdate()->find($lock->attempt_id);
                    if ($attempt?->state === 'started') {
                        $attempt->update(['state' => 'unknown']);
                        $count++;
                    }
                }, 3);
            }
        }, 'device_id');
        $this->info('Próby wymagające sprawdzenia: '.$count);

        return self::SUCCESS;
    }
}
