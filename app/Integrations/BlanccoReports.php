<?php

namespace App\Integrations;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class BlanccoReports
{
    private function client(): PendingRequest
    {
        if (! config('blancco.cloud.enabled') || ! config('blancco.cloud.key') || ! config('blancco.cloud.url')) {
            throw new \RuntimeException('BLANCCO_CLOUD_NOT_CONFIGURED');
        }

        return Http::withHeaders(['X-BLANCCO-API-KEY' => config('blancco.cloud.key')])->connectTimeout(5)->timeout(20)->withoutRedirecting();
    }

    public function forSerial(string $serial): array
    {
        $cursor = null;
        $seen = [];
        $reports = [];
        for ($page = 0; $page < 100; $page++) {
            $payload = ['format' => 'JSON', 'container' => 'NONE', 'filter' => ['date' => (object) [], 'fields' => [['name' => config('blancco.cloud.serial_field', '@system_serial'), 'like' => $serial]]]];
            $client = $this->client()->acceptJson();
            if ($cursor !== null) {
                $client = $client->withQueryParameters(['cursor' => $cursor]);
            }
            $response = $client->post(config('blancco.cloud.url'), $payload)->throw();
            if ($response->status() === 204) {
                return array_values($reports);
            }
            $body = $response->json();
            if (! is_array($body) || ! isset($body['reports']) || ! is_array($body['reports'])) {
                throw new \RuntimeException('BLANCCO_REPORT_SHAPE');
            }
            foreach ($body['reports'] as $report) {
                $data = $report['blancco_data'] ?? [];
                $system = data_get($data, 'blancco_hardware_report.system', []);
                $returnedSerial = $this->systemField($system, 'serial');
                if (! is_string($returnedSerial)) {
                    throw new \RuntimeException('BLANCCO_REPORT_SERIAL_MISSING');
                }
                if (trim($returnedSerial) !== trim($serial)) {
                    continue;
                }
                $id = data_get($data, 'description.document_id');
                if (! is_string($id) || ! preg_match('/^[a-f0-9-]{36}$/iD', $id)) {
                    throw new \RuntimeException('BLANCCO_REPORT_ID_MISSING');
                }
                $reports[$id] = ['id' => $id, 'serial' => $returnedSerial, 'date' => data_get($data, 'description.date') ?? data_get(collect(data_get($data, 'description.document_log', []))->last(), 'date'),
                    'model' => $this->systemField($system, 'model'), 'data' => $data];
            }
            $cursor = $response->header('X-BLANCCO-CURSOR');
            if (! $cursor) {
                return array_values($reports);
            }
            if (isset($seen[$cursor])) {
                throw new \RuntimeException('BLANCCO_REPEATED_CURSOR');
            }
            $seen[$cursor] = true;
        }
        throw new \RuntimeException('BLANCCO_REPORT_PAGE_LIMIT');
    }

    private function systemField(array $system, string $field): mixed
    {
        if (array_key_exists($field, $system)) {
            return $system[$field];
        }
        foreach ($system as $entry) {
            if (is_array($entry) && array_key_exists($field, $entry)) {
                return $entry[$field];
            }
        }

        return null;
    }

    public function pdf(string $uuid): string
    {
        $template = config('blancco.cloud.report_url');
        if (! $template || ! str_contains($template, '{uuid}')) {
            throw new \RuntimeException('BLANCCO_PDF_NOT_CONFIGURED');
        }
        $url = str_replace('{uuid}', rawurlencode($uuid), $template);
        $response = $this->client()->get($url, ['format' => 'PDF'])->throw();
        if (! str_starts_with($response->body(), '%PDF-')) {
            throw new \RuntimeException('BLANCCO_INVALID_PDF');
        }

        return $response->body();
    }
}
