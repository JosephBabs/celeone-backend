<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Outbox
{
    public function record(string $event, ?string $type, ?string $id, array $payload): string
    {
        $eventId=(string) Str::uuid();
        DB::table('outbox_events')->insert(['id'=>$eventId,'event_type'=>$event,'aggregate_type'=>$type,'aggregate_id'=>$id,'payload'=>json_encode($payload),'created_at'=>now(),'updated_at'=>now()]);
        return $eventId;
    }
}
