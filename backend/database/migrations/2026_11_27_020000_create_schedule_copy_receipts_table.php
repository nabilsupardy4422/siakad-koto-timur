<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('schedule_copy_receipts', function (Blueprint $table) {
            $table->string('token_hash', 64)->primary();
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('source_period_id');
            $table->unsignedBigInteger('target_period_id');
            $table->unsignedInteger('copied_count');
            $table->timestamp('created_at');
        });
    }
    public function down(): void {
        if (DB::table('schedule_copy_receipts')->exists()) throw new \RuntimeException('Receipt penyalinan sudah ada. Jangan rollback migration; gunakan backup yang terverifikasi.');
        Schema::dropIfExists('schedule_copy_receipts');
    }
};
