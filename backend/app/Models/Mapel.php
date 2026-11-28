<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mapel extends Model
{
    use HasFactory;

    protected $table = 'mapel';

    protected $fillable = [
        'kode',
        'nama',
        'kkm',
    ];

    protected function casts(): array
    {
        return [
            'kkm' => 'decimal:2',
        ];
    }

    public function directoryVersion(): string
    {
        $attributes = $this->getAttributes();
        unset($attributes['jadwal_pelajaran_count'], $attributes['usage_context']);
        ksort($attributes);
        return hash_hmac('sha256', json_encode($attributes, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function jadwalPelajaran(): HasMany
    {
        return $this->hasMany(JadwalPelajaran::class);
    }
}
