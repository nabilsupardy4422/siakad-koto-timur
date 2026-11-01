<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BahanAjarLampiran extends Model
{
    use HasFactory;

    protected $table = 'bahan_ajar_lampiran';

    protected $fillable = [
        'bahan_ajar_id',
        'file_path',
        'file_name',
        'file_mime_type',
        'file_size',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function bahanAjar(): BelongsTo
    {
        return $this->belongsTo(BahanAjar::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
