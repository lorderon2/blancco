<?php

namespace Tests\Feature;

use App\Integrations\BcLedger;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BcApiSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['blancco.bc.enabled' => true, 'blancco.bc.source' => 'api', 'blancco.bc.api.base_url' => 'https://bc.test/company',
            'blancco.bc.api.token_url' => 'https://login.test/token', 'blancco.bc.api.client_id' => 'synthetic-client', 'blancco.bc.api.client_secret' => 'synthetic-secret']);
    }

    private function row(): array
    {
        return ['Document_No' => 'FV/1', 'Serial_No' => 'PF123', 'Item_No' => 'NOT-123', 'Entry_Type' => 'Transfer', 'Quantity' => '1', 'Entry_No' => 42, 'N24C_SystemCreatedAt' => '2026-11-17T10:00:00Z', 'Description' => 'LENOVO'];
    }

    public function test_bc_api_uses_creation_timestamp_and_batch_cache_without_changing_serial(): void
    {
        Http::fake(['login.test/*' => Http::response(['access_token' => 'synthetic-token', 'expires_in' => 3600]), 'bc.test/*' => Http::response(['value' => [$this->row()]])]);
        $source = app(BcLedger::class);
        $source->preload(['FV/1', 'FV/2']);
        $rows = $source->assignments('FV/1');
        $this->assertSame('PF123', $rows[0]['serial']);
        $this->assertSame(42, $rows[0]['source_sequence']);
        $this->assertSame('2026-11-17T10:00:00+00:00', $rows[0]['assigned_at']->toIso8601String());
        $this->assertSame([], $source->assignments('FV/2'));
        Http::assertSentCount(2);
    }

    public function test_next_page_cannot_send_bearer_to_another_host(): void
    {
        Http::fake(['login.test/*' => Http::response(['access_token' => 'synthetic-token']),
            'bc.test/*' => Http::response(['value' => [], '@odata.nextLink' => 'https://other.test/ILE'])]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BC_API_UNTRUSTED_NEXT_LINK');
        app(BcLedger::class)->assignments('FV/1');
    }

    public function test_missing_chronology_is_an_error_not_an_arbitrary_latest_assignment(): void
    {
        $row = $this->row();
        unset($row['N24C_SystemCreatedAt']);
        Http::fake(['login.test/*' => Http::response(['access_token' => 'synthetic-token']), 'bc.test/*' => Http::response(['value' => [$row]])]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BC_ASSIGNMENT_CHRONOLOGY_MISSING');
        app(BcLedger::class)->assignments('FV/1');
    }
}
