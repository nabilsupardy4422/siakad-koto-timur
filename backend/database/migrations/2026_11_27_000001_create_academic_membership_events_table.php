<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('academic_membership_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa')->restrictOnDelete();
            $table->foreignId('source_membership_id')->constrained('anggota_kelas')->restrictOnDelete();
            $table->foreignId('destination_membership_id')->nullable()->constrained('anggota_kelas')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->date('effective_on');
            $table->timestamps();
            $table->unique('source_membership_id');
            $table->index(['siswa_id','action']);
        });
    }
    public function down(): void { Schema::dropIfExists('academic_membership_events'); }
};
