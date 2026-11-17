<?php

namespace Tests\Feature;

use App\Domain\Installations\Decision;
use App\Domain\Installations\SelectInstallation;
use App\Integrations\BcLedger;
use App\Integrations\SynchronizeAssignments;
use App\Models\Device;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SourceSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['bc', 'aiken_lap', 'aiken_pc', 'departments'] as $name) {
            config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
            DB::purge($name);
        }
        config(['blancco.bc.enabled' => true, 'blancco.bc.table' => 'ledger', 'blancco.bc.connection' => 'bc', 'blancco.aiken.enabled' => true]);
        Schema::connection('bc')->create('ledger', function (Blueprint $t) {
            $t->integer('Entry No_');
            $t->string('Posting Date');
            $t->string('Document No_');
            $t->string('Serial No_');
            $t->string('Description');
            $t->string('Item No_');
            $t->integer('Entry Type');
            $t->integer('Quantity');
        });
        foreach (['aiken_lap', 'aiken_pc'] as $name) {
            Schema::connection($name)->create('Units', function (Blueprint $t) {
                $t->integer('UnitID');
                $t->string('SerialNumber');
                $t->string('PartNumber');
            });
            Schema::connection($name)->create('Units_Devices', function (Blueprint $t) {
                $t->integer('UnitId');
                $t->string('Category');
                $t->string('Serial')->nullable();
                $t->string('Model')->nullable();
            });
        }
    }

    private function ledger(string $serial, string $document, int $sequence): void
    {
        DB::connection('bc')->table('ledger')->insert(['Entry No_' => $sequence, 'Posting Date' => now()->toDateString(), 'Document No_' => $document,
            'Serial No_' => $serial, 'Description' => 'LENOVO ThinkPad', 'Item No_' => 'NOT-123', 'Entry Type' => 4, 'Quantity' => 1]);
    }

    public function test_document_form_and_bc_sync_preserve_serials_coa_and_import_identity(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/dokumenty', ['document_number' => 'FV/1', 'windows_version' => 'WINDOWS_10_PRO', 'country' => 'Polska', 'use_dpk' => 0])->assertRedirect();
        $this->get('/dokumenty')->assertOk()->assertSee('FV/1');
        $this->ledger('PF123', 'FV/1', 1);
        DB::connection('aiken_lap')->table('Units')->insert(['UnitID' => 1, 'SerialNumber' => 'PF123', 'PartNumber' => 'NOT']);
        DB::connection('aiken_lap')->table('Units_Devices')->insert(['UnitId' => 1, 'Category' => 'COA', 'Serial' => 'test-license-never-logged', 'Model' => null]);
        $service = app(SynchronizeAssignments::class);
        $this->assertSame(1, $service->document(Order::first()));
        $service->document(Order::first());
        $this->assertDatabaseCount('installation_jobs', 1);
        $this->assertDatabaseHas('devices', ['serial_original' => 'PF123']);
        $license = DB::table('device_license_data')->first();
        $this->assertNotSame('test-license-never-logged', $license->encrypted_value);
        $this->assertSame('test-license-never-logged', Crypt::decryptString($license->encrypted_value));
        $this->assertSame(Decision::Auto, app(SelectInstallation::class)->forDevice(Device::first())->decision);
    }

    public function test_bc_filters_wrong_document_type_quantity_and_item(): void
    {
        $this->ledger('PF123', 'FV/1', 1);
        $this->ledger('PF456', 'FV/2', 2);
        $this->ledger('WRONG', 'FV/1', 3);
        DB::connection('bc')->table('ledger')->where('Serial No_', 'WRONG')->update(['Quantity' => -1]);
        $rows = app(BcLedger::class)->assignments('FV/1');
        $this->assertCount(1, $rows);
        $this->assertSame('PF123', $rows[0]['serial']);
    }

    public function test_missing_coa_blocks_job_and_failed_source_does_not_report_success(): void
    {
        $order = Order::create(['source' => 'document', 'external_id' => 'FV/1', 'document_number' => 'FV/1', 'country' => 'Polska', 'windows_version' => 'WINDOWS_10_PRO']);
        $this->ledger('PF123', 'FV/1', 1);
        app(SynchronizeAssignments::class)->document($order);
        $this->assertSame(Decision::Blocked, app(SelectInstallation::class)->forDevice(Device::first())->decision);
        config(['blancco.sync_enabled' => true, 'blancco.import_from' => '2026-11-17', 'blancco.bc.table' => 'nonexistent']);
        $this->artisan('blancco:sync', ['source' => 'bc'])->assertFailed();
        $this->assertDatabaseHas('sync_runs', ['source' => 'bc', 'state' => 'failed']);
    }

    public function test_sync_disabled_by_default_and_no_connection_attempt_is_made(): void
    {
        config(['blancco.sync_enabled' => false]);
        $this->artisan('blancco:sync', ['source' => 'bc'])->assertFailed();
        $this->assertDatabaseCount('sync_runs', 0);
    }

    public function test_source_sequence_selects_latest_same_day_bc_assignment(): void
    {
        $old = Order::create(['source' => 'document', 'external_id' => 'FV/1', 'document_number' => 'FV/1', 'country' => 'Polska', 'windows_version' => 'WINDOWS_11_PRO']);
        $new = Order::create(['source' => 'document', 'external_id' => 'FV/2', 'document_number' => 'FV/2', 'country' => 'Polska', 'windows_version' => 'WINDOWS_11_PRO']);
        $this->ledger('PF123', 'FV/1', 1);
        $this->ledger('PF123', 'FV/2', 2);
        $sync = app(SynchronizeAssignments::class);
        $sync->document($new);
        $sync->document($old);
        $selection = app(SelectInstallation::class)->forDevice(Device::first());
        $this->assertSame(Decision::Auto, $selection->decision);
        $this->assertSame($new->id, $selection->job->assignment->order_id);
    }

    public function test_optional_aiken_failure_does_not_block_win11_but_required_coa_stays_blocked(): void
    {
        config(['blancco.aiken.enabled' => false]);
        $order = Order::create(['source' => 'document', 'external_id' => 'FV/11', 'document_number' => 'FV/11', 'country' => 'Polska', 'windows_version' => 'WINDOWS_11_PRO']);
        $this->ledger('OPTIONAL', 'FV/11', 1);
        app(SynchronizeAssignments::class)->document($order);
        $this->assertDatabaseHas('installation_jobs', ['preparation_status' => 'ready']);
        $required = Order::create(['source' => 'document', 'external_id' => 'FV/10', 'document_number' => 'FV/10', 'country' => 'Polska', 'windows_version' => 'WINDOWS_10_PRO']);
        $this->ledger('REQUIRED', 'FV/10', 2);
        app(SynchronizeAssignments::class)->document($required);
        $this->assertDatabaseHas('installation_jobs', ['preparation_status' => 'blocked', 'blocked_reason' => 'Nie udało się sprawdzić wymaganego COA: AIKEN jest niedostępny.']);
    }
}
