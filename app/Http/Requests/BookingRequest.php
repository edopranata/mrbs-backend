<?php

namespace App\Http\Requests;

use App\Enums\BookingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi format input booking. Aturan bisnis (bentrok jadwal, jam operasional,
 * kapasitas) dicek di App\Services\BookingService.
 */
class BookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'room_id' => [$required, 'integer', 'exists:rooms,id'],
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'start_at' => [$required, 'date'],
            'end_at' => [$required, 'date'],
            'participants' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'type' => ['sometimes', Rule::enum(BookingType::class)],
            // Pengulangan hanya saat membuat booking; mengubah satu booking tidak mengubah seri.
            'repeat_weeks' => $this->isMethod('POST')
                ? ['sometimes', 'integer', 'min:1', 'max:'.config('mrbs.max_repeat_weeks')]
                : ['prohibited'],
            'skip_conflicts' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'room_id' => 'ruangan',
            'title' => 'judul rapat',
            'description' => 'deskripsi',
            'start_at' => 'waktu mulai',
            'end_at' => 'waktu selesai',
            'participants' => 'jumlah peserta',
            'type' => 'jenis rapat',
            'repeat_weeks' => 'jumlah minggu',
        ];
    }
}
