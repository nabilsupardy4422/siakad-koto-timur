<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kelas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tahun_akademik_id')
                ->constrained('tahun_akademik')
                ->restrictOnDelete();

            $table->string('nama', 100);

            $table->unsignedTinyInteger('tingkat');

            $table->timestamps();

            $table->unique([
                'tahun_akademik_id',
                'nama',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kelas');
    }
};