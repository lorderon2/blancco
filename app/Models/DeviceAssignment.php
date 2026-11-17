<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceAssignment extends Model
{
    protected $table = 'device_assignments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source_assigned_at' => 'immutable_datetime', 'first_seen_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(InstallationJob::class, 'assignment_id');
    }
}
