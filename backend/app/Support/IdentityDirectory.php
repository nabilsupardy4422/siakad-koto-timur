<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class IdentityDirectory
{
    public static function fields(bool $student): array
    {
        return $student
            ? ['nama_lengkap', 'nisn', 'nipd', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'no_telepon', 'alamat']
            : ['nama_lengkap', 'nip', 'jenis_kelamin', 'no_telepon', 'alamat'];
    }

    public static function apply(Builder $query, Request $request, bool $student): void
    {
        $request->validate([
            'account_state' => ['sometimes', Rule::in(['ready', 'pending', 'inactive'])],
            'completeness' => ['sometimes', Rule::in(['complete', 'incomplete'])],
            'gender' => ['sometimes', Rule::in(['L', 'P'])],
            'sort_order' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);
        if ($request->filled('gender')) {
            $query->where('jenis_kelamin', $request->input('gender'));
        }
        if ($request->filled('account_state')) {
            $state = $request->input('account_state');
            $query->whereHas('user', function (Builder $user) use ($state): void {
                $user->where('account_status', $state === 'inactive' ? 'inactive' : 'active');
                if ($state !== 'inactive') {
                    $user->where('requires_activation', $state === 'pending');
                }
            });
        }
        if ($request->filled('completeness')) {
            $complete = $request->input('completeness') === 'complete';
            $query->where(function (Builder $fields) use ($complete, $student): void {
                foreach (self::fields($student) as $field) {
                    // Field names are static server allowlists, never supplied by the caller.
                    if ($complete) {
                        $fields->whereNotNull($field)->whereRaw("TRIM($field) <> ''");
                    } else {
                        $fields->orWhere(fn (Builder $q) => $q->whereNull($field)->orWhereRaw("TRIM($field) = ''"));
                    }
                }
            });
        }
    }

    public static function completeness($profile, bool $student): array
    {
        $fields = self::fields($student);
        $missing = array_values(array_filter($fields, fn ($field) => $profile->{$field} === null || trim((string) $profile->{$field}) === ''));
        return ['filled' => count($fields) - count($missing), 'total' => count($fields), 'missing' => $missing];
    }
}
