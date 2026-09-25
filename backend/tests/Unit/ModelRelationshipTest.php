<?php

namespace Tests\Unit;

use App\Models\AnggotaKelas;
use App\Models\BahanAjar;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\KomponenNilai;
use App\Models\Mapel;
use App\Models\Nilai;
use App\Models\Presensi;
use App\Models\Rapor;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\SubmissionTugas;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Models\WaliKelas;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Tests\TestCase;

class ModelRelationshipTest extends TestCase
{
    public function test_all_models_have_expected_relationships(): void
    {
        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasMany::class,
            (new Role)->users()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            (new User)->role()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasOne::class,
            (new User)->guru()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasOne::class,
            (new User)->siswa()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasMany::class,
            (new TahunAkademik)->kelas()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasMany::class,
            (new Guru)->jadwalPelajaran()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasMany::class,
            (new Siswa)->anggotaKelas()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            (new Kelas)->tahunAkademik()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            (new AnggotaKelas)->siswa()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            (new WaliKelas)->guru()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasMany::class,
            (new Mapel)->jadwalPelajaran()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            (new JadwalPelajaran)->guru()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            (new Presensi)->siswa()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            (new BahanAjar)->jadwalPelajaran()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            (new SubmissionTugas)->siswa()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            (new KomponenNilai)->jadwalPelajaran()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            (new Nilai)->siswa()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            (new Rapor)->siswa()
        );

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            (new Rapor)->tahunAkademik()
        );

        $this->assertInstanceOf(
            HasMany::class,
            (new User)->bahanAjarDibuat()
        );

        $this->assertInstanceOf(
            HasMany::class,
            (new User)->raporDiunggah()
        );
    }
}