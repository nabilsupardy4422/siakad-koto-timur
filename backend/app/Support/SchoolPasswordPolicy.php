<?php

namespace App\Support;

/** The policy applies only when a new human-chosen password is written. */
final class SchoolPasswordPolicy
{
    public const MIN_CHARACTERS = 12;
    public const MAX_BYTES = 72;

    /** Unicode code points, not UTF-16 units or grapheme clusters. */
    public static function violations(mixed $value): array
    {
        if (! is_string($value) || preg_match('//u', $value) !== 1) {
            return ['Password harus berupa teks UTF-8 yang valid.'];
        }
        if (strlen($value) > self::MAX_BYTES) {
            return ['Password maksimal 72 byte. Karakter Unicode dapat memakai lebih dari satu byte.'];
        }
        $errors = [];
        if (preg_match_all('/./us', $value) < self::MIN_CHARACTERS) {
            $errors[] = 'Password minimal 12 karakter.';
        }
        if (preg_match('/\p{Cc}/u', $value)) {
            $errors[] = 'Password tidak boleh mengandung karakter kontrol.';
        }
        if (! preg_match('/\p{Lu}/u', $value) || ! preg_match('/\p{Ll}/u', $value)) {
            $errors[] = 'Password harus mengandung huruf besar dan huruf kecil.';
        }
        if (! preg_match('/\p{N}/u', $value)) {
            $errors[] = 'Password harus mengandung angka.';
        }
        if (! preg_match('/[\p{P}\p{S}]/u', $value)) {
            $errors[] = 'Password harus mengandung simbol; spasi bukan simbol.';
        }
        return $errors;
    }

    public static function rules(bool $confirmed = true): array
    {
        return [
            'bail', 'required', 'string',
            static function ($attribute, $value, $fail): void {
                foreach (self::violations($value) as $message) {
                    $fail($message);
                }
            },
            ...($confirmed ? ['confirmed'] : []),
        ];
    }
}
