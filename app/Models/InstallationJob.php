<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallationJob extends Model
{
    protected $table = 'installation_jobs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['use_dpk' => 'boolean', 'first_valid_until' => 'immutable_datetime', 'first_success_at' => 'immutable_datetime', 'last_success_at' => 'immutable_datetime', 'retry_until' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(DeviceAssignment::class, 'assignment_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(InstallationAttempt::class, 'job_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }

    public function effectiveStatus(): string
    {
        if ($this->cancelled_at || $this->lifecycle_status === 'cancelled') {
            return 'cancelled';
        }
        if (now()->greaterThanOrEqualTo($this->first_valid_until) || ($this->first_success_at && (! $this->retry_until || now()->greaterThanOrEqualTo($this->retry_until)))) {
            return 'expired';
        }

        return $this->first_success_at ? 'completed' : 'open';
    }

    public function scopeExpiredAt(Builder $query, \DateTimeInterface $at): void
    {
        $query->whereNull('cancelled_at')->where('lifecycle_status', '!=', 'cancelled')->where(function ($q) use ($at) {
            $q->where('first_valid_until', '<=', $at)->orWhere(function ($q) use ($at) {
                $q->whereNotNull('first_success_at')->where(fn ($q) => $q->whereNull('retry_until')->orWhere('retry_until', '<=', $at));
            });
        });
    }
}
