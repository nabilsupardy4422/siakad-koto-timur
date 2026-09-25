<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nilai', function (Blueprint $table) {
            $table->id();

            $table->foreignId('komponen_nilai_id')
                ->constrained('komponen_nilai')
                ->restrictOnDelete();

            $table->foreignId('siswa_id')
                ->constrained('siswa')
                ->restrictOnDelete();

            $table->decimal('nilai', 5, 2);

            $table->text('catatan')->nullable();

            $table->timestamps();

            $table->unique([
                'komponen_nilai_id',
                'siswa_id',
            ]);

            $table->index([
                'siswa_id',
                'komponen_nilai_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nilai');
    }
};