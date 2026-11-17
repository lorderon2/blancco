<?php

namespace Tests\Feature;

use App\Integrations\IdosellOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdosellHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_http_serialization_and_repeated_import_against_local_contract(): void
    {
        $url = getenv('BLANCCO_SIM_URL');
        if (! $url) {
            $this->markTestSkipped('Run with BLANCCO_SIM_URL pointing to the local contract server.');
        }
        $this->assertContains(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost']);
        config(['blancco.idosell.enabled' => true, 'blancco.idosell.url' => $url, 'blancco.idosell.key' => 'synthetic-test-key', 'blancco.import_from' => '2026-11-17']);
        DB::table('product_group_mappings')->insert(['external_product_id' => '100', 'windows_group' => 'WINDOWS_11_PRO']);
        app(IdosellOrders::class)->sync();
        app(IdosellOrders::class)->sync();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', ['document_number' => 'SIM-123', 'source' => 'idosell']);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseHas('sync_cursors', ['source' => 'idosell', 'scope' => 'packed_ready']);
    }
}
