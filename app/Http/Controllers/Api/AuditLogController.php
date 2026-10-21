<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Audit log (Admin & System Admin): daftar aktivitas terbaru dengan filter.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'category' => ['nullable', Rule::in(array_keys(AuditLog::CATEGORIES))],
            'action' => ['nullable', Rule::in(array_keys(AuditLog::ACTIONS))],
            'user_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:30'],
            'subject_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], attributes: ['from' => 'tanggal awal', 'to' => 'tanggal akhir']);

        $logs = AuditLog::query()
            ->with('user:id,name,username')
            ->when($request->filled('category'), fn ($q) => $q->where('action', 'like', $request->input('category').'.%'))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->input('action')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('subject_type'), fn ($q) => $q
                ->where('subject_type', $request->input('subject_type'))
                ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id'))))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->input('search').'%';
                $q->where(fn ($q) => $q->where('description', 'like', $term)
                    ->orWhere('user_name', 'like', $term)
                    ->orWhere('ip_address', 'like', $term));
            })
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        return AuditLogResource::collection($logs)->additional([
            'categories' => AuditLog::CATEGORIES,
            'actions' => AuditLog::ACTIONS,
            'retention_days' => (int) config('mrbs.audit_retention_days'),
        ]);
    }
}
