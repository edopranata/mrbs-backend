<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('username', $request->validated('username'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            Audit::log(
                'auth.login_failed',
                $user ? 'Login gagal: password salah' : 'Login gagal: username tidak terdaftar',
                $user,
                meta: ['Username dicoba' => Str::limit((string) $request->validated('username'), 50)],
                actor: $user,
                actorName: $user ? null : Str::limit((string) $request->validated('username'), 50),
            );

            throw ValidationException::withMessages([
                'username' => 'Username atau password salah.',
            ]);
        }

        if (! $user->is_active) {
            Audit::log('auth.login_failed', 'Login gagal: akun nonaktif', $user, actor: $user);

            throw ValidationException::withMessages([
                'username' => 'Akun Anda telah dinonaktifkan. Hubungi admin.',
            ]);
        }

        $token = $user->createToken($request->validated('device_name') ?? 'spa')->plainTextToken;
        Audit::log('auth.login', 'Login berhasil', $user, meta: ['Perangkat' => $request->validated('device_name')], actor: $user);

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        Audit::log('auth.logout', 'Logout', $request->user());
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil logout.']);
    }

    public function updateProfile(Request $request): UserResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
        ], attributes: ['name' => 'nama', 'department' => 'department', 'phone' => 'no. telepon']);

        $user = $request->user();
        $before = $user->only(array_keys($data));
        $user->update($data);

        if ($changes = Audit::diff($before, $user->only(array_keys($data)))) {
            Audit::log('auth.profile_updated', 'Mengubah profil sendiri', $user, $changes);
        }

        return new UserResource($request->user());
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], attributes: ['password' => 'password baru']);

        $user = $request->user();
        $user->update(['password' => $request->input('password')]);

        // Keluarkan sesi di perangkat lain, pertahankan token yang sedang dipakai.
        $user->tokens()->whereKeyNot($user->currentAccessToken()->id)->delete();
        Audit::log('auth.password_changed', 'Mengganti password sendiri (sesi di perangkat lain dikeluarkan)', $user);

        return response()->json(['message' => 'Password berhasil diubah.']);
    }
}
