<?php

namespace Tests\Feature;

use App\Domain\Installations\ImportAssignment;
use App\Integrations\BitrixOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BitrixOutboxTest extends TestCase
{
    use RefreshDatabase;

    private function queue(): int
    {
        $job = app(ImportAssignment::class)->import(['source' => 'manual', 'external_id' => 'HOME/1', 'document_number' => 'HOME/1', 'serial' => 'PF123', 'windows_version' => 'WINDOWS_11_PRO', 'country' => 'Polska', 'use_dpk' => false, 'assigned_at' => now()]);
        app(BitrixOutbox::class)->enqueueHomeCheck($job);
        app(BitrixOutbox::class)->enqueueHomeCheck($job);
        $this->assertDatabaseCount('integration_outbox', 1);
        config(['blancco.bitrix.enabled' => true, 'blancco.bitrix.url' => 'https://crm.test/webhook']);
        Http::preventStrayRequests();

        return DB::table('integration_outbox')->first()->id;
    }

    public function test_acknowledged_write_is_not_delivered_twice(): void
    {
        $id = $this->queue();
        Http::fake(['crm.test/*' => Http::response(['result' => 123])]);
        $service = app(BitrixOutbox::class);
        $service->dispatch($id);
        $service->dispatch($id);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('integration_outbox', ['id' => $id, 'state' => 'sent', 'external_result_id' => '123']);
    }

    public function test_ambiguous_write_is_not_retried_automatically(): void
    {
        $id = $this->queue();
        Http::fake(['crm.test/*' => Http::response('gateway error', 502)]);
        $service = app(BitrixOutbox::class);
        $service->dispatch($id);
        $service->dispatch($id);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('integration_outbox', ['id' => $id, 'state' => 'needs_review']);
    }
}
