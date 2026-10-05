<?php

namespace Tests\Concerns;

use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Database MRBS lama tiruan (SQLite di memori, struktur tabel mrbs-code) beserta ruangan & user
 * tujuan, untuk menguji `mrbs:import-legacy` dan `mrbs:sync-legacy`.
 */
trait LegacyMrbsFixture
{
    protected string $mapDir;

    protected Room $brilian;

    protected Room $brilink;

    protected User $budi;

    protected function setUpLegacyMrbs(): void
    {
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'mrbs_']]);
        $this->createLegacySchema();

        $this->mapDir = sys_get_temp_dir().'/mrbs-legacy-test-'.uniqid();
        config(['mrbs.legacy_sync.map_dir' => $this->mapDir]);
        $this->brilian = Room::factory()->create(['code' => 'BRILIAN', 'name' => 'BRILIAN ROOM']);
        $this->brilink = Room::factory()->create(['code' => 'BRILINK', 'name' => 'BRILINK ROOM']);
        $this->budi = User::factory()->create(['username' => 'budi.hcbp']);
        User::factory()->systemAdmin()->create(['username' => 'sysadmin']);
    }

    protected function tearDownLegacyMrbs(): void
    {
        File::deleteDirectory($this->mapDir);
    }

    protected function legacyTimestamp(string $local): int
    {
        return Carbon::parse($local, 'Asia/Jakarta')->timestamp;
    }

    protected function createLegacySchema(): void
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

        $at = fn (string $local) => $this->legacyTimestamp($local);
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

    protected function fillMaps(): void
    {
        $this->artisan('mrbs:import-legacy', ['--make-maps' => true, '--map-dir' => $this->mapDir])->assertSuccessful();
        // users.csv: hcbp → budi.hcbp (ritel sengaja dibiarkan kosong); rooms.csv: ruangan 6 → BRILINK
        $users = File::get("{$this->mapDir}/users.csv");
        File::put("{$this->mapDir}/users.csv", preg_replace('/^hcbp,(.*),$/m', 'hcbp,$1,budi.hcbp', $users));
        $rooms = File::get("{$this->mapDir}/rooms.csv");
        File::put("{$this->mapDir}/rooms.csv", preg_replace('/^6,(.*),$/m', '6,$1,BRILINK', $rooms));
    }
}
