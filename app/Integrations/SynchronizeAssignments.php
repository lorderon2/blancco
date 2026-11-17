<?php

namespace App\Integrations;

use App\Domain\Installations\ImportAssignment;
use App\Domain\Installations\LegacyConfiguration;
use App\Models\Order;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class SynchronizeAssignments
{
    public int $inventoryFailures = 0;

    public function __construct(private BcLedger $bc, private AikenInventory $aiken, private ImportAssignment $importer) {}

    public function document(Order $order): int
    {
        $rows = $this->bc->assignments($order->document_number);
        $count = 0;
        foreach ($rows as $row) {
            $inventoryError = false;
            try {
                $inventory = $this->aiken->device($row['serial'], $row['item_number']);
            } catch (\RuntimeException|\PDOException $e) {
                $this->inventoryFailures++;
                $inventoryError = true;
                $inventory = ['model' => null, 'coa' => null, 'source' => 'unavailable'];
            }
            $required = app(LegacyConfiguration::class)->resolve($order->source, $order->windows_version, $order->country, (bool) ($order->source_data['use_dpk'] ?? true));
            $blockedReason = $order->source_data['preparation_reason'] ?? null;
            if ($inventoryError && $required['requires_coa']) {
                $blockedReason ??= 'Nie udało się sprawdzić wymaganego COA: AIKEN jest niedostępny.';
            }
            DB::transaction(function () use ($row, $order, $inventory, $inventoryError, $blockedReason) {
                $job = $this->importer->import(['source' => $order->source, 'external_id' => $order->external_id, 'document_number' => $order->document_number,
                    'serial' => $row['serial'], 'windows_version' => $order->windows_version, 'country' => $order->country,
                    'assigned_at' => $row['assigned_at'], 'source_sequence' => $row['source_sequence'],
                    'blocked_reason' => $blockedReason, 'use_dpk' => (bool) ($order->source_data['use_dpk'] ?? true), 'has_coa' => ! empty($inventory['coa'])]);
                $device = $job->assignment->device;
                $device->update(['description' => $row['description'], 'item_number' => $row['item_number'], 'model' => $inventoryError ? $device->model : $inventory['model']]);
                if ($inventory['coa']) {
                    DB::table('device_license_data')->updateOrInsert(['device_id' => $device->id, 'source' => $inventory['source'], 'kind' => 'coa'],
                        ['encrypted_value' => Crypt::encryptString($inventory['coa']), 'checked_at' => now()]);
                }
            }, 3);
            $count++;
        }

        // Absence is not automatically interpreted as withdrawal until the live source contract is verified.
        return $count;
    }
}
