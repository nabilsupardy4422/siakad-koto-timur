
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bahan_ajar_lampiran', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bahan_ajar_id')
                ->constrained('bahan_ajar')
                ->cascadeOnDelete();

            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            // Nullable agar lampiran lama tetap bisa dimigrasikan.
            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['bahan_ajar_id', 'created_at']);
        });

        // Salin metadata file lama tanpa memindahkan atau
        // menghapus file fisik yang tersimpan di disk local.
        DB::table('bahan_ajar')
            ->whereNotNull('file_path')
            ->where('file_path', '!=', '')
            ->orderBy('id')
            ->chunk(200, function ($materials): void {
                $now = now();

                $attachments = [];

                foreach ($materials as $material) {
                    $attachments[] = [
                        'bahan_ajar_id' => $material->id,
                        'file_path' => $material->file_path,
                        'file_name' => $material->file_name
                            ?: basename($material->file_path),
                        'file_mime_type' => $material->file_mime_type,
                        'file_size' => $material->file_size,
                        'uploaded_by' => $material->created_by,
                        'created_at' => $material->created_at ?? $now,
                        'updated_at' => $material->updated_at ?? $now,
                    ];
                }

                if ($attachments !== []) {
                    DB::table('bahan_ajar_lampiran')->insert($attachments);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('bahan_ajar_lampiran');
    }
};
