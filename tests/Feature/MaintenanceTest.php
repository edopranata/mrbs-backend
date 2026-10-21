<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Pembersihan harian `mrbs:cleanup` dan pengaturannya di menu Pengaturan.
 */
class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-21 01:00'));
        // Folder storage sementara agar file log asli tidak tersentuh.
        $this->storage = sys_get_temp_dir().'/mrbs-maintenance-'.uniqid();
        File::ensureDirectoryExists("{$this->storage}/logs");
        File::ensureDirectoryExists("{$this->storage}/app");
        File::ensureDirectoryExists("{$this->storage}/framework/views");
        $this->app->useStoragePath($this->storage);
        config(['cache.default' => 'database', 'mrbs.audit_retention_days' => 365, 'mrbs.log_retention_days' => 14, 'mrbs.cache_clear_days' => 7]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function log(string $name, string $content, string $modified): string
    {
        $path = "{$this->storage}/logs/{$name}";
        File::put($path, $content);
        touch($path, Carbon::parse($modified)->getTimestamp());

        return $path;
    }

    public function test_cleanup_removes_old_audit_logs_and_log_entries(): void
    {
        AuditLog::create(['action' => 'auth.login', 'description' => 'Lama', 'created_at' => now()->subDays(400)]);
        AuditLog::create(['action' => 'auth.login', 'description' => 'Baru', 'created_at' => now()->subDays(10)]);

        $old = $this->log('legacy-sync.log', "[2026-09-01 10:00:00] local.INFO: lama\n", '2026-09-01 10:00');
        $active = $this->log('laravel.log', implode("\n", [
            '[2026-09-20 08:00:00] local.ERROR: error lama',
            '#0 stack trace lama',
            '[2026-10-15 09:00:00] local.INFO: masih disimpan',
            '[2026-10-20 23:59:00] local.INFO: terbaru',
        ])."\n", '2026-10-20 23:59');
        $cron = $this->log('cron-schedule.log', "isi putaran terakhir\n", '2026-01-01 00:00');

        $this->artisan('mrbs:cleanup')
            ->expectsOutputToContain('1 audit log, 1 file log, isi lama 1 file log')
            ->assertSuccessful();

        $this->assertSame(['Baru'], AuditLog::where('action', 'auth.login')->pluck('description')->all());
        $this->assertFileDoesNotExist($old);
        $this->assertSame("[2026-10-15 09:00:00] local.INFO: masih disimpan\n[2026-10-20 23:59:00] local.INFO: terbaru\n", File::get($active));
        $this->assertFileExists($cron);

        $audit = AuditLog::where('action', 'system.cleanup')->sole();
        $this->assertSame('Sistem', $audit->user_name);
        $this->assertStringContainsString('Pembersihan otomatis', $audit->description);
    }

    public function test_zero_disables_audit_and_log_cleanup(): void
    {
        config(['mrbs.audit_retention_days' => 0, 'mrbs.log_retention_days' => 0]);
        AuditLog::create(['action' => 'auth.login', 'description' => 'Kuno', 'created_at' => now()->subYears(5)]);
        $path = $this->log('laravel.log', "[2020-01-01 00:00:00] local.INFO: kuno\n", '2020-01-01');

        $this->artisan('mrbs:cleanup')->assertSuccessful();

        $this->assertSame(1, AuditLog::where('description', 'Kuno')->count());
        $this->assertFileExists($path);
    }

    public function test_expired_cache_is_always_purged_and_full_cache_cleared_every_n_days(): void
    {
        Cache::put('masih-berlaku', 'ya', now()->addMonth());
        DB::table('cache')->insert(['key' => 'kedaluwarsa', 'value' => 's:1:"x";', 'expiration' => now()->subMinute()->getTimestamp()]);

        $this->artisan('mrbs:cleanup')->expectsOutputToContain('seluruh cache dikosongkan')->assertSuccessful(); // putaran pertama
        Cache::put('masih-berlaku', 'ya', now()->addMonth());
        DB::table('cache')->insert(['key' => 'kedaluwarsa', 'value' => 's:1:"x";', 'expiration' => now()->subMinute()->getTimestamp()]);

        $this->travel(3)->days();
        $this->artisan('mrbs:cleanup')->expectsOutputToContain('Dihapus: 1 cache kedaluwarsa.')->assertSuccessful();
        $this->assertSame('ya', Cache::get('masih-berlaku'));

        $this->travel(4)->days(); // 7 hari sejak pengosongan terakhir
        $this->artisan('mrbs:cleanup')->expectsOutputToContain('seluruh cache dikosongkan')->assertSuccessful();
        $this->assertNull(Cache::get('masih-berlaku'));
    }

    public function test_cleanup_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('mrbs:cleanup')->assertSuccessful();
    }

    public function test_system_admin_manages_maintenance_settings(): void
    {
        $sys = User::factory()->systemAdmin()->create();

        $this->actingAs($sys)->putJson('/api/settings', ['audit_retention_days' => 90, 'log_retention_days' => 7, 'cache_clear_days' => 0])
            ->assertOk()
            ->assertJsonPath('values.audit_retention_days', 90)
            ->assertJsonPath('maintenance.time', '01:00');
        $this->assertSame(7, config('mrbs.log_retention_days'));
        $this->assertSame([90, 365], array_reverse(AuditLog::where('action', 'settings.updated')->sole()->changes['audit_retention_days']));

        $this->putJson('/api/settings', ['log_retention_days' => 999])->assertUnprocessable();

        // Tidak ikut endpoint publik.
        $this->getJson('/api/settings')->assertOk()->assertJsonMissingPath('audit_retention_days');

        $this->postJson('/api/settings/maintenance', ['clear_cache' => true])
            ->assertOk()
            ->assertJsonPath('maintenance.last_summary', fn ($s) => str_contains($s, 'seluruh cache dikosongkan'));
        $this->assertSame($sys->id, AuditLog::where('action', 'system.cleanup')->sole()->user_id);

        $this->actingAs(User::factory()->admin()->create())->postJson('/api/settings/maintenance')->assertForbidden();
    }
}
