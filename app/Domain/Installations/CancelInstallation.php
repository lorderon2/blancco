<?php

namespace App\Domain\Installations;

use App\Models\Device;
use App\Models\InstallationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelInstallation
{
    public function cancel(InstallationJob $job, string $reason): void
    {
        DB::transaction(function () use ($job, $reason) {
            $device = Device::query()->lockForUpdate()->findOrFail($job->assignment->device_id);
            $current = InstallationJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($device->executionLock()->exists()) {
                throw ValidationException::withMessages(['reason' => 'Najpierw rozstrzygnij trwającą próbę instalacji.']);
            }
            if ($current->cancelled_at) {
                return;
            }
            $current->update(['cancelled_at' => now(), 'lifecycle_status' => 'cancelled', 'cancellation_reason' => $reason]);
            Audit::record('installation_job', $current->id, 'cancelled', ['reason' => $reason]);
        }, 3);
    }
}
