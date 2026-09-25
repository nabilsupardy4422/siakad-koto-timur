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
        'bahan_ajar_id',
        'siswa_id',
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

    public function bahanAjar(): BelongsTo
    {
        return $this->belongsTo(BahanAjar::class);
    }

    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class);
    }
}