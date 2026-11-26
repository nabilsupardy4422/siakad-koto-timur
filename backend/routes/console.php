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
