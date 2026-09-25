<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('komponen_nilai', function (Blueprint $table) {
            $table->id();

            $table->foreignId('jadwal_pelajaran_id')
                ->constrained('jadwal_pelajaran')
                ->restrictOnDelete();

            $table->string('nama', 100);

            $table->decimal('bobot', 5, 2);

            $table->timestamps();

            $table->unique([
                'jadwal_pelajaran_id',
                'nama',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('komponen_nilai');
    }
};