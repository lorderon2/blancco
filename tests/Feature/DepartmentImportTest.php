<?php

namespace Tests\Feature;

use App\Integrations\DepartmentOrders;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DepartmentImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_departments_resume_after_midnight_without_duplication_or_renewal(): void
    {
        config(['database.connections.departments' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'blancco.departments.enabled' => true, 'blancco.import_from' => '2026-11-16']);
        DB::purge('departments');
        Schema::connection('departments')->create('shop', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        Schema::connection('departments')->create('orders', function (Blueprint $t) {
            $t->id();
            $t->integer('shop');
            $t->string('serial');
            $t->string('name');
            $t->string('os');
            $t->integer('created');
        });
        DB::connection('departments')->table('shop')->insert(['id' => 1, 'name' => 'Kraków']);
        DB::connection('departments')->table('orders')->insert(['id' => 42, 'shop' => 1, 'serial' => 'PF123', 'name' => 'LENOVO', 'os' => 'WINDOWS 11 PRO', 'created' => 1794826800]);
        // Explicit timestamp avoids relying on a source server timezone.
        DB::connection('departments')->table('orders')->update(['created' => CarbonImmutable::parse('2026-11-16T12:00:00Z')->timestamp]);
        $service = app(DepartmentOrders::class);
        $this->assertSame(1, $service->sync());
        $service->sync();
        $this->assertDatabaseCount('installation_jobs', 1);
        $this->assertDatabaseHas('orders', ['external_id' => '42', 'document_number' => 'Kraków_42', 'source' => 'department']);
        $this->assertDatabaseHas('installation_jobs', ['image_name_snapshot' => '2WIN 11 PRO PL ODD', 'use_dpk' => false]);
        $this->assertDatabaseHas('sync_cursors', ['source' => 'departments']);
    }
}
