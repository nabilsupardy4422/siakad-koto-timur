<?php

namespace Tests\Unit;

use App\Models\TahunAkademik;
use App\Support\AcademicContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_active_academic_context(): void
    {
        TahunAkademik::create([
            'tahun_mulai' => 2025,
            'tahun_selesai' => 2026,
            'semester' => 'GENAP',
            'is_active' => false,
        ]);

        $active = TahunAkademik::create([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 'GANJIL',
            'is_active' => true,
        ]);

        $context = AcademicContext::active();

        $this->assertSame($active->id, $context->id);
        $this->assertSame(2026, $context->tahun_mulai);
        $this->assertSame(2027, $context->tahun_selesai);
        $this->assertSame('GANJIL', $context->semester);
    }

    public function test_it_can_resolve_specific_academic_context(): void
    {
        $academicYear = TahunAkademik::create([
            'tahun_mulai' => 2025,
            'tahun_selesai' => 2026,
            'semester' => 'GENAP',
            'is_active' => false,
        ]);

        $context = AcademicContext::resolve($academicYear->id);

        $this->assertSame($academicYear->id, $context->id);
    }

    public function test_it_resolves_active_context_when_id_is_not_provided(): void
    {
        $active = TahunAkademik::create([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 'GANJIL',
            'is_active' => true,
        ]);

        $context = AcademicContext::resolve();

        $this->assertSame($active->id, $context->id);
    }

    public function test_it_generates_academic_context_label(): void
    {
        $academicYear = TahunAkademik::create([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 'GANJIL',
            'is_active' => true,
        ]);

        $this->assertSame(
            '2026/2027 GANJIL',
            AcademicContext::label($academicYear)
        );
    }
}