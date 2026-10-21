<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->created_at?->format('Y-m-d\TH:i:s'),
            'action' => $this->action,
            'action_label' => AuditLog::ACTIONS[$this->action] ?? $this->action,
            'category' => $this->category(),
            'category_label' => AuditLog::CATEGORIES[$this->category()] ?? $this->category(),
            'description' => $this->description,
            // Nama saat kejadian; akun yang sudah dihapus tetap terbaca.
            'user_name' => $this->user?->name ?? $this->user_name ?? 'Sistem',
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name, 'username' => $this->user->username] : null,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'changes' => collect($this->changes ?? [])->map(fn ($pair, $field) => [
                'field' => $field,
                'label' => AuditLog::FIELDS[$field] ?? $field,
                'old' => $pair[0] ?? null,
                'new' => $pair[1] ?? null,
            ])->values(),
            'meta' => $this->meta ?? (object) [],
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
        ];
    }
}
