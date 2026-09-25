<?php

namespace App\Support;

use App\Models\TahunAkademik;

class AcademicContext
{
    public static function active(): TahunAkademik
    {
        return TahunAkademik::query()
            ->where('is_active', true)
            ->firstOrFail();
    }

    public static function find(int $id): TahunAkademik
    {
        return TahunAkademik::query()
            ->whereKey($id)
            ->firstOrFail();
    }

    public static function resolve(?int $id = null): TahunAkademik
    {
        if ($id !== null) {
            return self::find($id);
        }

        return self::active();
    }

    public static function label(TahunAkademik $academicYear): string
    {
        return sprintf(
            '%d/%d %s',
            $academicYear->tahun_mulai,
            $academicYear->tahun_selesai,
            $academicYear->semester
        );
    }
}