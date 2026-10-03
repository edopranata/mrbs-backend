<?php

namespace Tests\Feature;

use App\Services\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/mrbs-pwa-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents("{$this->dir}/index.html", '<!doctype html><div id="app"></div>');
        file_put_contents("{$this->dir}/sw.js", "const VERSION = 'abc123'");
        config(['mrbs.spa_index' => "{$this->dir}/index.html"]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*"));
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_service_worker_is_served_from_root_without_cache(): void
    {
        $response = $this->get('/sw.js')->assertOk();

        $this->assertStringContainsString("VERSION = 'abc123'", $response->streamedContent());
        $this->assertStringStartsWith('application/javascript', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $this->assertEmpty($response->headers->getCookies());
    }

    public function test_service_worker_returns_404_when_frontend_not_built(): void
    {
        unlink("{$this->dir}/sw.js");

        $this->get('/sw.js')->assertNotFound();
    }

    public function test_manifest_uses_app_name_from_settings(): void
    {
        app(AppSettings::class)->update(['app_name' => 'Booking Kantor', 'app_subtitle' => 'Ruang Rapat Lt 3']);

        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', 'Booking Kantor')
            ->assertJsonPath('description', 'Ruang Rapat Lt 3')
            ->assertJsonPath('start_url', '/')
            ->assertJsonPath('scope', '/')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonCount(3, 'icons')
            ->assertJsonPath('icons.2.purpose', 'maskable');
    }
}
