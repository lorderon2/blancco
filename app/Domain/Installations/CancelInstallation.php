<?php

namespace App\Domain\Installations;

use App\Models\Device;
use App\Models\InstallationJob;
use Illuminate\Support\Facades\DB;

final class CancelInstallation
{
    public function cancel(InstallationJob $job, string $reason): void
    {
        DB::transaction(function () use ($job, $reason) {
            $device = Device::query()->lockForUpdate()->findOrFail($job->assignment->device_id);
            $current = InstallationJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($current->cancelled_at) {
                return;
            }
            $current->update(['cancelled_at' => now(), 'lifecycle_status' => 'cancelled', 'cancellation_reason' => $reason]);
            Audit::record('installation_job', $current->id, 'cancelled', ['reason' => $reason]);
        }, 3);
    }
}
