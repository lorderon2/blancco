<?php

namespace App\Domain\Installations;

use App\Models\AuditEvent;
use Illuminate\Support\Facades\Auth;

final class Audit
{
    public static function record(string $entity, string|int $id, string $event, array $changes = []): void
    {
        AuditEvent::create(['actor_type' => Auth::check() ? 'user' : 'system', 'actor_id' => Auth::id(), 'entity_type' => $entity,
            'entity_id' => (string) $id, 'event' => $event, 'redacted_changes' => $changes, 'created_at' => now()]);
    }
}
