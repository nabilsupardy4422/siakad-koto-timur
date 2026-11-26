<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\TahunAkademik;
use App\Services\UserActivationService;
use App\Services\ActivationQr;
use App\Services\ActivationUrlPolicy;
use App\Services\Reporting\ReportDocument;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Bulk actions use exact user IDs, never an unbounded filter in a write request. */
class BulkAccountController extends Controller
{
    private const MAX = 100;
    private const TTL = 300;

    private function authorizeAction(Request $request, string $action): void
    {
        $actor = $request->user();
        $permission = $action === 'issue' ? 'users.reset_password' : 'users.deactivate';
        abort_unless($actor?->role?->code === 'TU' && $actor->account_status === 'active'
            && in_array($permission, Permissions::forRole('TU'), true), 403);
    }

    private function ids(Request $request): array
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'user_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $ids = array_map('intval', array_values($data['user_ids']));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** Row locks share the existing individual activation lifecycle lock order. */
    private function snapshot(array $ids, int $actorId, string $action, bool $lock): array
    {
        $users = User::query()->whereIn('id', $ids)->orderBy('id');
        if ($lock) $users->lockForUpdate();
        $users = $users->with(['role', 'guru:id,user_id', 'siswa:id,user_id'])->get()->keyBy('id');
        $tokens = DB::table('user_activation_tokens')->whereIn('user_id', $ids)->orderBy('user_id');
        if ($lock) $tokens->lockForUpdate();
        $tokens = $tokens->get()->keyBy('user_id');
        $rows = []; $state = [];
        foreach ($ids as $id) {
            $user = $users->get($id);
            $token = $tokens->get($id);
            $role = $user?->role?->code;
            $linked = $role === 'GURU' ? ($user?->guru && ! $user?->siswa) : ($role === 'SISWA' && $user?->siswa && ! $user?->guru);
            $activeCode = $token && $token->used_at === null && $token->failed_attempts < 5 && now()->lt($token->expires_at);
            $reason = match (true) {
                ! $user => 'Akun tidak ditemukan.',
                $id === $actorId => 'Akun sendiri tidak boleh diproses.',
                ! in_array($role, ['GURU', 'SISWA'], true) => 'Hanya Guru dan Siswa.',
                $user->account_status !== 'active' => 'Akun tidak aktif.',
                $action === 'issue' && $user->requires_activation !== true => 'Akun sudah siap digunakan.',
                $action === 'issue' && ! $linked => 'Profil akademik belum terhubung atau konflik.',
                default => null,
            };
            $rows[] = ['user_id'=>$id, 'name'=>$user?->name, 'username'=>$user?->username, 'role_code'=>$role,
                'eligible'=>$reason === null, 'reason'=>$reason, 'has_active_code'=>(bool) $activeCode];
            // Internal only: token hash and credential hash bind the preview to lifecycle changes.
            // Neither hash is returned to clients. A fresh random code invalidates the old snapshot.
            $state[] = [$id, $user?->getRawOriginal('updated_at'), $user?->name, $user?->username,
                $role, $user?->account_status, $user?->requires_activation, $user?->getRawOriginal('password'),
                $user?->guru?->id, $user?->siswa?->id, $token ? (array) $token : null, (bool) $activeCode, $reason];
        }
        return [$rows, $state, $users];
    }

