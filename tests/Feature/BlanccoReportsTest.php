<?php

namespace Tests\Feature;

use App\Integrations\BlanccoReports;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BlanccoReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['blancco.cloud.enabled' => true, 'blancco.cloud.key' => 'synthetic', 'blancco.cloud.url' => 'https://cloud.test/export', 'blancco.cloud.report_url' => 'https://cloud.test/export/{uuid}']);
    }

    private function report(string $id, string $serial = 'PF123'): array
    {
        return ['blancco_data' => ['description' => ['document_id' => $id, 'date' => '2026-11-17'], 'blancco_hardware_report' => ['system' => ['serial' => $serial, 'model' => 'ThinkPad']]]];
    }

    public function test_all_pages_are_loaded_and_reports_for_other_serials_are_excluded(): void
    {
        Http::fakeSequence()->push(['reports' => [$this->report('11111111-1111-4111-8111-111111111111')]], 200, ['X-BLANCCO-CURSOR' => 'next'])
            ->push(['reports' => [$this->report('22222222-2222-4222-8222-222222222222'), $this->report('33333333-3333-4333-8333-333333333333', 'OTHER')]]);
        $reports = app(BlanccoReports::class)->forSerial('PF123');
        $this->assertCount(2, $reports);
        Http::assertSent(fn ($r) => ($r['cursor'] ?? null) === 'next' && $r['filter']['fields'][0]['like'] === 'PF123');
    }

    public function test_error_is_distinct_from_empty_reports_and_pdf_is_downloadable(): void
    {
        $device = Device::create(['serial_original' => 'PF123', 'serial_lookup' => 'PF123']);
        $this->actingAs(User::factory()->create());
        Http::fake(['cloud.test/export' => Http::response([], 503)]);
        $this->get('/urzadzenia/'.$device->id.'/raporty')->assertOk()->assertSee('Nie udało się pobrać pełnej listy')->assertDontSee('Brak raportów dla tego serialu');
        Http::fake(['cloud.test/export/*' => Http::response('%PDF-test')]);
        $this->get('/raporty/11111111-1111-4111-8111-111111111111/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_repeated_cursor_is_an_error_not_a_partial_success(): void
    {
        Http::fake(['cloud.test/*' => Http::response(['reports' => []], 200, ['X-BLANCCO-CURSOR' => 'same'])]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BLANCCO_REPEATED_CURSOR');
        app(BlanccoReports::class)->forSerial('PF123');
    }
}
