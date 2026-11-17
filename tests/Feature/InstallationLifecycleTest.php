<?php

namespace Tests\Feature;

use App\Domain\Installations\AttemptConflict;
use App\Domain\Installations\CancelInstallation;
use App\Domain\Installations\Decision;
use App\Domain\Installations\ImportAssignment;
use App\Domain\Installations\ManageAttempts;
use App\Domain\Installations\SelectInstallation;
use App\Domain\Installations\Selection;
use App\Domain\Installations\Validity;
use App\Models\Device;
use App\Models\InstallationAttempt;
use App\Models\InstallationJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InstallationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-16 10:00:00 UTC');
        Carbon::setTestNow(CarbonImmutable::now());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function job(string $document = 'ZAM/1', string $assigned = '2026-11-16T09:00:00Z'): InstallationJob
    {
        return app(ImportAssignment::class)->import(['source' => 'manual', 'external_id' => $document, 'document_number' => $document, 'serial' => 'PF123', 'windows_version' => 'WINDOWS_11_PRO', 'country' => 'Polska', 'use_dpk' => true, 'assigned_at' => $assigned]);
    }

    private function select(): Selection
    {
        return app(SelectInstallation::class)->forDevice(Device::first());
    }

    private function at(string $date): void
    {
        CarbonImmutable::setTestNow($date);
        Carbon::setTestNow(CarbonImmutable::now());
    }

    private function start(InstallationJob $job, string $key = 'physical-boot-request-0001', bool $retry = false): InstallationAttempt
    {
        return app(ManageAttempts::class)->start($job->assignment->device, $key, 'order', $job->id, $job->configuration_hash, $retry);
    }

    public function test_first_deadline_is_end_of_third_calendar_day_in_poland(): void
    {
        $job = $this->job();
        $this->assertSame('2026-11-18T23:00:00+00:00', $job->first_valid_until->toIso8601String());
        $this->at('2026-11-18 22:59:59 UTC');
        $this->assertSame(Decision::Auto, $this->select()->decision);
        $this->at('2026-11-18 23:00:00 UTC');
        $this->assertSame(Decision::Manual, $this->select()->decision);
    }

    public function test_daylight_saving_deadline_uses_calendar_days_not_72_hours(): void
    {
        $deadline = Validity::firstDeadline(CarbonImmutable::parse('2026-03-27T22:00:00Z'));
        $this->assertSame('2026-03-29T22:00:00+00:00', $deadline->toIso8601String());
    }

    public function test_import_does_not_duplicate_or_extend_expired_job(): void
    {
        $job = $this->job();
        $deadline = $job->first_valid_until;
        $this->at('2026-11-20 10:00:00 UTC');
        $again = $this->job();
        $this->assertSame($job->id, $again->id);
        $this->assertTrue($deadline->equalTo($again->first_valid_until));
        $this->assertDatabaseCount('installation_jobs', 1);
        $this->assertSame(Decision::Manual, $this->select()->decision);
    }

    public function test_cancellation_returns_to_older_valid_job(): void
    {
        $old = $this->job();
        $new = $this->job('ZAM/2', '2026-11-16T10:00:00Z');
        $this->assertSame($new->id, $this->select()->job->id);
        app(CancelInstallation::class)->cancel($new, 'Pomyłka operatora');
        $this->assertSame($old->id, $this->select()->job->id);
        $this->assertSame(Decision::Auto, $this->select()->decision);
    }

    public function test_reimport_does_not_restore_cancelled_job(): void
    {
        $job = $this->job();
        app(CancelInstallation::class)->cancel($job, 'Anulowanie');
        $this->job();
        $this->assertSame(Decision::Manual, $this->select()->decision);
        $this->assertNotNull($job->refresh()->cancelled_at);
    }

    public function test_expired_latest_does_not_fall_back_to_older_valid_job(): void
    {
        $old = $this->job();
        $new = $this->job('ZAM/2', '2026-11-16T10:00:00Z');
        $new->update(['first_valid_until' => now()->subMinute()]);
        $this->assertSame(Decision::Manual, $this->select()->decision);
        $this->assertSame($new->id, $this->select()->job->id);
    }

    public function test_reimport_order_does_not_change_source_chronology(): void
    {
        $old = $this->job();
        $new = $this->job('ZAM/2', '2026-11-16T10:00:00Z');
        $this->at('2026-11-16 11:00:00 UTC');
        $this->job();
        $this->assertSame($new->id, $this->select()->job->id);
    }

    public function test_success_allows_only_same_day_retry_and_callback_is_idempotent(): void
    {
        $job = $this->job();
        $attempt = $this->start($job);
        $manager = app(ManageAttempts::class);
        $result = $manager->finish($attempt, true);
        $finished = $result->finished_at;
        $this->assertSame(Decision::AskRetry, $this->select()->decision);
        $this->at('2026-11-16 11:00:00 UTC');
        $result = $manager->finish($attempt, true);
        $this->assertTrue($finished->equalTo($result->finished_at));
        $retryUntil = $job->refresh()->retry_until;
        $retry = $this->start($job, 'physical-boot-request-0002', true);
        $manager->finish($retry, true);
        $this->assertTrue($retryUntil->equalTo($job->refresh()->retry_until));
        $this->at('2026-11-16 23:00:00 UTC');
        $this->assertSame(Decision::Manual, $this->select()->decision);
    }

    public function test_retry_requires_operator_confirmation(): void
    {
        $job = $this->job();
        app(ManageAttempts::class)->finish($this->start($job), true);
        $this->expectException(AttemptConflict::class);
        $this->start($job->refresh(), 'physical-boot-request-0002');
    }

    public function test_start_is_idempotent_and_second_boot_cannot_start_in_parallel(): void
    {
        $job = $this->job();
        $attempt = $this->start($job);
        $again = $this->start($job);
        $this->assertSame($attempt->id, $again->id);
        $this->assertDatabaseCount('installation_attempts', 1);
        $this->assertSame(Decision::Busy, $this->select()->decision);
        $this->expectException(AttemptConflict::class);
        $this->start($job, 'physical-boot-request-0002');
    }

    public function test_stale_selection_is_rejected_after_midnight(): void
    {
        $job = $this->job();
        $this->at('2026-11-18 23:00:00 UTC');
        $this->expectException(AttemptConflict::class);
        $this->start($job);
    }

    public function test_cancel_is_rejected_while_attempt_is_active(): void
    {
        $job = $this->job();
        $this->start($job);
        $this->expectException(ValidationException::class);
        app(CancelInstallation::class)->cancel($job, 'Anulowanie');
    }

    public function test_failure_releases_device_but_does_not_mark_job_completed(): void
    {
        $job = $this->job();
        app(ManageAttempts::class)->finish($this->start($job), false, 'REIMAGE_FAILED');
        $this->assertNull($job->refresh()->first_success_at);
        $this->assertSame(Decision::Auto, $this->select()->decision);
    }

    public function test_conflicting_callback_cannot_replace_success(): void
    {
        $job = $this->job();
        $attempt = $this->start($job);
        app(ManageAttempts::class)->finish($attempt, true);
        $this->expectException(AttemptConflict::class);
        app(ManageAttempts::class)->finish($attempt, false, 'FAILED');
    }

    public function test_same_time_conflicting_assignments_block_automatic_choice(): void
    {
        $this->job();
        $this->job('ZAM/2');
        $this->assertSame(Decision::Blocked, $this->select()->decision);
    }

    public function test_manual_attempt_never_completes_an_order(): void
    {
        $job = $this->job();
        $attempt = app(ManageAttempts::class)->start($job->assignment->device, 'manual-request-key-00001', 'manual');
        app(ManageAttempts::class)->finish($attempt, true);
        $this->assertNull($job->refresh()->first_success_at);
    }

    public function test_original_lenovo_serial_and_multiple_devices_are_preserved(): void
    {
        $this->job();
        app(ImportAssignment::class)->import(['source' => 'manual', 'external_id' => 'ZAM/1', 'document_number' => 'ZAM/1', 'serial' => ' PF456 ', 'windows_version' => 'WINDOWS_11_PRO', 'country' => 'Polska', 'use_dpk' => true, 'assigned_at' => '2026-11-16T09:00:00Z']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('device_assignments', 2);
        $this->assertDatabaseHas('devices', ['serial_original' => 'PF456', 'serial_lookup' => 'PF456']);
    }
}
