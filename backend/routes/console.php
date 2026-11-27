<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expired receipts cannot be accepted by the controller. Keep a one-day safety margin.
Artisan::command('accounts:prune-receipts', function () {
    $count=\Illuminate\Support\Facades\DB::table('account_bulk_receipts')->where('expires_at','<',now()->subDay())->delete();
    $this->info("Expired account receipts removed: {$count}");
})->purpose('Remove expired one-use account preview receipts');
\Illuminate\Support\Facades\Schedule::command('accounts:prune-receipts')->dailyAt('03:15')->timezone('Asia/Jakarta')->withoutOverlapping();

// Local/operator diagnostic only: never mutate or print personal information.
Artisan::command('identity:audit-integration', function () {
    $counts = app(\App\Support\IdentityIntegrationAudit::class)->counts();
    $this->table(['Check', 'Count'], collect($counts)->map(fn ($count, $key) => [$key, $count])->values()->all());
    $this->info('Read-only audit. Review nonzero findings; no records were changed. Counts can overlap. Exactly one active period is expected.');
})->purpose('Inspect identity, session and academic relationship integrity without changing data');

Artisan::command('memberships:audit-integrity', function () {
    $db = \Illuminate\Support\Facades\DB::class;
    $invalid = $db::table('anggota_kelas')->whereNull('tanggal_mulai')->orWhereColumn('tanggal_selesai', '<', 'tanggal_mulai')->count();
    $pairs = $db::table('anggota_kelas as a')->join('anggota_kelas as b', function ($join) {
        $join->on('a.siswa_id', '=', 'b.siswa_id')->on('a.id', '<', 'b.id');
    })->join('kelas as ca', 'a.kelas_id', '=', 'ca.id')->join('kelas as cb', 'b.kelas_id', '=', 'cb.id')
        ->whereColumn('ca.tahun_akademik_id', 'cb.tahun_akademik_id')
        ->where(fn ($q) => $q->whereNull('a.tanggal_selesai')->orWhereColumn('a.tanggal_selesai', '>=', 'b.tanggal_mulai'))
        ->where(fn ($q) => $q->whereNull('b.tanggal_selesai')->orWhereColumn('b.tanggal_selesai', '>=', 'a.tanggal_mulai'))->count();
    $this->table(['Pemeriksaan', 'Jumlah'], [['Interval tidak valid', $invalid], ['Pasangan interval bertabrakan dalam periode', $pairs]]);
    $this->info('Read-only; tidak ada perubahan data. Jumlah konflik adalah pasangan, bukan jumlah siswa.');
})->purpose('Inspect membership intervals without mutating data');
