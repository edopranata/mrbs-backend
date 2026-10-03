<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    public function __construct(private readonly AppSettings $settings) {}

    /**
     * Pengaturan yang berlaku (publik: dipakai juga di halaman login untuk nama aplikasi).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            ...$this->settings->all(),
            'timezone' => config('app.timezone'),
        ]);
    }

    /**
     * Nilai default (.env) dan nilai yang sedang berlaku, untuk halaman Pengaturan.
     */
    public function manage(): JsonResponse
    {
        return response()->json([
            'values' => $this->settings->all(),
            'defaults' => $this->settings->defaults(),
            'overridden' => array_keys($this->settings->overrides()),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app_name' => ['sometimes', 'required', 'string', 'max:50'],
            'app_subtitle' => ['sometimes', 'nullable', 'string', 'max:100'],
            'open_time' => ['sometimes', 'required', 'date_format:H:i'],
            'close_time' => ['sometimes', 'required', 'date_format:H:i'],
            'slot_minutes' => ['sometimes', 'required', 'integer', Rule::in([15, 30, 60])],
            'min_duration' => ['sometimes', 'required', 'integer', 'min:15', 'max:1440'],
            'max_duration' => ['sometimes', 'required', 'integer', 'min:15', 'max:1440'],
            'max_advance_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:365'],
            'max_repeat_weeks' => ['sometimes', 'required', 'integer', 'min:1', 'max:52'],
        ], attributes: [
            'app_name' => 'nama aplikasi',
            'app_subtitle' => 'subjudul',
            'open_time' => 'jam buka',
            'close_time' => 'jam tutup',
            'slot_minutes' => 'interval slot',
            'min_duration' => 'durasi minimal',
            'max_duration' => 'durasi maksimal',
            'max_advance_days' => 'batas hari pemesanan',
            'max_repeat_weeks' => 'batas minggu berulang',
        ]);

        $this->ensureConsistent([...$this->settings->all(), ...$data]);
        $this->settings->update($data);

        return $this->manage();
    }

    public function reset(): JsonResponse
    {
        $this->settings->reset();

        return $this->manage();
    }

    /**
     * Aturan antar-kolom agar grid jadwal & validasi booking tetap masuk akal.
     *
     * @param  array<string, mixed>  $s
     */
    private function ensureConsistent(array $s): void
    {
        $minutes = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $open = $minutes($s['open_time']);
        $close = $minutes($s['close_time']);
        $slot = $s['slot_minutes'];
        $errors = [];

        if ($close <= $open) {
            $errors['close_time'] = 'Jam tutup harus setelah jam buka.';
        }
        if ($open % $slot || $close % $slot) {
            $errors['open_time'] = "Jam buka & tutup harus kelipatan {$slot} menit.";
        }
        if ($s['min_duration'] < $slot || $s['min_duration'] % $slot) {
            $errors['min_duration'] = "Durasi minimal harus kelipatan {$slot} menit (minimal {$slot} menit).";
        }
        if ($s['max_duration'] % $slot) {
            $errors['max_duration'] = "Durasi maksimal harus kelipatan {$slot} menit.";
        } elseif ($s['max_duration'] < $s['min_duration']) {
            $errors['max_duration'] = 'Durasi maksimal tidak boleh kurang dari durasi minimal.';
        } elseif ($close > $open && $s['max_duration'] > $close - $open) {
            $errors['max_duration'] = 'Durasi maksimal melebihi rentang jam operasional.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
