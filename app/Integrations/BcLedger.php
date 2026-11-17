<?php

namespace App\Integrations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BcLedger
{
    private ?array $preloaded = null;

    public function __construct(private BcLedgerApi $api) {}

    public function preload(array $documents): void
    {
        $this->preloaded = config('blancco.bc.source') === 'api' ? $this->api->assignments($documents) : null;
    }

    public function assignments(string $document): array
    {
        if (! config('blancco.bc.enabled')) {
            throw new \RuntimeException('BC_NOT_CONFIGURED');
        }
        if (config('blancco.bc.source') === 'api') {
            return $this->preloaded !== null ? ($this->preloaded[$document] ?? []) : ($this->api->assignments([$document])[$document] ?? []);
        }
        $table = config('blancco.bc.table');
        if (! config('blancco.bc.enabled') || ! $table) {
            throw new \RuntimeException('BC_NOT_CONFIGURED');
        }
        $date = config('blancco.bc.date_column');
        $sequence = config('blancco.bc.sequence_column');
        $rows = DB::connection(config('blancco.bc.connection'))->table($table)
            ->where('Document No_', $document)->where('Entry Type', 4)->where('Quantity', 1)
            ->where(fn ($q) => $q->where('Item No_', 'like', '%PC%')->orWhere('Item No_', 'like', '%NOT%')->orWhere('Item No_', 'like', '%AIO%'))
            ->orderBy($sequence)->limit(1001)->get(['Serial No_', 'Description', 'Item No_', $date, $sequence]);
        if ($rows->count() > 1000) {
            throw new \RuntimeException('BC_DOCUMENT_LIMIT');
        }

        return $rows->map(function ($row) use ($date, $sequence) {
            $data = (array) $row;
            if (empty($data[$date]) || ! isset($data[$sequence])) {
                throw new \RuntimeException('BC_ASSIGNMENT_CHRONOLOGY_MISSING');
            }

            return ['serial' => trim((string) $data['Serial No_']), 'description' => (string) $data['Description'], 'item_number' => (string) $data['Item No_'],
                'assigned_at' => CarbonImmutable::parse($data[$date], 'Europe/Warsaw')->utc(), 'source_sequence' => (int) $data[$sequence]];
        })->filter(fn ($row) => $row['serial'] !== '')->values()->all();
    }
}
