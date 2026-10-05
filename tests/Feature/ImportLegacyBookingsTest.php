<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\LegacyMrbsFixture;
use Tests\TestCase;

/**
 * Migrasi dari MRBS lama memakai database tiruan (SQLite di memori) dengan struktur tabel mrbs-code.
 */
class ImportLegacyBookingsTest extends TestCase
{
    use LegacyMrbsFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLegacyMrbs();
    }

    protected function tearDown(): void
    {
        $this->tearDownLegacyMrbs();
        parent::tearDown();
    }

    public function test_make_maps_matches_rooms_by_name_and_keeps_filled_values(): void
    {
        $this->artisan('mrbs:import-legacy', ['--make-maps' => true, '--map-dir' => $this->mapDir])->assertSuccessful();

        $rooms = File::get("{$this->mapDir}/rooms.csv");
        $this->assertStringContainsString('8,"BRILIAN ROOM [Lt 3]","Ruang Rapat",4,BRILIAN', $rooms);
        $this->assertStringContainsString('12,"BRILINK ROOM [Lt 5]","Ruang Rapat",2,BRILINK', $rooms);
        $this->assertMatchesRegularExpression('/^6,.*,1,$/m', $rooms);
        $this->assertStringContainsString('hcbp,"Bagian HCBP",6,', File::get("{$this->mapDir}/users.csv"));

        // Isian manual tidak ditimpa saat file dibuat ulang.
        $this->fillMaps();
        $this->artisan('mrbs:import-legacy', ['--make-maps' => true, '--map-dir' => $this->mapDir])->assertSuccessful();
        $this->assertStringContainsString(',budi.hcbp', File::get("{$this->mapDir}/users.csv"));
        $this->assertMatchesRegularExpression('/^6,.*,BRILINK$/m', File::get("{$this->mapDir}/rooms.csv"));
    }

    public function test_dry_run_saves_nothing(): void
    {
        $this->fillMaps();

        $this->artisan('mrbs:import-legacy', ['--dry-run' => true, '--map-dir' => $this->mapDir])
            ->expectsOutputToContain('Simulasi impor')
            ->expectsOutputToContain('ritel: 1 booking')
            ->assertSuccessful();

        $this->assertSame(0, Booking::count());
    }

    public function test_imports_bookings_with_mapping_and_is_idempotent(): void
    {
        $this->fillMaps();

        $this->artisan('mrbs:import-legacy', ['--map-dir' => $this->mapDir])->assertSuccessful();

        $this->assertSame(6, Booking::count()); // 107 (ritel) dilewati
        $this->assertNull(Booking::where('legacy_id', 107)->first());

        $first = Booking::where('legacy_id', 101)->firstOrFail();
        $this->assertSame($this->brilian->id, $first->room_id);
        $this->assertSame($this->budi->id, $first->user_id);
        $this->assertSame('2026-10-05 09:00:00', $first->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 10:30:00', $first->end_at->format('Y-m-d H:i:s'));
        $this->assertSame('internal', $first->type->value);
        $this->assertSame('confirmed', $first->status->value);
        $this->assertSame("Agenda Q4\n\nPIC (WA): 081234567890", $first->description);

        $vendor = Booking::where('legacy_id', 102)->firstOrFail();
        $this->assertSame('external', $vendor->type->value);
        $this->assertSame('confirmed', $vendor->status->value); // tentative → biasa

        $weekly = Booking::whereIn('legacy_id', [103, 104])->get();
        $this->assertNotNull($weekly[0]->series_id);
        $this->assertSame($weekly[0]->series_id, $weekly[1]->series_id);

        $this->assertSame($this->brilink->id, Booking::where('legacy_id', 105)->value('room_id'));
        $this->assertSame('2026-11-04 18:00:00', Booking::where('legacy_id', 106)->firstOrFail()->end_at->format('Y-m-d H:i:s'));

        // Dijalankan ulang: tidak ada duplikat.
        $this->artisan('mrbs:import-legacy', ['--map-dir' => $this->mapDir])
            ->expectsOutputToContain('Sudah pernah diimpor')
            ->assertSuccessful();
        $this->assertSame(6, Booking::count());
    }

    public function test_skips_conflicts_with_existing_bookings_unless_allowed(): void
    {
        $this->fillMaps();
        Booking::factory()->create([
            'room_id' => $this->brilian->id,
            'start_at' => '2026-10-05 10:00:00',
            'end_at' => '2026-10-05 11:00:00',
        ]);

        $this->artisan('mrbs:import-legacy', ['--map-dir' => $this->mapDir])
            ->expectsOutputToContain('bentrok dengan')
            ->assertSuccessful();
        $this->assertNull(Booking::where('legacy_id', 101)->first());

        $this->artisan('mrbs:import-legacy', ['--map-dir' => $this->mapDir, '--allow-conflicts' => true])->assertSuccessful();
        $this->assertNotNull(Booking::where('legacy_id', 101)->first());
    }

    public function test_fallback_user_takes_unmapped_creators(): void
    {
        $this->fillMaps();

        $this->artisan('mrbs:import-legacy', ['--map-dir' => $this->mapDir, '--fallback-user' => 'sysadmin'])->assertSuccessful();

        $this->assertSame(User::where('username', 'sysadmin')->value('id'), Booking::where('legacy_id', 107)->value('user_id'));
    }

    public function test_requires_maps_before_import(): void
    {
        $this->artisan('mrbs:import-legacy', ['--map-dir' => $this->mapDir])
            ->expectsOutputToContain('--make-maps')
            ->assertFailed();
    }
}
