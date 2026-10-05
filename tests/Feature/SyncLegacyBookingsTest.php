<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacyMrbsFixture;
use Tests\TestCase;

/**
 * Sinkronisasi masa transisi MRBS lama → aplikasi ini (`mrbs:sync-legacy`).
 */
class SyncLegacyBookingsTest extends TestCase
{
    use LegacyMrbsFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 08:00:00');
        $this->setUpLegacyMrbs();
        $this->fillMaps();
    }

    protected function tearDown(): void
    {
        $this->tearDownLegacyMrbs();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sync(array $options = []): void
    {
        $this->artisan('mrbs:sync-legacy', $options)->assertSuccessful();
    }

    public function test_first_sync_imports_everything_mapped(): void
    {
        $this->sync();

        $this->assertSame(6, Booking::count()); // 107 (pembuat belum dipetakan) dilewati
        $this->assertNotNull(Booking::where('legacy_id', 101)->value('legacy_modified_at'));
    }

    public function test_updates_bookings_changed_in_legacy(): void
    {
        $this->sync();

        DB::connection('legacy')->table('entry')->where('id', 101)->update([
            'name' => 'Rapat HC (diundur)',
            'start_time' => $this->legacyTimestamp('2026-10-05 13:00'),
            'end_time' => $this->legacyTimestamp('2026-10-05 14:00'),
            'room_id' => 12,
            'timestamp' => '2026-09-30 10:00:00',
        ]);
        $this->artisan('mrbs:sync-legacy')->expectsOutputToContain('1 diperbarui')->assertSuccessful();

        $booking = Booking::where('legacy_id', 101)->firstOrFail();
        $this->assertSame('Rapat HC (diundur)', $booking->title);
        $this->assertSame('2026-10-05 13:00:00', $booking->start_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->brilink->id, $booking->room_id);
        $this->assertSame(6, Booking::count());
    }

    public function test_cancels_upcoming_bookings_deleted_in_legacy_but_keeps_history(): void
    {
        $this->sync();

        // 103 = 7 Okt (mendatang), 105 = 2021 (riwayat)
        DB::connection('legacy')->table('entry')->whereIn('id', [103, 105])->delete();
        $this->artisan('mrbs:sync-legacy')->expectsOutputToContain('1 dibatalkan')->assertSuccessful();

        $upcoming = Booking::where('legacy_id', 103)->firstOrFail();
        $this->assertTrue($upcoming->isCancelled());
        $this->assertSame('Dihapus di MRBS lama', $upcoming->cancel_reason);
        $this->assertFalse(Booking::where('legacy_id', 105)->firstOrFail()->isCancelled());
    }

    public function test_unchanged_booking_cancelled_here_is_not_revived(): void
    {
        $this->sync();
        Booking::where('legacy_id', 104)->update(['status' => 'cancelled', 'cancel_reason' => 'Dibatalkan admin']);

        $this->sync();

        $this->assertTrue(Booking::where('legacy_id', 104)->firstOrFail()->isCancelled());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->artisan('mrbs:sync-legacy', ['--dry-run' => true])->expectsOutputToContain('Simulasi')->assertSuccessful();

        $this->assertSame(0, Booking::count());
    }

    public function test_guards_against_mass_cancellation(): void
    {
        $this->sync();

        // Database lama kosong (mis. salah nama database): tidak ada yang dibatalkan.
        DB::connection('legacy')->table('entry')->delete();
        $this->artisan('mrbs:sync-legacy')->expectsOutputToContain('pembatalan otomatis dilewati')->assertSuccessful();
        $this->assertSame(0, Booking::where('status', 'cancelled')->count());
    }

    public function test_respects_cancellation_limit(): void
    {
        $this->sync();
        DB::connection('legacy')->table('entry')->whereIn('id', [102, 103, 104])->delete();

        $this->artisan('mrbs:sync-legacy', ['--max-cancellations' => 2])->expectsOutputToContain('batas 2')->assertSuccessful();
        $this->assertSame(0, Booking::where('status', 'cancelled')->count());

        $this->sync(['--max-cancellations' => 10]);
        $this->assertSame(3, Booking::where('status', 'cancelled')->count());
    }

    public function test_legacy_bookings_are_read_only_while_sync_is_enabled(): void
    {
        $this->sync();
        $booking = Booking::where('legacy_id', 103)->firstOrFail();
        $admin = User::where('username', 'sysadmin')->firstOrFail();

        config(['mrbs.legacy_sync.enabled' => true]);
        $this->actingAs($admin, 'sanctum');
        $this->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.is_legacy', true)
            ->assertJsonPath('data.legacy_locked', true)
            ->assertJsonPath('data.can.update', false)
            ->assertJsonPath('data.can.cancel', false)
            ->assertJsonPath('data.can.delete', false);
        $this->putJson("/api/bookings/{$booking->id}", ['title' => 'Diubah'])->assertForbidden();
        $this->postJson("/api/bookings/{$booking->id}/cancel")->assertForbidden();
        $this->deleteJson("/api/bookings/{$booking->id}")->assertForbidden();

        // Setelah masa transisi selesai (sinkronisasi dimatikan) booking bisa dikelola seperti biasa.
        config(['mrbs.legacy_sync.enabled' => false]);
        $this->getJson("/api/bookings/{$booking->id}")->assertJsonPath('data.can.update', true)->assertJsonPath('data.legacy_locked', false);
    }

    public function test_check_reports_connection_and_maps(): void
    {
        $this->artisan('mrbs:sync-legacy', ['--check' => true])
            ->expectsOutputToContain('Database MRBS lama terhubung')
            ->assertSuccessful();

        config(['mrbs.legacy_sync.map_dir' => $this->mapDir.'/tidak-ada']);
        $this->artisan('mrbs:sync-legacy', ['--check' => true])->assertFailed();
    }

    public function test_bookings_imported_before_versioning_are_only_backfilled(): void
    {
        $this->artisan('mrbs:import-legacy', ['--map-dir' => $this->mapDir])->assertSuccessful();
        // Simulasikan data impor lama: belum punya versi, dan satu booking sudah dibatalkan di aplikasi ini.
        Booking::query()->update(['legacy_modified_at' => null]);
        Booking::where('legacy_id', 104)->update(['status' => 'cancelled', 'title' => 'Diubah di aplikasi baru']);

        $this->artisan('mrbs:sync-legacy')->expectsOutputToContain('0 diperbarui')->assertSuccessful();

        $booking = Booking::where('legacy_id', 104)->firstOrFail();
        $this->assertTrue($booking->isCancelled());
        $this->assertSame('Diubah di aplikasi baru', $booking->title);
        $this->assertSame(0, Booking::whereNull('legacy_modified_at')->count());
    }
}
