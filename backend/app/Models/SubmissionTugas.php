<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionTugas extends Model
{
    use HasFactory;

    protected $table = 'submission_tugas';

    protected $fillable = [
        'assignment_id',
        'siswa_id',
        'jawaban',
        'file_path',
        'file_name',
        'file_mime_type',
        'file_size',
        'submitted_at',
        'status',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class);
    }
}