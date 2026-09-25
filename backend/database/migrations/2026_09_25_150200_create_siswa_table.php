<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('siswa', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('nisn', 50)->unique();

            $table->string('nis', 50)->nullable()->unique();

            $table->string('nama_lengkap');

            $table->string('jenis_kelamin', 20)->nullable();

            $table->date('tanggal_lahir')->nullable();

            $table->string('tempat_lahir', 100)->nullable();

            $table->string('no_telepon', 30)->nullable();

            $table->text('alamat')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siswa');
    }
};