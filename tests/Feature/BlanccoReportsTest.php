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
        Http::assertSent(fn ($r) => str_contains($r->url(), 'cursor=next') && ! isset($r['cursor']) && $r['filter']['fields'][0]['like'] === 'PF123');
    }

    public function test_bar_typed_field_arrays_and_document_log_date_are_supported(): void
    {
        $report = $this->report('11111111-1111-4111-8111-111111111111');
        $report['blancco_data']['blancco_hardware_report']['system'] = [
            ['@type' => 'string', 'serial' => 'PF123'], ['@type' => 'string', 'model' => 'Latitude 5510'],
        ];
        unset($report['blancco_data']['description']['date']);
        $report['blancco_data']['description']['document_log'] = [['date' => '2026-11-17T10:55:13+0100']];
        Http::fake(['cloud.test/*' => Http::response(['reports' => [$report]])]);
        $reports = app(BlanccoReports::class)->forSerial('PF123');
        $this->assertCount(1, $reports);
        $this->assertSame('Latitude 5510', $reports[0]['model']);
        $this->assertSame('2026-11-17T10:55:13+0100', $reports[0]['date']);
    }

    public function test_latest_report_summary_uses_real_chronology_and_typed_hardware_fields(): void
    {
        $device = Device::create(['serial_original' => 'PF123', 'serial_lookup' => 'PF123']);
        $this->actingAs(User::factory()->create());
        $older = $this->report('11111111-1111-4111-8111-111111111111');
        $older['blancco_data']['description']['date'] = '2026-11-18T12:00:00+0200';
        $newer = $this->report('22222222-2222-4222-8222-222222222222');
        $newer['blancco_data']['description']['date'] = '2026-11-18T11:00:00+0000';
        $newer['blancco_data']['blancco_hardware_report']['system'] = [['serial' => 'PF123'], ['model' => 'Latitude <5510>']];
        $newer['blancco_data']['blancco_hardware_report']['memory'] = [['@type' => 'uint', 'total_memory' => 17179869184], ['free_slots' => 0]];
        $newer['blancco_data']['blancco_hardware_report']['disks'] = [['disk' => [['model' => 'SSD ONE'], ['capacity' => 1073741824]]], ['disk' => [['model' => 'SSD TWO']]]];
        $newer['blancco_data']['blancco_reimage_report']['reimages'] = [['reimage' => [['state' => 'Successful'], ['image' => [['image_name' => 'WIN 11 PRO']]], ['dpk' => [['@type' => 'string', 'coa_type' => '11P'], ['@type' => 'string', 'product_key' => 'TEST1-TEST2-TEST3-TEST4-TEST5'], ['@type' => 'string', 'product_key_id' => 'SYNTHETIC-ID']]]]]];
        Http::fake(['cloud.test/*' => Http::response(['reports' => [$older, $newer]])]);
        $this->get('/urzadzenia/'.$device->id.'/raporty')->assertOk()
            ->assertViewHas('latest', fn ($report) => $report['id'] === '22222222-2222-4222-8222-222222222222')
            ->assertSeeInOrder(['Dane z najnowszego raportu', '16,0 GiB', 'SSD ONE', 'SSD TWO', 'WIN 11 PRO', 'Raporty PDF i historia'])
            ->assertViewHas('sections', fn ($sections) => $sections['Instalacja Reimage 1']['Klucz produktu'] === 'TEST1-TEST2-TEST3-TEST4-TEST5'
                && $sections['Instalacja Reimage 1']['Identyfikator klucza produktu'] === 'SYNTHETIC-ID'
                && $sections['Instalacja Reimage 1']['Rodzaj COA'] === '11P')
            ->assertSee('Latitude &lt;5510&gt;', false)->assertDontSee('Latitude <5510>', false);
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
