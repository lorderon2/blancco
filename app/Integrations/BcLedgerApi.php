<?php

namespace App\Integrations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

class BcLedgerApi
{
    private ?string $token = null;

    private int $expiresAt = 0;

    private function token(): string
    {
        if ($this->token !== null && time() < $this->expiresAt) {
            return $this->token;
        }
        foreach (['token_url', 'client_id', 'client_secret', 'base_url'] as $key) {
            if (! config('blancco.bc.api.'.$key)) {
                throw new \RuntimeException('BC_API_NOT_CONFIGURED');
            }
        }
        $response = Http::asForm()->connectTimeout(5)->timeout(20)->withoutRedirecting()->post(config('blancco.bc.api.token_url'), [
            'grant_type' => 'client_credentials', 'client_id' => config('blancco.bc.api.client_id'),
            'client_secret' => config('blancco.bc.api.client_secret'), 'scope' => 'https://api.businesscentral.dynamics.com/.default',
        ])->throw()->json();
        if (! is_array($response) || ! is_string($response['access_token'] ?? null) || $response['access_token'] === '') {
            throw new \RuntimeException('BC_API_INVALID_TOKEN');
        }
        $this->token = $response['access_token'];
        $this->expiresAt = time() + max(1, min((int) ($response['expires_in'] ?? 300), 3600) - 60);

        return $this->token;
    }

    public function assignments(array $documents): array
    {
        $documents = array_values(array_unique(array_filter(array_map('trim', $documents))));
        $result = array_fill_keys($documents, []);
        $base = rtrim((string) config('blancco.bc.api.base_url'), '/');
        foreach (array_chunk($documents, 20) as $batch) {
            $filter = '('.implode(' or ', array_map(fn ($number) => "Document_No eq '".str_replace("'", "''", $number)."'", $batch)).')';
            $next = $base.'/ILE';
            $seen = [];
            for ($page = 0; $next !== null; $page++) {
                if ($page >= 100 || isset($seen[$next])) {
                    throw new \RuntimeException('BC_API_PAGE_LIMIT');
                }
                if (! str_starts_with($next, $base.'/ILE')) {
                    throw new \RuntimeException('BC_API_UNTRUSTED_NEXT_LINK');
                }
                $seen[$next] = true;
                $query = $page === 0 ? ['$filter' => $filter, '$select' => 'Document_No,Serial_No,Description,Item_Description,Item_No,Entry_Type,Quantity,Entry_No,N24C_SystemCreatedAt', '$top' => 1000] : [];
                $body = Http::withToken($this->token())->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()->get($next, $query)->throw()->json();
                if (! is_array($body) || ! isset($body['value']) || ! is_array($body['value'])) {
                    throw new \RuntimeException('BC_API_INVALID_RESPONSE');
                }
                foreach ($body['value'] as $row) {
                    $document = $row['Document_No'] ?? '';
                    if (! in_array($document, $batch, true)) {
                        continue;
                    }
                    $type = strtoupper(trim((string) ($row['Entry_Type'] ?? '')));
                    $item = (string) ($row['Item_No'] ?? '');
                    if (! in_array($type, ['4', 'TRANSFER'], true) || ! is_numeric($row['Quantity'] ?? null) || (float) $row['Quantity'] !== 1.0 || ! preg_match('/PC|NOT|AIO/i', $item)) {
                        continue;
                    }
                    $serial = trim((string) ($row['Serial_No'] ?? ''));
                    if ($serial === '') {
                        continue;
                    }
                    if (! is_numeric($row['Entry_No'] ?? null) || (int) $row['Entry_No'] <= 0 || ! is_string($row['N24C_SystemCreatedAt'] ?? null) || $row['N24C_SystemCreatedAt'] === '') {
                        throw new \RuntimeException('BC_ASSIGNMENT_CHRONOLOGY_MISSING');
                    }
                    $at = CarbonImmutable::parse($row['N24C_SystemCreatedAt'])->utc();
                    if ($at->year < 2000) {
                        throw new \RuntimeException('BC_ASSIGNMENT_CHRONOLOGY_INVALID');
                    }
                    $result[$document][] = ['serial' => $serial, 'item_number' => $item, 'description' => (string) ($row['Description'] ?? $row['Item_Description'] ?? ''),
                        'assigned_at' => $at, 'source_sequence' => (int) $row['Entry_No']];
                }
                $next = $body['@odata.nextLink'] ?? null;
                if ($next !== null && ! is_string($next)) {
                    throw new \RuntimeException('BC_API_INVALID_NEXT_LINK');
                }
            }
        }

        return $result;
    }
}
