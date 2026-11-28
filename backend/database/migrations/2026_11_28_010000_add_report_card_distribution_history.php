<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('rapor', function (Blueprint $table) {
            $table->unsignedInteger('document_version')->default(1);
            $table->string('distribution_status', 16)->default('active');
        });
        Schema::create('rapor_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rapor_id')->constrained('rapor')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('document_version');
            $table->string('action', 16);
            $table->text('reason')->nullable();
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->index(['rapor_id','id']);
        });
    }
    public function down(): void {
        throw new RuntimeException('Rollback destruktif dinonaktifkan: pulihkan backup database dan storage secara terencana.');
    }
};
