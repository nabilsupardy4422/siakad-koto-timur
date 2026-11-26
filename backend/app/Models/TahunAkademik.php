<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TahunAkademik extends Model
{
    use HasFactory;

    public const PERIOD_DEPENDENCIES = ['kelas', 'waliKelas', 'jadwalPelajaran', 'rapor'];

    // Serialize period mutations even when the period table is empty. The TU
    // role is an existing, stable row; callers must already be in a transaction.
    public static function lockForAdministrativeMutation(): \Illuminate\Database\Eloquent\Collection
    {
        Role::query()->where('code', 'TU')->lockForUpdate()->firstOrFail();

        return self::query()->orderBy('id')->lockForUpdate()->get();
    }

    public function hasAcademicDependencies(): bool
    {
        foreach (self::PERIOD_DEPENDENCIES as $relation) {
            if ($this->{$relation}()->exists()) {
                return true;
            }
        }

        return false;
    }

    protected $table = 'tahun_akademik';

    protected $fillable = [
        'tahun_mulai',
        'tahun_selesai',
        'semester',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tahun_mulai' => 'integer',
            'tahun_selesai' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function kelas(): HasMany
    {
        return $this->hasMany(Kelas::class);
    }

    public function waliKelas(): HasMany
    {
        return $this->hasMany(WaliKelas::class);
    }

    public function jadwalPelajaran(): HasMany
    {
        return $this->hasMany(JadwalPelajaran::class);
    }

    public function rapor(): HasMany
    {
        return $this->hasMany(Rapor::class);
    }
}
