<?php

namespace App\Domain\Installations;

use App\Models\Device;
use App\Models\DeviceExecutionLock;
use App\Models\InstallationAttempt;
use App\Models\InstallationJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ManageAttempts
{
    public function start(Device $device, string $requestKey, string $mode, ?int $jobId = null, ?string $configurationHash = null, bool $retryConfirmed = false): InstallationAttempt
    {
        if (! in_array($mode, ['order', 'manual'], true) || ! preg_match('/^[a-zA-Z0-9_-]{16,100}$/D', $requestKey)) {
            throw new AttemptConflict('Niepoprawne dane rozpoczęcia próby.');
        }

        return DB::transaction(function () use ($device, $requestKey, $mode, $jobId, $configurationHash, $retryConfirmed) {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            if ($existing = InstallationAttempt::where('request_key', $requestKey)->first()) {
                if ($existing->device_id !== $device->id || $existing->mode !== $mode || $existing->job_id !== $jobId || ($mode === 'order' && ($existing->config_snapshot['configurationVersion'] ?? null) !== $configurationHash)) {
                    throw new AttemptConflict('Klucz żądania był już użyty dla innej próby.');
                }

                return $existing;
            }
            $job = null;
            $snapshot = ['mode' => $mode];
            if ($mode === 'order') {
                $selection = app(SelectInstallation::class)->forDevice($device);
                if (! in_array($selection->decision, [Decision::Auto, Decision::AskRetry], true) || $selection->job?->id !== $jobId || $selection->job?->configuration_hash !== $configurationHash) {
                    throw new AttemptConflict('Zlecenie lub konfiguracja uległy zmianie. Pobierz dane ponownie.');
                }
                if ($selection->decision === Decision::AskRetry && ! $retryConfirmed) {
                    throw new AttemptConflict('Wymagane potwierdzenie ponownej instalacji.');
                }
                $job = InstallationJob::query()->lockForUpdate()->findOrFail($jobId);
                $snapshot += ['imageName' => $job->image_name_snapshot, 'useDpk' => $job->use_dpk, 'configurationVersion' => $job->configuration_hash];
            } elseif ($jobId !== null) {
                throw new AttemptConflict('Próba ręczna nie może realizować zlecenia.');
            }
            foreach (InstallationAttempt::where('device_id', $device->id)->whereIn('state', ['started', 'unknown'])->lockForUpdate()->get() as $previous) {
                $previous->update(['state' => 'failed', 'finished_at' => now(), 'failure_code' => 'RESTARTED_BY_OPERATOR']);
                Audit::record('installation_attempt', $previous->id, 'superseded');
            }
            DeviceExecutionLock::where('device_id', $device->id)->delete();
            $attempt = new InstallationAttempt(['device_id' => $device->id, 'job_id' => $job?->id, 'mode' => $mode,
                'request_key' => $requestKey, 'state' => 'started', 'started_at' => now(), 'config_snapshot' => $snapshot]);
            $attempt->id = (string) Str::uuid();
            $attempt->save();
            DeviceExecutionLock::create(['device_id' => $device->id, 'attempt_id' => $attempt->id, 'acquired_at' => now(), 'timeout_at' => now()->addHours(4)]);
            Audit::record('installation_attempt', $attempt->id, 'started', ['job_id' => $job?->id]);

            return $attempt;
        }, 3);
    }

    public function finish(InstallationAttempt $attempt, bool $success, ?string $failureCode = null): InstallationAttempt
    {
        return DB::transaction(function () use ($attempt, $success, $failureCode) {
            Device::query()->lockForUpdate()->findOrFail($attempt->device_id);
            $attempt = InstallationAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $state = $success ? 'succeeded' : 'failed';
            if (in_array($attempt->state, ['succeeded', 'failed'], true)) {
                if ($attempt->state !== $state) {
                    throw new AttemptConflict('Próba ma już inny wynik.');
                }

                return $attempt;
            }
            $attempt->update(['state' => $state, 'finished_at' => now(), 'failure_code' => $success ? null : $failureCode]);
            if ($success && $attempt->job_id) {
                $job = InstallationJob::query()->lockForUpdate()->findOrFail($attempt->job_id);
                $updates = ['last_success_at' => now()];
                if (! $job->first_success_at) {
                    $updates += ['first_success_at' => now(), 'retry_until' => Validity::retryDeadline(CarbonImmutable::now('UTC'))->min($job->first_valid_until)];
                }
                if (! $job->cancelled_at) {
                    $updates['lifecycle_status'] = 'completed';
                }
                $job->update($updates);
            }
            DeviceExecutionLock::where('device_id', $attempt->device_id)->where('attempt_id', $attempt->id)->delete();
            Audit::record('installation_attempt', $attempt->id, $state);

            return $attempt->refresh();
        }, 3);
    }
}
