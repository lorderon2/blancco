<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Device extends Model
{
    protected $table = 'devices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceAssignment::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(InstallationAttempt::class);
    }

    public function executionLock(): HasOne
    {
        return $this->hasOne(DeviceExecutionLock::class);
    }
}
