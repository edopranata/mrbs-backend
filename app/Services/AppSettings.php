<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Pengaturan aplikasi yang bisa diubah System Admin dari menu Pengaturan.
 *
 * Nilai default berasal dari config/mrbs.php (.env). Nilai yang disimpan di tabel
 * `settings` menimpa default tersebut di setiap request lewat apply(), sehingga kode
 * lain cukup membaca config('mrbs.*') seperti biasa.
 */
class AppSettings
{
    /** Kunci yang boleh diubah lewat menu Pengaturan. */
    public const KEYS = [
        'app_name', 'app_subtitle',
        'open_time', 'close_time', 'slot_minutes',
        'min_duration', 'max_duration', 'max_advance_days', 'max_repeat_weeks',
    ];

    private const CACHE_KEY = 'mrbs.settings';

    /** @var array<string, mixed>|null nilai dari config/.env sebelum ditimpa */
    private ?array $defaults = null;

    /**
     * Terapkan nilai tersimpan ke config('mrbs.*').
     */
    public function apply(): void
    {
        $this->defaults ??= Arr::only(config('mrbs'), self::KEYS);

        config(Arr::prependKeysWith([...$this->defaults, ...$this->overrides()], 'mrbs.'));
    }

    /** @return array<string, mixed> nilai yang sedang berlaku */
    public function all(): array
    {
        return Arr::only(config('mrbs'), self::KEYS);
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return $this->defaults ?? Arr::only(config('mrbs'), self::KEYS);
    }

    /** @return array<string, mixed> nilai yang disimpan di database */
    public function overrides(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => Setting::pluck('value', 'key')
                ->only(self::KEYS)
                ->all());
        } catch (QueryException) {
            // Tabel belum ada (mis. saat instalasi sebelum migrate): pakai default.
            return [];
        }
    }

    /**
     * Simpan perubahan. Nilai yang sama dengan default tidak disimpan agar
     * perubahan .env di kemudian hari tetap berlaku untuk kunci tersebut.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        $defaults = $this->defaults();

        foreach (Arr::only($values, self::KEYS) as $key => $value) {
            if ($value === $defaults[$key]) {
                Setting::where('key', $key)->delete();
            } else {
                Setting::updateOrCreate(['key' => $key], ['value' => $value]);
            }
        }

        $this->refresh();
    }

    /**
     * Hapus semua nilai tersimpan, kembali ke default dari .env.
     */
    public function reset(): void
    {
        Setting::query()->delete();
        $this->refresh();
    }

    private function refresh(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->apply();
    }
}
