<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationAttempt extends Model
{
    protected $table = 'installation_attempts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['config_snapshot' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    public function job(): BelongsTo
    {
        return $this->belongsTo(InstallationJob::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
