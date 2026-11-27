<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kelas extends Model
{
    use HasFactory;

    protected $table = 'kelas';

    protected $fillable = [
        'tahun_akademik_id',
        'nama',
        'tingkat',
    ];

    protected function casts(): array
    {
        return [
            'tingkat' => 'integer',
        ];
    }

    public function directoryVersion(): string
    {
        return hash_hmac('sha256', json_encode([$this->id, $this->tahun_akademik_id, $this->nama, $this->tingkat, $this->getRawOriginal('updated_at')], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function tahunAkademik(): BelongsTo
    {
        return $this->belongsTo(TahunAkademik::class);
    }

    public function anggotaKelas(): HasMany
    {
        return $this->hasMany(AnggotaKelas::class);
    }

    public function waliKelas(): HasMany
    {
        return $this->hasMany(WaliKelas::class);
    }

    public function jadwalPelajaran(): HasMany
    {
        return $this->hasMany(JadwalPelajaran::class);
    }
}
