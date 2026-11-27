<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('wali_kelas', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason', 500)->nullable();
        });
    }
    public function down(): void {
        // Removing cancellation metadata would reactivate cancelled assignments.
        if (\Illuminate\Support\Facades\DB::table('wali_kelas')->whereNotNull('cancelled_at')->exists()) {
            throw new \RuntimeException('Restore a verified backup instead: cancellation history exists.');
        }
        Schema::table('wali_kelas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at','cancel_reason']);
        });
    }
};
