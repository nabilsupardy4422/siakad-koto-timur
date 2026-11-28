<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // No verified mapping from legacy bahan_ajar_id to assignment_id.
        if (DB::table('submission_tugas')->exists()) {
            throw new RuntimeException('SUBMISSION_LEGACY_MAPPING_REVIEW_REQUIRED');
        }
        Schema::table('submission_tugas', function (Blueprint $table) {
            $table->dropForeign([
                'bahan_ajar_id',
            ]);

            $table->dropUnique([
                'bahan_ajar_id',
                'siswa_id',
            ]);

            $table->dropColumn('bahan_ajar_id');

            $table->foreignId('assignment_id')
                ->after('id')
                ->constrained('assignments')
                ->restrictOnDelete();

            $table->text('jawaban')
                ->nullable()
                ->after('siswa_id');

            $table->string('status', 30)
                ->default('dikumpulkan')
                ->change();

            $table->unique([
                'assignment_id',
                'siswa_id',
            ]);

            $table->index([
                'assignment_id',
                'submitted_at',
            ]);
        });
    }

    public function down(): void
    {
        if (DB::table('submission_tugas')->exists()) {
            throw new RuntimeException('SUBMISSION_ROLLBACK_REQUIRES_DATA_REVIEW');
        }
        Schema::table('submission_tugas', function (Blueprint $table) {
            $table->dropForeign([
                'assignment_id',
            ]);

            $table->dropUnique([
                'assignment_id',
                'siswa_id',
            ]);

            $table->dropIndex([
                'assignment_id',
                'submitted_at',
            ]);

            $table->dropColumn([
                'assignment_id',
                'jawaban',
            ]);

            $table->foreignId('bahan_ajar_id')
                ->after('id')
                ->constrained('bahan_ajar')
                ->restrictOnDelete();

            $table->unique([
                'bahan_ajar_id',
                'siswa_id',
            ]);

            $table->string('status', 20)
                ->default('submitted')
                ->change();
        });
    }
};