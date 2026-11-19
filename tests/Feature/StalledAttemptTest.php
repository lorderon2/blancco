<?php

namespace Tests\Feature;

use App\Domain\Installations\Decision;
use App\Domain\Installations\ImportAssignment;
use App\Domain\Installations\ManageAttempts;
use App\Domain\Installations\SelectInstallation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StalledAttemptTest extends TestCase
{
    use RefreshDatabase;

    public function test_stalled_attempt_does_not_block_restarting_the_device(): void
    {
        $this->actingAs(User::factory()->create());
        $job = app(ImportAssignment::class)->import(['source' => 'manual', 'external_id' => 'M/1', 'document_number' => 'M/1', 'serial' => 'PF123', 'windows_version' => 'WINDOWS_11_PRO', 'country' => 'Polska', 'use_dpk' => false, 'assigned_at' => now()]);
        $device = $job->assignment->device;
        $attempt = app(ManageAttempts::class)->start($device, 'boot-request-unique-01', 'order', $job->id, $job->configuration_hash);
        $this->travel(5)->hours();
        $this->artisan('blancco:detect-stalled')->assertSuccessful();
        $this->assertSame('unknown', $attempt->refresh()->state);
        $this->assertSame(Decision::Auto, app(SelectInstallation::class)->forDevice($device)->decision);
        $this->post('/proby/'.$attempt->id.'/rozstrzygnij', ['result' => 'failed', 'reason' => 'Sprawdzono urządzenie'])->assertRedirect();
        $this->assertSame('failed', $attempt->refresh()->state);
        $this->assertDatabaseCount('device_execution_locks', 0);
        $this->post('/proby/'.$attempt->id.'/rozstrzygnij', ['result' => 'succeeded', 'reason' => 'Sprzeczny wynik'])->assertSessionHasErrors('attempt');
        $this->assertSame('failed', $attempt->refresh()->state);
    }
}
