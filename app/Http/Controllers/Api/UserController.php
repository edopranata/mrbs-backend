<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->withCount('bookings')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->input('search').'%';
                $q->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('username', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('department', 'like', $term));
            })
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->input('role')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return UserResource::collection($users);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $this->guardSystemAdminAccess($request, null, $request->validated('role'));

        $user = User::create($request->validated());

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->loadCount('bookings'));
    }

    public function update(UserRequest $request, User $user): UserResource|JsonResponse
    {
        $data = $request->validated();
        $this->guardSystemAdminAccess($request, $user, $data['role'] ?? null);

        if ($user->is($request->user())) {
            $demoting = isset($data['role']) && $data['role'] !== $user->role->value;
            $deactivating = array_key_exists('is_active', $data) && ! $data['is_active'];

            if ($demoting || $deactivating) {
                return response()->json([
                    'message' => 'Anda tidak dapat mengubah level atau menonaktifkan akun Anda sendiri.',
                ], 422);
            }
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return new UserResource($user->loadCount('bookings'));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            return response()->json(['message' => 'Anda tidak dapat menghapus akun Anda sendiri.'], 422);
        }
        $this->guardSystemAdminAccess($request, $user);

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User berhasil dihapus.']);
    }

    /**
     * Hanya System Admin yang boleh memberi level System Admin atau mengelola akun System Admin.
     */
    private function guardSystemAdminAccess(Request $request, ?User $target, ?string $role = null): void
    {
        if ($request->user()->isSystemAdmin()) {
            return;
        }

        abort_if(
            $target?->isSystemAdmin() || $role === UserRole::SystemAdmin->value,
            403,
            'Hanya System Admin yang dapat mengelola akun System Admin.',
        );
    }
}
