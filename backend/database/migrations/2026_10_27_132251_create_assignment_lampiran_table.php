<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_lampiran', function (Blueprint $table) {
            $table->id();

            $table->foreignId('assignment_id')
                ->constrained('assignments')
                ->cascadeOnDelete();

            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_mime_type', 100);
            $table->unsignedBigInteger('file_size');

            $table->timestamps();

            $table->index('assignment_id');
        });

        // Migrasikan metadata lampiran lama ke tabel baru.
        // File fisik tidak dipindahkan atau dihapus.
        $now = now();

        DB::table('assignments')
            ->whereNotNull('file_path')
            ->whereNotNull('file_name')
            ->orderBy('id')
            ->chunk(100, function ($assignments) use ($now) {
                $rows = [];

                foreach ($assignments as $assignment) {
                    $rows[] = [
                        'assignment_id' => $assignment->id,
                        'file_path' => $assignment->file_path,
                        'file_name' => $assignment->file_name,
                        'file_mime_type' =>
                            $assignment->file_mime_type ?? 'application/octet-stream',
                        'file_size' => $assignment->file_size ?? 0,
                        'created_at' => $assignment->created_at ?? $now,
                        'updated_at' => $assignment->updated_at ?? $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('assignment_lampiran')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_lampiran');
    }
};
