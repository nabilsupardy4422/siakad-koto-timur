<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'jadwal_pelajaran_id',
        'komponen_nilai_id',
        'judul',
        'deskripsi',
        'deadline',
        'file_path',
        'file_name',
        'file_mime_type',
        'file_size',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    public function jadwalPelajaran(): BelongsTo
    {
        return $this->belongsTo(JadwalPelajaran::class);
    }

    public function komponenNilai(): BelongsTo
    {
        return $this->belongsTo(KomponenNilai::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissionTugas(): HasMany
    {
        return $this->hasMany(SubmissionTugas::class);
    }
}