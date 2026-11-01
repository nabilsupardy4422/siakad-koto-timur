<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionTugasLampiran extends Model
{
    protected $table = 'submission_tugas_lampiran';

    protected $fillable = [
        'submission_tugas_id',
        'file_path',
        'file_name',
        'file_mime_type',
        'file_size',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function submissionTugas(): BelongsTo
    {
        return $this->belongsTo(
            SubmissionTugas::class,
            'submission_tugas_id'
        );
    }
}