    private function signature(int $actor, string $action, int $expires, string $nonce, array $state): string
    {
        return hash_hmac('sha256', json_encode([$actor, $action, $expires, $nonce, $state], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function noStore($response)
    {
        return $response->header('Cache-Control', 'private, no-store, max-age=0')->header('Pragma', 'no-cache');
    }

    public function preview(Request $request)
    {
        $request->validate(['action'=>['required', 'in:issue,deactivate']]);
        $action = $request->input('action');
        $this->authorizeAction($request, $action);
        $ids = $this->ids($request);
        [$rows, $state] = $this->snapshot($ids, $request->user()->id, $action, false);
        $expires = now()->timestamp + self::TTL;
        $nonce = bin2hex(random_bytes(16));
        return $this->noStore(ApiResponse::success([
            'action'=>$action, 'rows'=>$rows, 'total'=>count($rows),
            'eligible'=>count(array_filter($rows, fn ($r) => $r['eligible'])),
            'reissue_count'=>$action === 'issue' ? count(array_filter($rows, fn ($r) => $r['eligible'] && $r['has_active_code'])) : 0,
            'expires'=>$expires, 'preview_nonce'=>$nonce, 'preview_token'=>$this->signature($request->user()->id, $action, $expires, $nonce, $state),
        ]));
    }

    private function confirmed(Request $request, string $action, callable $write)
    {
        $this->authorizeAction($request, $action);
        $ids = $this->ids($request);
        $data = $request->validate(['expires'=>['required','integer'], 'preview_token'=>['required','string','regex:/^[a-f0-9]{64}$/'], 'preview_nonce'=>['required','string','regex:/^[a-f0-9]{32}$/'],
            'confirm'=>['required','accepted'], 'confirm_reissue'=>['sometimes','boolean']]);
        abort_if($data['expires'] <= now()->timestamp || $data['expires'] > now()->timestamp + self::TTL, 409, 'Preview kedaluwarsa. Periksa ulang.');
        return DB::transaction(function () use ($request, $action, $ids, $data, $write) {
            [$rows, $state, $users] = $this->snapshot($ids, $request->user()->id, $action, true);
            abort_unless(hash_equals($this->signature($request->user()->id, $action, (int) $data['expires'], $data['preview_nonce'], $state), $data['preview_token']), 409, 'Kondisi akun berubah. Periksa ulang sebelum melanjutkan.');
            abort_if($data['expires'] <= now()->timestamp, 409, 'Preview kedaluwarsa. Periksa ulang.');
            abort_if(collect($rows)->contains(fn ($r) => ! $r['eligible']), 422, 'Keluarkan akun yang tidak memenuhi syarat, lalu periksa ulang.');
            abort_if($action === 'issue' && collect($rows)->contains('has_active_code', true) && ! $request->boolean('confirm_reissue'), 422, 'Konfirmasi penggantian kode aktif diperlukan.');
            // Unique, persistent receipt prevents replay even after a code is cancelled or an account reactivated.
            // Receipt and write commit together; a PDF render failure rolls both back.
            $receipt = DB::table('account_bulk_receipts')->insertOrIgnore([
                'preview_hash'=>hash('sha256', $data['preview_token']),
                'expires_at'=>\Carbon\Carbon::createFromTimestamp((int) $data['expires'], config('app.timezone')),
                'created_at'=>now(),
            ]);
            abort_unless($receipt === 1, 409, 'Preview sudah pernah digunakan. Periksa ulang.');
            return $write($users);
        });
    }

    /** Individual legacy JSON endpoint uses the same one-use, locked preview as a one-account batch. */
    public function confirmSingle(Request $request, User $user, UserActivationService $activation): array
    {
        $request->merge(['user_ids'=>[$user->id]]);
        return $this->confirmed($request, 'issue', function ($users) use ($request, $activation) {
            $target=$users->first();
            return ['user_id'=>$target->id,'username'=>$target->username,'name'=>$target->name,'role_code'=>$target->role->code,
                ...$activation->issue($target,(int)$request->user()->id)];
        });
    }

    public function issue(Request $request, UserActivationService $activation)
    {
        // The activation address is administrator configuration, never a user-supplied URL.
        $url = config('activation.public_url');
        abort_unless(ActivationUrlPolicy::allows($url, app()->environment(), config('activation.lan_host')),
            422, 'Alamat aktivasi resmi belum dikonfigurasi dengan benar. Hubungi administrator sistem.');
        $qr = ActivationQr::pngDataUri($url);
        $bytes = $this->confirmed($request, 'issue', function ($users) use ($request, $activation, $url, $qr) {
            $periods = TahunAkademik::query()->where('is_active', true)->limit(2)->pluck('id');
            $period = $periods->count() === 1 ? $periods->first() : null;
            $slips = [];
            foreach ($users as $user) {
                $class = null;
                if ($period && $user->role->code === 'SISWA') {
                    $members = $user->siswa->anggotaKelas()->whereHas('kelas', fn ($q) => $q->where('tahun_akademik_id', $period))
                        ->whereDate('tanggal_mulai','<=',now('Asia/Jakarta')->toDateString())
                        ->where(fn ($q) => $q->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai','>=',now('Asia/Jakarta')->toDateString()))
                        ->with('kelas')->limit(2)->get();
                    if ($members->count() === 1) $class = $members->first()->kelas?->nama;
                }
                $slips[] = ['name'=>$user->name, 'username'=>$user->username, 'role'=>$user->role->name, 'class'=>$class,
                    ...$activation->issue($user, (int) $request->user()->id)];
            }
            // Render in transaction. No secret file is placed in public storage; on render failure roll back.
            try {
                $bytes = Pdf::loadView('accounts.bulk-activation', ['slips'=>$slips, 'url'=>$url, 'qr'=>$qr, 'logo'=>ReportDocument::logo()])
                    ->setPaper('a4', 'portrait')->setOption('isRemoteEnabled', false)->output();
                if (! str_starts_with($bytes, '%PDF-')) throw new \RuntimeException('Invalid PDF');
                return $bytes;
            } catch (\Throwable) {
                // Do not report exception arguments containing slip data.
                abort(500, 'Slip PDF gagal dibuat. Tidak ada kode baru yang disimpan.');
            }
        });
        Log::info('Bulk activation codes issued', ['actor_user_id'=>$request->user()->id,'target_user_ids'=>$this->ids($request)]);
        return $this->noStore(response($bytes, 200, ['Content-Type'=>'application/pdf', 'Content-Disposition'=>'attachment; filename="slip-aktivasi-siakad.pdf"']));
    }

    public function deactivate(Request $request)
    {
        $count = $this->confirmed($request, 'deactivate', function ($users) {
            foreach ($users as $user) {
                $user->update(['account_status'=>'inactive']);
                $user->tokens()->delete();
                DB::table('user_activation_tokens')->where('user_id',$user->id)->whereNull('used_at')->delete();
            }
            return $users->count();
        });
        Log::info('Bulk academic accounts deactivated', ['actor_user_id'=>$request->user()->id,'target_user_ids'=>$this->ids($request)]);
        return $this->noStore(ApiResponse::message('Akun akademik berhasil dinonaktifkan.', ['total'=>$count]));
    }
}
