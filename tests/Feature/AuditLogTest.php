<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 08:00'));
        $this->admin = User::factory()->admin()->create(['name' => 'Ani Admin', 'username' => 'ani']);
        $this->room = Room::factory()->create(['name' => 'BRILIAN ROOM', 'capacity' => 10]);
    }

    private function latest(string $action): AuditLog
    {
        return AuditLog::where('action', $action)->latest('id')->firstOrFail();
    }

    public function test_only_admin_and_system_admin_can_read_audit_log(): void
    {
        $this->actingAs($this->admin)->getJson('/api/audit-logs')->assertOk()->assertJsonPath('categories.booking', 'Booking');
        $this->actingAs(User::factory()->systemAdmin()->create())->getJson('/api/audit-logs')->assertOk();
        $this->actingAs(User::factory()->create())->getJson('/api/audit-logs')->assertForbidden();
        $this->actingAs(User::factory()->viewer()->create())->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_login_success_and_failure_are_recorded_without_password(): void
    {
        $user = User::factory()->create(['username' => 'budi', 'password' => 'rahasia123']);

        $this->postJson('/api/auth/login', ['username' => 'budi', 'password' => 'salah-sekali'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['username' => 'tidakada', 'password' => 'apa-saja'])->assertUnprocessable();
        $token = $this->postJson('/api/auth/login', ['username' => 'budi', 'password' => 'rahasia123', 'device_name' => 'android'])
            ->assertOk()->json('token');
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->assertSame($user->id, $this->latest('auth.logout')->user_id);

        $failed = AuditLog::where('action', 'auth.login_failed')->orderBy('id')->get();
        $this->assertSame($user->id, $failed[0]->user_id);
        $this->assertSame('Login gagal: password salah', $failed[0]->description);
        $this->assertNull($failed[1]->user_id);
        $this->assertSame('tidakada', $failed[1]->user_name);

        $login = $this->latest('auth.login');
        $this->assertSame($user->id, $login->user_id);
        $this->assertSame('android', $login->meta['Perangkat']);
        $this->assertSame('127.0.0.1', $login->ip_address);
        $this->assertStringNotContainsString('rahasia123', AuditLog::all()->toJson());
    }

    public function test_booking_lifecycle_is_recorded_with_changes(): void
    {
        $owner = User::factory()->create(['name' => 'Budi', 'username' => 'budi']);
        $other = Room::factory()->create(['name' => 'WAR ROOM', 'capacity' => 10]);

        $id = $this->actingAs($owner)->postJson('/api/bookings', [
            'room_id' => $this->room->id, 'title' => 'Rapat Tim', 'participants' => 5,
            'start_at' => '2026-10-22 09:00', 'end_at' => '2026-10-22 10:00',
        ])->assertCreated()->json('data.id');
        $created = $this->latest('booking.created');
        $this->assertSame($owner->id, $created->user_id);
        $this->assertSame('Membuat booking "Rapat Tim" — BRILIAN ROOM, 22 Okt 2026 09:00–10:00', $created->description);

        // Admin memindahkan booking orang lain.
        $this->actingAs($this->admin)->putJson("/api/bookings/{$id}", [
            'room_id' => $other->id, 'start_at' => '2026-10-22 13:00', 'end_at' => '2026-10-22 14:00',
        ])->assertOk();
        $moved = $this->latest('booking.updated');
        $this->assertStringStartsWith('Memindahkan booking "Rapat Tim" — WAR ROOM', $moved->description);
        $this->assertSame(['BRILIAN ROOM', 'WAR ROOM'], $moved->changes['room']);
        $this->assertSame(['2026-10-22 09:00', '2026-10-22 13:00'], $moved->changes['start_at']);
        $this->assertArrayNotHasKey('title', $moved->changes);
        $this->assertSame('Budi (budi)', $moved->meta['Pemilik booking']);

        $this->actingAs($this->admin)->putJson("/api/bookings/{$id}", ['title' => 'Rapat Tim Q4'])->assertOk();
        $this->assertStringStartsWith('Mengubah booking', $this->latest('booking.updated')->description);

        $this->actingAs($owner)->postJson("/api/bookings/{$id}/cancel", ['reason' => 'Diundur'])->assertOk();
        $cancelled = $this->latest('booking.cancelled');
        $this->assertSame('Diundur', $cancelled->meta['Alasan']);
        $this->assertArrayNotHasKey('Pemilik booking', $cancelled->meta);

        $this->actingAs($this->admin)->deleteJson("/api/bookings/{$id}")->assertOk();
        $deleted = $this->latest('booking.deleted');
        $this->assertSame($id, $deleted->subject_id);
        $this->assertStringContainsString('Rapat Tim Q4', $deleted->description);
    }

    public function test_room_user_and_settings_changes_are_recorded(): void
    {
        $this->actingAs($this->admin)->putJson("/api/rooms/{$this->room->id}", [
            'code' => $this->room->code, 'name' => 'BRILIAN ROOM', 'floor' => $this->room->floor, 'capacity' => 12,
        ])->assertOk();
        $this->assertSame([10, 12], $this->latest('room.updated')->changes['capacity']);

        $user = User::factory()->create(['name' => 'Citra', 'username' => 'citra']);
        $this->actingAs($this->admin)->putJson("/api/users/{$user->id}", [
            'name' => 'Citra', 'username' => 'citra', 'email' => $user->email, 'role' => 'viewer',
            'is_active' => false, 'password' => 'passwordbaru1', 'password_confirmation' => 'passwordbaru1',
        ])->assertOk();
        $changes = $this->latest('user.updated')->changes;
        $this->assertSame(['User', 'View Only'], $changes['role']);
        $this->assertSame(['Ya', 'Tidak'], $changes['is_active']);
        $this->assertSame([null, '(diganti)'], $changes['password']);
        $this->assertStringNotContainsString('passwordbaru1', AuditLog::all()->toJson());

        $sys = User::factory()->systemAdmin()->create();
        $this->actingAs($sys)->putJson('/api/settings', ['app_name' => 'Booking Ruang'])->assertOk();
        $this->assertSame(['MRBS', 'Booking Ruang'], $this->latest('settings.updated')->changes['app_name']);
    }

    public function test_list_filters_and_shapes_entries(): void
    {
        $this->actingAs($this->admin)->postJson('/api/rooms', ['code' => 'R9', 'name' => 'Ruang Baru', 'floor' => 9, 'capacity' => 8])->assertCreated();
        $this->actingAs($this->admin)->putJson('/api/auth/profile', ['name' => 'Ani Admin', 'phone' => '0811'])->assertOk();

        $this->actingAs($this->admin)->getJson('/api/audit-logs?category=room')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action_label', 'Tambah ruangan')
            ->assertJsonPath('data.0.category_label', 'Ruangan')
            ->assertJsonPath('data.0.user_name', 'Ani Admin')
            ->assertJsonPath('data.0.changes.0.label', 'Kode');

        $this->getJson('/api/audit-logs?search=Ruang%20Baru')->assertJsonCount(1, 'data');
        $this->getJson('/api/audit-logs?from=2026-10-22')->assertJsonCount(0, 'data');
        $this->getJson('/api/audit-logs?category=salah')->assertUnprocessable();
    }

    public function test_activity_can_be_filtered_per_user(): void
    {
        $budi = User::factory()->create(['name' => 'Budi', 'username' => 'budi', 'password' => 'rahasia123']);
        $this->postJson('/api/auth/login', ['username' => 'budi', 'password' => 'salah-sekali'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['username' => 'tamu', 'password' => 'apa-saja'])->assertUnprocessable();
        $this->actingAs($budi)->putJson('/api/auth/profile', ['name' => 'Budi S'])->assertOk();
        $this->actingAs($this->admin)->putJson('/api/auth/profile', ['name' => 'Ani A'])->assertOk();

        $this->actingAs($this->admin)->getJson("/api/audit-logs?user_id={$budi->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.user.id', $budi->id);

        $this->getJson('/api/audit-logs?user_name=tamu')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.description', 'Login gagal: username tidak terdaftar');
    }

    public function test_deleted_actor_name_is_kept(): void
    {
        $user = User::factory()->create(['name' => 'Dodi', 'username' => 'dodi']);
        $this->actingAs($user)->putJson('/api/auth/profile', ['name' => 'Dodi S'])->assertOk();
        $this->actingAs($this->admin)->deleteJson("/api/users/{$user->id}")->assertOk();

        $entry = $this->actingAs($this->admin)->getJson('/api/audit-logs?category=auth')->json('data.0');
        $this->assertNull($entry['user']);
        $this->assertSame('Dodi S (dodi)', $entry['user_name']);
    }
}
