<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    protected $table = 'audit_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['redacted_changes' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public $timestamps = false;
}
