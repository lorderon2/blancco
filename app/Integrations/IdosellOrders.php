<?php

namespace App\Integrations;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class IdosellOrders
{
    public function sync(): int
    {
        $url = config('blancco.idosell.url');
        $key = config('blancco.idosell.key');
        $from = config('blancco.import_from');
        if (! config('blancco.idosell.enabled') || ! $url || ! $key || ! $from) {
            throw new \RuntimeException('IDOSELL_NOT_CONFIGURED');
        }
        $mappings = DB::table('product_group_mappings')->pluck('windows_group', 'external_product_id');
        if ($mappings->isEmpty()) {
            throw new \RuntimeException('IDOSELL_PRODUCT_MAPPING_MISSING');
        }
        $count = 0;
        foreach ($mappings->groupBy(fn ($version) => $version, preserveKeys: true) as $version => $products) {
            $finished = false;
            for ($page = 0; $page < (int) config('blancco.idosell.max_pages', 100); $page++) {
                $response = Http::acceptJson()->withHeaders(['X-API-KEY' => $key])->connectTimeout(5)->timeout(20)
                    ->withoutRedirecting()->post($url, ['params' => [
                        'ordersStatuses' => ['packed_ready'], 'products' => $products->keys()->map(fn ($id) => ['productId' => (string) $id])->values()->all(),
                        'stocks' => [['stockId' => 1]], 'ordersRange' => ['ordersDateRange' => ['ordersDateType' => 'add', 'ordersDateBegin' => CarbonImmutable::parse($from, 'Europe/Warsaw')->format('Y-m-d H:i:s')]],
                        'resultsPage' => $page, 'resultsLimit' => 100,
                    ]])->throw();
                $body = $response->json();
                if (! is_array($body) || ! isset($body['Results']) || ! is_array($body['Results'])) {
                    throw new \RuntimeException('IDOSELL_INVALID_RESPONSE');
                }
                if ($body['Results'] === []) {
                    $finished = true;
                    break;
                }
                foreach ($body['Results'] as $row) {
                    $number = $row['orderSerialNumber'] ?? null;
                    $country = $row['clientResult']['clientBillingAddress']['clientCountryName'] ?? null;
                    $items = $row['orderDetails']['productsResults'] ?? [];
                    if (! is_scalar($number) || trim((string) $number) === '' || ! is_string($country) || ! is_array($items)) {
                        throw new \RuntimeException('IDOSELL_INVALID_ORDER');
                    }
                    $productIds = array_map(fn ($item) => (string) ($item['productId'] ?? ''), $items);
                    $groups = $mappings->only($productIds)->values()->unique();
                    $reason = array_intersect($productIds, ['203122', '25556']) ? 'Zamówienie zawiera wykluczony produkt.' : null;
                    if ($groups->count() !== 1) {
                        $reason ??= 'Niejednoznaczna konfiguracja systemu w zamówieniu.';
                    }
                    DB::transaction(function () use ($number, $country, $groups, $version, $reason, $items) {
                        $order = Order::firstOrCreate(['source' => 'idosell', 'external_id' => (string) $number], ['document_number' => (string) $number]);
                        // Source changes remain visible but cannot silently replace an already executed job snapshot.
                        $order->update(['country' => $country, 'windows_version' => $groups->count() === 1 ? $groups->first() : $version,
                            'source_status' => 'packed_ready', 'source_data' => ['use_dpk' => true, 'preparation_reason' => $reason]]);
                        foreach ($items as $index => $item) {
                            $order->items()->updateOrCreate(['external_line_id' => (string) $index],
                                ['product_id' => (string) ($item['productId'] ?? ''), 'description' => (string) ($item['productName'] ?? ''),
                                    'quantity' => max(1, (int) ($item['productQuantity'] ?? 1))]);
                        }
                    });
                    $count++;
                }
                if (count($body['Results']) < 100) {
                    $finished = true;
                    break;
                }
            }
            if (! $finished) {
                throw new \RuntimeException('IDOSELL_PAGE_LIMIT');
            }
        }
        DB::table('sync_cursors')->updateOrInsert(['source' => 'idosell', 'scope' => 'packed_ready'], ['cursor' => json_encode(['from' => $from]), 'last_success_at' => now()]);

        return $count;
    }
}
