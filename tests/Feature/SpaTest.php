<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpaTest extends TestCase
{
    private string $index;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = sys_get_temp_dir().'/mrbs-spa-'.bin2hex(random_bytes(6)).'.html';
        file_put_contents($this->index, '<!doctype html><div id="app"></div>');
        config(['mrbs.spa_index' => $this->index]);
    }

    protected function tearDown(): void
    {
        @unlink($this->index);

        parent::tearDown();
    }

    public function test_root_serves_frontend_index(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertStringContainsString('<div id="app">', $response->streamedContent());
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
    }

    public function test_deep_links_serve_frontend_index(): void
    {
        foreach (['/jadwal', '/booking-saya', '/admin/users', '/admin/pengaturan'] as $url) {
            $this->assertStringContainsString('<div id="app">', $this->get($url)->assertOk()->streamedContent());
        }
    }

    public function test_spa_pages_do_not_start_a_session(): void
    {
        $this->assertEmpty($this->get('/jadwal')->headers->getCookies());
    }

    public function test_unknown_api_urls_still_return_json_404(): void
    {
        $this->getJson('/api/tidak-ada')->assertNotFound()->assertJsonStructure(['message']);
        $this->get('/api')->assertNotFound();
    }

    public function test_api_and_health_routes_are_not_affected(): void
    {
        $this->getJson('/api/settings')->assertOk()->assertJsonStructure(['app_name']);
        $this->get('/up')->assertOk();
    }

    public function test_helpful_message_when_frontend_is_not_built(): void
    {
        config(['mrbs.spa_index' => '/tidak/ada/index.html']);

        $this->get('/')->assertStatus(503)->assertSee('npm run build:laravel');
    }
}
