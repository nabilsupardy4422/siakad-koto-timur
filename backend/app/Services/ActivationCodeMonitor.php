<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ActivationCodeMonitor
{
    public const STATES = ['not_issued','valid','expired','locked','inconsistent'];

    /** Correlated scalar subquery; no token hash or code is selected. Bound timestamp is shared by each query. */
    public static function sql(): string
    {
        return "CASE WHEN users.account_status = 'active' AND users.requires_activation = 1
            AND EXISTS (SELECT 1 FROM roles WHERE roles.id = users.role_id AND roles.code IN ('GURU','SISWA'))
            THEN COALESCE((SELECT CASE WHEN used_at IS NOT NULL THEN 'inconsistent'
                WHEN expires_at <= ? THEN 'expired' WHEN failed_attempts >= 5 THEN 'locked' ELSE 'valid' END
                FROM user_activation_tokens WHERE user_id = users.id), 'not_issued') ELSE 'not_applicable' END";
    }

    public static function counts(): array
    {
        $counts=array_fill_keys(self::STATES,0);
        $rows=DB::table('users')->selectRaw(self::sql().' AS code_state, COUNT(*) AS aggregate',[now()])->groupBy('code_state')->get();
        foreach ($rows as $row) if (array_key_exists($row->code_state,$counts)) $counts[$row->code_state]=(int)$row->aggregate;
        return $counts;
    }

    public static function decorate(User $user): User
    {
        $row=User::query()->whereKey($user->id)->selectRaw(self::sql().' AS code_state',[now()])
            ->addSelect(['code_expires_at'=>DB::table('user_activation_tokens')->select('expires_at')->whereColumn('user_id','users.id')])->firstOrFail();
        $user->setAttribute('code_state',$row->code_state);
        $user->setAttribute('code_expires_at',$row->code_expires_at);
        return $user;
    }

    /** An opaque, actor-bound comparison value for cancellation. Never return the raw token hash. */
    public static function version(int $actor, int $target, ?object $token): string
    {
        return hash_hmac('sha256',json_encode(['cancel-v1',$actor,$target,$token?(array)$token:null],JSON_THROW_ON_ERROR),config('app.key'));
    }
}
