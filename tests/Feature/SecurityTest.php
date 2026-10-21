<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Masa aktif token, kunci login per username, header keamanan, dan CORS.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-21 09:00'));
    }

    private function login(string $username, string $password, string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/auth/login', ['username' => $username, 'password' => $password]);
    }

    public function test_token_expires_after_idle_days_but_active_use_keeps_it_alive(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('android')->plainTextToken;
        $token = PersonalAccessToken::findToken($plain);

        $this->travel(29)->days();
        $this->withToken($plain)->getJson('/api/auth/me')->assertOk(); // memperbarui last_used_at

        $this->travel(29)->days(); // 58 hari sejak dibuat, 29 hari sejak terakhir dipakai
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/auth/me')->assertOk();

        $this->travel(31)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/auth/me')->assertUnauthorized();

        // Pembersihan harian membuang token yang sudah tidak berlaku.
        $this->artisan('mrbs:cleanup')->expectsOutputToContain('1 token login kedaluwarsa')->assertSuccessful();
        $this->assertNull(PersonalAccessToken::find($token->id));
    }

    public function test_token_idle_expiry_can_be_disabled(): void
    {
        config(['mrbs.security.token_idle_days' => 0]);
        $plain = User::factory()->create()->createToken('web')->plainTextToken;

        $this->travel(2)->years();
        $this->withToken($plain)->getJson('/api/auth/me')->assertOk();
    }

    public function test_username_is_locked_after_repeated_failures_from_any_ip(): void
    {
        $user = User::factory()->create(['username' => 'budi', 'password' => 'rahasia123']);

        foreach (range(1, 5) as $i) {
            $this->login('budi', 'salah', "10.0.0.{$i}")->assertUnprocessable()
                ->assertJsonPath('errors.username.0', 'Username atau password salah.');
        }

        // Percobaan ke-6, dari IP lain dan dengan password benar, tetap ditolak.
        $this->login('BUDI', 'rahasia123', '10.9.9.9')->assertUnprocessable()
            ->assertJsonPath('errors.username.0', 'Terlalu banyak percobaan login yang gagal. Coba lagi dalam 15 menit.');
        $blocked = AuditLog::where('description', 'Login diblokir sementara: terlalu banyak percobaan gagal')->sole();
        $this->assertSame($user->id, $blocked->user_id);

        // Akun lain tidak ikut terkunci.
        User::factory()->create(['username' => 'citra', 'password' => 'rahasia123']);
        $this->login('citra', 'rahasia123', '10.0.0.1')->assertOk();

        $this->travel(16)->minutes();
        $this->login('budi', 'rahasia123')->assertOk();
    }

    public function test_successful_login_resets_failed_attempts(): void
    {
        User::factory()->create(['username' => 'budi', 'password' => 'rahasia123']);

        foreach (range(1, 4) as $i) {
            $this->login('budi', 'salah', "10.0.1.{$i}")->assertUnprocessable();
        }
        $this->login('budi', 'rahasia123', '10.0.2.1')->assertOk();
        foreach (range(1, 4) as $i) {
            $this->login('budi', 'salah', "10.0.3.{$i}")->assertUnprocessable();
        }
        $this->login('budi', 'rahasia123', '10.0.4.1')->assertOk();
    }

    public function test_security_headers_are_sent(): void
    {
        $this->getJson('/api/settings')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('Strict-Transport-Security'); // bukan HTTPS & bukan production

        $this->get('https://localhost/api/settings', ['Accept' => 'application/json'])
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_cors_only_allows_configured_origins(): void
    {
        config(['cors.allowed_origins' => ['https://booking.ropekanbaru.com']]);
        $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/bookings', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertSame('https://booking.ropekanbaru.com', $preflight('https://booking.ropekanbaru.com')->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('https://situs-asing.example', $preflight('https://situs-asing.example')->headers->get('Access-Control-Allow-Origin'));

        // Klien non-browser (aplikasi Android) tidak mengirim Origin dan tetap dilayani.
        User::factory()->create(['username' => 'budi', 'password' => 'rahasia123']);
        $this->login('budi', 'rahasia123')->assertOk();
    }
}
