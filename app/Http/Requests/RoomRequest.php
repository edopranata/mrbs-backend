<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoomRequest extends FormRequest
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
            'code' => [$required, 'string', 'max:20', Rule::unique('rooms', 'code')->ignore($this->route('room'))],
            'name' => [$required, 'string', 'max:255'],
            'floor' => [$required, 'integer', 'min:0', 'max:200'],
            'capacity' => [$required, 'integer', 'min:1', 'max:1000'],
            'facilities' => ['nullable', 'array'],
            'facilities.*' => ['string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'kode ruangan',
            'name' => 'nama ruangan',
            'floor' => 'lantai',
            'capacity' => 'kapasitas',
            'facilities' => 'fasilitas',
            'color' => 'warna',
        ];
    }
}
