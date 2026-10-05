<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migrasi dari MRBS lama memakai database tiruan (SQLite di memori) dengan struktur tabel mrbs-code.
 */
class ImportLegacyBookingsTest extends TestCase
{
    use RefreshDatabase;

    private string $mapDir;

    private Room $brilian;

    private Room $brilink;

    private User $budi;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'mrbs_']]);
        $this->createLegacySchema();

        $this->mapDir = sys_get_temp_dir().'/mrbs-legacy-test-'.uniqid();
        $this->brilian = Room::factory()->create(['code' => 'BRILIAN', 'name' => 'BRILIAN ROOM']);
        $this->brilink = Room::factory()->create(['code' => 'BRILINK', 'name' => 'BRILINK ROOM']);
        $this->budi = User::factory()->create(['username' => 'budi.hcbp']);
        User::factory()->systemAdmin()->create(['username' => 'sysadmin']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->mapDir);
        parent::tearDown();
    }

    private function createLegacySchema(): void
    {
        $schema = Schema::connection('legacy');
        $schema->create('area', function (Blueprint $t) {
            $t->integer('id');
            $t->string('area_name');
            $t->string('timezone')->nullable();
        });
        $schema->create('room', function (Blueprint $t) {
            $t->integer('id');
            $t->integer('area_id');
            $t->string('room_name');
        });
        $schema->create('users', function (Blueprint $t) {
            $t->integer('id');
            $t->string('name');
            $t->string('display_name')->nullable();
        });
        $schema->create('entry', function (Blueprint $t) {
            $t->integer('id');
            $t->integer('start_time');
            $t->integer('end_time');
            $t->integer('repeat_id')->default(0);
            $t->integer('room_id');
            $t->timestamp('timestamp')->nullable();
            $t->string('create_by');
            $t->string('name');
            $t->char('type', 1)->default('I');
            $t->text('description')->nullable();
            $t->string('No_WA_PIC')->nullable();
            $t->integer('status')->default(0);
        });

        $db = DB::connection('legacy');
        $db->table('area')->insert([['id' => 1, 'area_name' => 'Lantai 5', 'timezone' => 'Asia/Jakarta'], ['id' => 4, 'area_name' => 'Ruang Rapat', 'timezone' => 'Asia/Jakarta']]);
        $db->table('room')->insert([
            ['id' => 6, 'area_id' => 1, 'room_name' => 'Ruang Rapat Lt. 5 Besar'],
            ['id' => 8, 'area_id' => 4, 'room_name' => 'BRILIAN ROOM [Lt 3]'],
            ['id' => 12, 'area_id' => 4, 'room_name' => 'BRILINK ROOM [Lt 5]'],
        ]);
        $db->table('users')->insert([['id' => 5, 'name' => 'hcbp', 'display_name' => 'Bagian HCBP'], ['id' => 9, 'name' => 'ritel', 'display_name' => 'Bagian RITEL']]);

        $at = fn (string $local) => Carbon::parse($local, 'Asia/Jakarta')->timestamp;
        $entry = fn (array $e) => $e + ['repeat_id' => 0, 'type' => 'I', 'description' => null, 'No_WA_PIC' => null, 'status' => 0, 'timestamp' => '2026-01-10 08:00:00'];
        $db->table('entry')->insert([
            // biasa + No. WA PIC
            $entry(['id' => 101, 'start_time' => $at('2026-10-05 09:00'), 'end_time' => $at('2026-10-05 10:30'), 'room_id' => 8, 'create_by' => 'hcbp', 'name' => 'Rapat HC', 'description' => 'Agenda Q4', 'No_WA_PIC' => '081234567890']),
            // eksternal, tentative
            $entry(['id' => 102, 'start_time' => $at('2026-10-06 13:00'), 'end_time' => $at('2026-10-06 14:00'), 'room_id' => 8, 'create_by' => 'hcbp', 'name' => 'Vendor', 'type' => 'E', 'status' => 4]),
            // seri berulang
            $entry(['id' => 103, 'start_time' => $at('2026-10-07 07:30'), 'end_time' => $at('2026-10-07 09:00'), 'room_id' => 12, 'create_by' => 'hcbp', 'name' => 'Weekly', 'repeat_id' => 27]),
            $entry(['id' => 104, 'start_time' => $at('2026-10-14 07:30'), 'end_time' => $at('2026-10-14 09:00'), 'room_id' => 12, 'create_by' => 'hcbp', 'name' => 'Weekly', 'repeat_id' => 27]),
            // ruangan lama tanpa padanan → dipetakan ke BRILINK
            $entry(['id' => 105, 'start_time' => $at('2021-03-01 08:00'), 'end_time' => $at('2021-03-01 09:00'), 'room_id' => 6, 'create_by' => 'hcbp', 'name' => 'Lama']),
            // > 24 jam
            $entry(['id' => 106, 'start_time' => $at('2026-11-02 07:00'), 'end_time' => $at('2026-11-04 18:00'), 'room_id' => 8, 'create_by' => 'hcbp', 'name' => 'Diklat 3 hari']),
            // pembuat belum dipetakan
            $entry(['id' => 107, 'start_time' => $at('2026-10-08 09:00'), 'end_time' => $at('2026-10-08 10:00'), 'room_id' => 8, 'create_by' => 'ritel', 'name' => 'Rapat Ritel']),
        ]);
    }

    private function fillMaps(): void
    {
        $this->artisan('mrbs:import-legacy', ['--make-maps' => true, '--map-dir' => $this->mapDir])->assertSuccessful();
        // users.csv: hcbp → budi.hcbp (ritel sengaja dibiarkan kosong); rooms.csv: ruangan 6 → BRILINK
        $users = File::get("{$this->mapDir}/users.csv");
        File::put("{$this->mapDir}/users.csv", preg_replace('/^hcbp,(.*),$/m', 'hcbp,$1,budi.hcbp', $users));
        $rooms = File::get("{$this->mapDir}/rooms.csv");
        File::put("{$this->mapDir}/rooms.csv", preg_replace('/^6,(.*),$/m', '6,$1,BRILINK', $rooms));
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
