<?php

namespace App\Integrations;

use App\Domain\Installations\ImportAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DepartmentOrders
{
    public function sync(): int
    {
        if (! config('blancco.departments.enabled') || ! config('blancco.import_from')) {
            throw new \RuntimeException('DEPARTMENTS_NOT_CONFIGURED');
        }
        $scope = 'created';
        $cursor = DB::table('sync_cursors')->where('source', 'departments')->where('scope', $scope)->first();
        $saved = $cursor ? json_decode($cursor->cursor, true) : null;
        $start = max(CarbonImmutable::parse(config('blancco.import_from'), 'Europe/Warsaw')->timestamp, (int) ($saved['created'] ?? 0) - 86400);
        $count = 0;
        $last = $start;
        DB::connection(config('blancco.departments.connection'))->table('orders as o')->join('shop as s', 'o.shop', '=', 's.id')
            ->select(['o.id', 'o.serial', 'o.name as product_name', 'o.os', 's.name as shop_name', 'o.created'])
            ->where('o.created', '>=', $start)->whereNotNull('o.serial')->where('o.serial', '!=', '')->orderBy('o.created')->orderBy('o.id')
            ->chunk(100, function ($rows) use (&$count, &$last) {
                foreach ($rows as $row) {
                    if (! isset($row->os) || trim($row->os) === '' || mb_strtolower(trim($row->os)) === 'brak') {
                        continue;
                    }
                    $windows = str_replace(' ', '_', trim($row->os));
                    $job = app(ImportAssignment::class)->import(['source' => 'department', 'external_id' => (string) $row->id,
                        'document_number' => $row->shop_name.'_'.$row->id, 'serial' => $row->serial, 'windows_version' => $windows,
                        'country' => 'Polska', 'use_dpk' => false, 'assigned_at' => CarbonImmutable::createFromTimestamp((int) $row->created, 'UTC')]);
                    $job->assignment->order->update(['windows_version' => $windows, 'country' => 'Polska']);
                    $job->assignment->device->update(['description' => $row->product_name]);
                    $last = max($last, (int) $row->created);
                    $count++;
                }
            });
        DB::table('sync_cursors')->updateOrInsert(['source' => 'departments', 'scope' => $scope], ['cursor' => json_encode(['created' => $last]), 'last_success_at' => now()]);

        return $count;
    }
}
