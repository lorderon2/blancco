<?php

namespace App\Domain\Installations;

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\InstallationJob;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ImportAssignment
{
    public function import(array $data): InstallationJob
    {
        return DB::transaction(function () use ($data) {
            $serial = trim($data['serial']);
            if ($serial === '' || mb_strlen($serial) > 255) {
                throw new \InvalidArgumentException('Niepoprawny numer seryjny.');
            }
            $order = Order::firstOrCreate(['source' => $data['source'], 'external_id' => $data['external_id']], [
                'document_number' => $data['document_number'], 'country' => $data['country'], 'windows_version' => $data['windows_version'],
                'source_created_at' => $data['assigned_at'], 'source_data' => $data['source_data'] ?? ['use_dpk' => (bool) $data['use_dpk']],
            ]);
            if ($data['source'] === 'manual' && ! $order->wasRecentlyCreated &&
                ($order->windows_version !== $data['windows_version'] || $order->country !== $data['country'] || (bool) ($order->source_data['use_dpk'] ?? false) !== (bool) $data['use_dpk'])) {
                throw ValidationException::withMessages(['windows_version' => 'Ten dokument ma już inną konfigurację. Użyj nowego oznaczenia dokumentu, aby nie mieszać systemów w jednym zamówieniu.']);
            }
            $device = Device::firstOrCreate(['serial_lookup' => $serial], ['serial_original' => $serial]);
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            $assignment = DeviceAssignment::firstOrCreate(['order_id' => $order->id, 'device_id' => $device->id], [
                'source_assignment_key' => hash('sha256', 'assignment-v2|'.$order->id.'|'.$device->id),
                'source_assigned_at' => CarbonImmutable::parse($data['assigned_at'])->utc(), 'source_sequence' => $data['source_sequence'] ?? null, 'first_seen_at' => now(),
            ]);
            $existing = $assignment->jobs()->latest('revision')->first();
            // Existing job keeps its identity, deadlines and cancellation, regardless of reimport.
            if ($existing && ($existing->first_success_at || $existing->cancelled_at || $device->executionLock()->exists())) {
                return $existing;
            }
            $config = app(LegacyConfiguration::class)->resolve($data['source'], $data['windows_version'], $data['country'], (bool) $data['use_dpk'], (bool) ($data['has_coa'] ?? false));
            if (! empty($data['blocked_reason'])) {
                $config['preparation_status'] = 'blocked';
                $config['blocked_reason'] = $data['blocked_reason'];
            }
            $fields = array_intersect_key($config, array_flip(['image_name_snapshot', 'use_dpk', 'preparation_status', 'blocked_reason', 'rule_version']));
            $fields['configuration_hash'] = hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
            if ($existing) {
                if ($existing->configuration_hash !== $fields['configuration_hash']) {
                    $existing->update($fields);
                    Audit::record('installation_job', $existing->id, 'configuration_updated');
                }

                return $existing->refresh();
            }
            $job = $assignment->jobs()->create($fields + ['source_type' => $data['source'], 'revision' => 1,
                'first_valid_until' => Validity::firstDeadline(CarbonImmutable::now('UTC')), 'created_by' => auth()->id()]);
            Audit::record('installation_job', $job->id, 'created', ['source' => $data['source']]);

            return $job;
        }, 3);
    }
}
