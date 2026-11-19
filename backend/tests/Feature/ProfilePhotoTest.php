<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    // ==========================================
    // HELPER MEMBUAT AKUN PENGUJIAN
    // ==========================================

    private function makeUser(
        string $username,
        string $roleCode
    ): User {
        $role = Role::query()->firstOrCreate(
            ['code' => $roleCode],
            ['name' => $roleCode]
        );

        $user = new User();

        $user->forceFill([
            'username' => $username,
            'name' => 'Foto Profil Pengujian',
            'email' => null,
            'password' => 'TestPassword#2026',
            'role_id' => $role->id,
            'account_status' => 'active',
            'requires_activation' => false,
        ])->save();

        return $user;
    }

    // ==========================================
    // TEST 1: PENGGUNA TANPA LOGIN DITOLAK
    // ==========================================

    public function test_guest_cannot_access_profile_photo(): void
    {
        $this->getJson('/api/profile/photo')
            ->assertUnauthorized();

        $this->postJson('/api/profile/photo')
            ->assertUnauthorized();

        $this->deleteJson('/api/profile/photo')
            ->assertUnauthorized();
    }

    // ==========================================
    // TEST 2: SISWA UPLOAD, LIHAT, HAPUS FOTO
    // ==========================================

    public function test_siswa_can_upload_view_and_delete_own_photo(): void
    {
        Storage::fake('local');

        $user = $this->makeUser(
            'photo.siswa',
            'SISWA'
        );

        Sanctum::actingAs($user);

        // Akun belum memiliki foto profil.
        $this->get('/api/profile/photo')
            ->assertNoContent();

        // Upload foto profil.
        $this->post('/api/profile/photo', [
            'photo' => UploadedFile::fake()
                ->image('avatar.jpg', 200, 200),
        ])
            ->assertOk()
            ->assertJsonPath('data.has_photo', true);

        // Perbarui objek autentikasi setelah upload.
        $user->refresh();

        // Pastikan lokasi foto tersimpan.
        $path = $user->profile_photo_path;

        $this->assertNotNull($path);

        $this->assertStringStartsWith(
            'profile-photos/',
            $path
        );

        // Pastikan file benar-benar tersimpan.
        Storage::disk('local')
            ->assertExists($path);

        // Endpoint harus mengembalikan gambar.
        $this->get('/api/profile/photo')
            ->assertOk()
            ->assertHeader(
                'Content-Type',
                'image/jpeg'
            );

        // Hapus foto profil.
        $this->deleteJson('/api/profile/photo')
            ->assertOk()
            ->assertJsonPath('data.has_photo', false);

        // Perbarui objek autentikasi setelah hapus.
        $user->refresh();

        // Kolom foto harus kembali null.
        $this->assertNull(
            $user->profile_photo_path
        );

        // File lama harus dihapus dari storage.
        Storage::disk('local')
            ->assertMissing($path);

        // Foto tidak lagi tersedia.
        $this->get('/api/profile/photo')
            ->assertNoContent();
    }

    // ==========================================
    // TEST 3: GANTI FOTO MENGHAPUS FILE LAMA
    // ==========================================

    public function test_replacing_photo_removes_old_file(): void
    {
        Storage::fake('local');

        $user = $this->makeUser(
            'photo.guru',
            'GURU'
        );

        Sanctum::actingAs($user);

        // Upload foto pertama.
        $this->post('/api/profile/photo', [
            'photo' => UploadedFile::fake()
                ->image('first.jpg'),
        ])->assertOk();

        $user->refresh();

        $previous = $user->profile_photo_path;

        $this->assertNotNull($previous);

        Storage::disk('local')
            ->assertExists($previous);

        // Upload foto pengganti.
        $this->post('/api/profile/photo', [
            'photo' => UploadedFile::fake()
                ->image('second.png'),
        ])->assertOk();

        $user->refresh();

        $newPath = $user->profile_photo_path;

        // Path foto baru harus berbeda.
        $this->assertNotSame(
            $previous,
            $newPath
        );

        // File lama harus terhapus.
        Storage::disk('local')
            ->assertMissing($previous);

        // File baru harus tersedia.
        Storage::disk('local')
            ->assertExists($newPath);
    }

    // ==========================================
    // TEST 4: FILE TIDAK VALID DITOLAK
    // ==========================================

    public function test_invalid_file_is_rejected(): void
    {
        Storage::fake('local');

        $user = $this->makeUser(
            'photo.tu',
            'TU'
        );

        Sanctum::actingAs($user);

        // File TXT tidak boleh digunakan.
        $this->post('/api/profile/photo', [
            'photo' => UploadedFile::fake()->create(
                'notes.txt',
                30,
                'text/plain'
            ),
        ])->assertStatus(422);

        // Tidak boleh ada perubahan foto.
        $user->refresh();

        $this->assertNull(
            $user->profile_photo_path
        );
    }

    // ==========================================
    // TEST 5: FOTO HANYA MILIK AKUN SENDIRI
    // ==========================================

    public function test_photo_is_private_to_its_owner(): void
    {
        Storage::fake('local');

        // Buat akun pemilik foto.
        $owner = $this->makeUser(
            'photo.owner',
            'KEPALA_SEKOLAH'
        );

        // Buat akun lain.
        $other = $this->makeUser(
            'photo.other',
            'TU'
        );

        // Login sebagai pemilik foto.
        Sanctum::actingAs($owner);

        $this->post('/api/profile/photo', [
            'photo' => UploadedFile::fake()
                ->image('owner.jpg'),
        ])->assertOk();

        $owner->refresh();

        $ownerPath = $owner->profile_photo_path;

        $this->assertNotNull($ownerPath);

        Storage::disk('local')
            ->assertExists($ownerPath);

        // Beralih ke akun lain.
        Sanctum::actingAs($other);

        // Akun lain tidak mendapatkan foto pemilik.
        $this->get('/api/profile/photo')
            ->assertNoContent();

        // Foto milik akun pertama tetap tersimpan.
        $owner->refresh();

        $this->assertSame(
            $ownerPath,
            $owner->profile_photo_path
        );

        Storage::disk('local')
            ->assertExists($ownerPath);
    }

    // ==========================================
    // TEST 6: BELUM AKTIVASI TIDAK BOLEH UPLOAD
    // ==========================================

    public function test_pending_activation_cannot_upload_photo(): void
    {
        Storage::fake('local');

        $user = $this->makeUser(
            'photo.pending',
            'GURU'
        );

        // Tandai sebagai akun belum aktivasi.
        $user->forceFill([
            'requires_activation' => true,
        ])->save();

        Sanctum::actingAs($user);

        // Sistem harus menolak upload.
        $this->post('/api/profile/photo', [
            'photo' => UploadedFile::fake()
                ->image('avatar.jpg'),
        ])->assertStatus(403);

        $user->refresh();

        // Pastikan tidak ada foto yang tersimpan.
        $this->assertNull(
            $user->profile_photo_path
        );
    }
}
