<?php

namespace App\Domain\Installations;

use App\Models\Device;
use App\Models\InstallationJob;
use Carbon\CarbonImmutable;

final class SelectInstallation
{
    public function forDevice(?Device $device, ?CarbonImmutable $at = null): Selection
    {
        if (! $device) {
            return new Selection(Decision::Manual, 'NO_JOB');
        }
        $at ??= CarbonImmutable::now('UTC');
        $jobs = InstallationJob::query()->with('assignment.order')
            ->whereHas('assignment', fn ($q) => $q->where('device_id', $device->id)->whereNull('withdrawn_at'))
            ->whereNull('cancelled_at')->where('lifecycle_status', '!=', 'cancelled')->get()
            ->sort(function ($a, $b) {
                $date = $b->assignment->source_assigned_at <=> $a->assignment->source_assigned_at;
                if (! $date && $a->assignment->order->source === $b->assignment->order->source && $a->assignment->source_sequence !== null && $b->assignment->source_sequence !== null) {
                    $date = $b->assignment->source_sequence <=> $a->assignment->source_sequence;
                }

                return $date ?: ($b->revision <=> $a->revision) ?: ($b->id <=> $a->id);
            })->values();
        $job = $jobs->first();
        if (! $job) {
            return new Selection(Decision::Manual, 'NO_JOB');
        }
        if ($jobs->contains(fn ($other) => $other->assignment_id !== $job->assignment_id && $other->assignment->source_assigned_at->equalTo($job->assignment->source_assigned_at) && ! ($other->assignment->order->source === $job->assignment->order->source && $other->assignment->source_sequence !== null && $job->assignment->source_sequence !== null && $other->assignment->source_sequence !== $job->assignment->source_sequence))) {
            return new Selection(Decision::Blocked, 'AMBIGUOUS_ASSIGNMENT');
        }
        // An expired latest job must not expose an older job, even if preparation failed.
        if ($at->greaterThanOrEqualTo($job->first_valid_until) || ($job->first_success_at && (! $job->retry_until || $at->greaterThanOrEqualTo($job->retry_until)))) {
            return new Selection(Decision::Manual, 'EXPIRED', $job);
        }
        if ($job->preparation_status === 'blocked') {
            return new Selection(Decision::Blocked, 'BLOCKED', $job);
        }
        if ($job->preparation_status !== 'ready' || ! $job->image_name_snapshot) {
            return new Selection(Decision::Wait, 'INCOMPLETE', $job);
        }

        return $job->first_success_at
            ? new Selection(Decision::AskRetry, 'ALREADY_INSTALLED_TODAY', $job)
            : new Selection(Decision::Auto, 'READY', $job);
    }
}
