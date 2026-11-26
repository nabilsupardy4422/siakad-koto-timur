<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivationCodeMonitor;
use Illuminate\Http\Request;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class UserActivationCodeStatusController extends Controller
{
    /**
     * Memeriksa keberadaan kode aktivasi aktif.
     *
     * Kode dianggap aktif jika:
     * - Akun Guru/Siswa masih aktif.
     * - Akun masih memerlukan aktivasi.
     * - Token belum digunakan.
     * - Token belum kedaluwarsa.
     * - Percobaan salah masih di bawah batas.
     *
     * Endpoint ini hanya boleh diakses
     * TU melalui middleware route.
     */
    public function __invoke(Request $request, User $user): JsonResponse
    {
        $user->loadMissing('role');
        $token=DB::table('user_activation_tokens')->where('user_id',$user->id)->first();
        $eligible=$user->account_status === 'active' && $user->requires_activation && in_array($user->role?->code,['GURU','SISWA'],true);
        $state= ! $eligible ? 'not_applicable' : (! $token ? 'not_issued' : ($token->used_at !== null ? 'inconsistent' : (now()->gte($token->expires_at) ? 'expired' : ($token->failed_attempts >= 5 ? 'locked' : 'valid'))));
        return ApiResponse::success([
            'has_active_code'=>$state === 'valid',
            'state'=>$state,
            'expires_at'=>$eligible && $token ? \Carbon\Carbon::parse($token->expires_at,config('app.timezone'))->toIso8601String() : null,
            'can_cancel'=>$eligible && $token && $token->used_at === null,
            'code_version'=>ActivationCodeMonitor::version((int)$request->user()->id,$user->id,$token),
        ])->header('Cache-Control','private, no-store, max-age=0')->header('Pragma','no-cache');
    }
}
