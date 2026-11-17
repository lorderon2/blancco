<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    protected $table = 'sync_runs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['counts' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public $timestamps = false;
}
