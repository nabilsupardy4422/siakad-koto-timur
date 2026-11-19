<?php

namespace App\Http\Controllers\Api\Profile;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ProfilePhotoController extends Controller
{
    private const DIRECTORY = 'profile-photos/';

    // ==========================================
    // LIHAT FOTO PROFIL SENDIRI
    // ==========================================

    public function show(Request $request): Response
    {
        $path = $request->user()->profile_photo_path;

        if (! $path) {
            return response()->noContent(204, [
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $disk = Storage::disk('local');

        if (
            ! str_starts_with($path, self::DIRECTORY) ||
            str_contains($path, '..') ||
            ! $disk->exists($path)
        ) {
            abort(404, 'Foto profil tidak ditemukan.');
        }

        // Tentukan tipe gambar dari ekstensi
        // file yang disimpan oleh Laravel.
        $extension = strtolower(
            pathinfo($path, PATHINFO_EXTENSION)
        );

        $mimeType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => null,
        };

        if ($mimeType === null) {
            abort(404, 'Format foto tidak didukung.');
        }

        // Gunakan get() yang tersedia pada
        // kontrak Filesystem Laravel.
        $contents = $disk->get($path);

        if (! is_string($contents)) {
            abort(404, 'Foto profil tidak dapat dibaca.');
        }

        // Kirim respons gambar menggunakan
        // response() bawaan Laravel.
        // Maksimal ukuran upload hanya 2 MB.
        return response($contents, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ==========================================
    // UPLOAD / GANTI FOTO
    // ==========================================

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'photo' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                'dimensions:max_width=4096,max_height=4096',
            ],
        ], [
            'photo.required' =>
                'Pilih foto terlebih dahulu.',

            'photo.image' =>
                'File harus berupa gambar.',

            'photo.mimes' =>
                'Gunakan JPG, PNG, atau WebP.',

            'photo.max' =>
                'Ukuran foto maksimal 2 MB.',

            'photo.dimensions' =>
                'Dimensi foto maksimal 4096 x 4096 piksel.',
        ]);

        $newPath = $validated['photo']->store(
            'profile-photos',
            'local'
        );

        if (! $newPath) {
            abort(500, 'Foto gagal disimpan.');
        }

        try {
            $oldPath = DB::transaction(
                function () use ($request, $newPath): ?string {
                    $user = User::query()
                        ->lockForUpdate()
                        ->findOrFail(
                            $request->user()->getKey()
                        );

                    $previous = $user->profile_photo_path;

                    $user->forceFill([
                        'profile_photo_path' => $newPath,
                    ])->save();

                    return $previous;
                }
            );
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($newPath);

            throw $exception;
        }

        // Hapus foto lama setelah database
        // berhasil menyimpan foto baru.
        if (
            $oldPath &&
            str_starts_with($oldPath, self::DIRECTORY)
        ) {
            Storage::disk('local')->delete($oldPath);
        }

        return response()->json([
            'message' =>
                'Foto profil berhasil disimpan.',

            'data' => [
                'has_photo' => true,
            ],
        ]);
    }

    // ==========================================
    // HAPUS FOTO PROFIL SENDIRI
    // ==========================================

    public function destroy(Request $request): JsonResponse
    {
        $oldPath = DB::transaction(
            function () use ($request): ?string {
                $user = User::query()
                    ->lockForUpdate()
                    ->findOrFail(
                        $request->user()->getKey()
                    );

                $previous = $user->profile_photo_path;

                $user->forceFill([
                    'profile_photo_path' => null,
                ])->save();

                return $previous;
            }
        );

        if (
            $oldPath &&
            str_starts_with($oldPath, self::DIRECTORY)
        ) {
            Storage::disk('local')->delete($oldPath);
        }

        return response()->json([
            'message' =>
                'Foto profil berhasil dihapus.',

            'data' => [
                'has_photo' => false,
            ],
        ]);
    }
}
