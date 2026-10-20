<?php

namespace App\Services\Marketplace;

use Illuminate\Support\Facades\DB;

class Audit
{
    public static function log(?int $actorId, string $event, string $subjectType, string|int $subjectId, array $metadata = []): void
    {
        DB::table('marketplace_audit_events')->insert([
            'actor_id' => $actorId,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => (string) $subjectId,
            'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
