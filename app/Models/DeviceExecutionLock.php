<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceExecutionLock extends Model
{
    protected $table = 'device_execution_locks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['acquired_at' => 'immutable_datetime', 'timeout_at' => 'immutable_datetime'];
    }

    public $timestamps = false;

    protected $primaryKey = 'device_id';

    public $incrementing = false;

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(InstallationAttempt::class);
    }
}
