<?php

namespace Tests\Feature;

use App\Integrations\IdosellOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdosellImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['blancco.sync_enabled' => true, 'blancco.idosell.enabled' => true, 'blancco.idosell.url' => 'https://idosell.test/orders/get', 'blancco.idosell.key' => 'local-test-only', 'blancco.import_from' => '2026-11-17']);
        DB::table('product_group_mappings')->insert(['external_product_id' => '100', 'windows_group' => 'WINDOWS_11_PRO']);
    }

    private function order(array $products = [['productId' => 100]]): array
    {
        return ['orderSerialNumber' => 123, 'clientResult' => ['clientBillingAddress' => ['clientCountryName' => 'Polska']], 'orderDetails' => ['productsResults' => $products]];
    }

    public function test_idosell_import_is_deduplicated_and_retains_only_operational_data(): void
    {
        Http::fake(['idosell.test/*' => Http::response(['Results' => [$this->order()]])]);
        $source = app(IdosellOrders::class);
        $source->sync();
        $source->sync();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseHas('orders', ['source' => 'idosell', 'document_number' => '123', 'windows_version' => 'WINDOWS_11_PRO']);
        Http::assertSent(fn ($request) => $request->hasHeader('X-API-KEY', 'local-test-only') && $request['params']['ordersStatuses'] === ['packed_ready'] && $request['params']['resultsPage'] === 0);
    }

    public function test_excluded_product_is_visible_as_a_block_in_source_data(): void
    {
        Http::fake(['idosell.test/*' => Http::response(['Results' => [$this->order([['productId' => 100], ['productId' => 203122]])]])]);
        app(IdosellOrders::class)->sync();
        $data = json_decode(DB::table('orders')->first()->source_data, true);
        $this->assertSame('Zamówienie zawiera wykluczony produkt.', $data['preparation_reason']);
    }

    public function test_upstream_error_does_not_advance_cursor_or_claim_success(): void
    {
        Http::fake(['idosell.test/*' => Http::response(['error' => 'rate limit'], 429)]);
        $this->artisan('blancco:sync-idosell')->assertFailed();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('sync_cursors', 0);
        $this->assertDatabaseHas('sync_runs', ['source' => 'idosell', 'state' => 'failed']);
    }

    public function test_documented_empty_search_fault_is_success_without_orders(): void
    {
        Http::fake(['idosell.test/*' => Http::response(['errors' => ['faultCode' => 2, 'faultString' => 'Wyszukiwarka zamówień: zwrócono pusty wynik']])]);
        $this->assertSame(0, app(IdosellOrders::class)->sync());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('sync_cursors', 1);
    }

    public function test_other_fault_with_same_code_does_not_advance_cursor(): void
    {
        Http::fake(['idosell.test/*' => Http::response(['errors' => ['faultCode' => 2, 'faultString' => 'Inny błąd']])]);
        $this->artisan('blancco:sync-idosell')->assertFailed();
        $this->assertDatabaseCount('sync_cursors', 0);
    }

    public function test_malformed_success_response_is_not_empty_success(): void
    {
        Http::fake(['idosell.test/*' => Http::response(['error' => 'invalid key'])]);
        $this->artisan('blancco:sync-idosell')->assertFailed();
        $this->assertDatabaseCount('sync_cursors', 0);
    }
}
