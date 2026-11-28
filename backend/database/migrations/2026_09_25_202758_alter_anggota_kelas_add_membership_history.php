<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Compatibility marker: the next migration owns the history schema.
        // Keep this filename for existing migration ledgers.
    }

    public function down(): void
    {
        // No schema changes owned by this migration in the revised chain.
    }
};
