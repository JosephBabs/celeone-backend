<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // The existing production tables are owned by the PostgreSQL service
        // account. The deployment procedure creates this index as that owner;
        // keeping this migration a marker avoids failing normal app deploys.
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_lower_idx');
    }
};
