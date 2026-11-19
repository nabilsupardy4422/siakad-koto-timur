<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tambahan untuk akun yang perlu aktivasi.
        // Akun lama otomatis tidak memerlukan aktivasi.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('requires_activation')
                ->default(false)
                ->after('account_status');

            $table->timestamp('activated_at')
                ->nullable()
                ->after('requires_activation');
        });

        // Satu record kode aktivasi per pengguna.
        // Nilai kode asli tidak disimpan dalam database.
        Schema::create('user_activation_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->char('token_hash', 64)
                ->unique();

            $table->timestamp('expires_at')
                ->index();

            $table->timestamp('used_at')
                ->nullable();

            $table->unsignedSmallInteger('failed_attempts')
                ->default(0);

            $table->foreignId('issued_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_activation_tokens');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'requires_activation',
                'activated_at',
            ]);
        });
    }
};
