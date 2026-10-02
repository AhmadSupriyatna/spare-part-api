<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Auth::validate([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ])) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Akun ini telah dinonaktifkan. Hubungi Super Admin.'],
            ]);
        }

        $token = $user->createToken($credentials['device_name']);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $this->transformUser($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->transformUser($request->user()));
    }

    /** Self-service "Edit Profil" — see UpdateProfileRequest's docblock for how this differs from UserController::update(). */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->safe()->only(['name', 'password']);

        $updates = collect($data)->except('password')->all();
        if (! empty($data['password'])) {
            $updates['password'] = Hash::make($data['password']);
        }

        $user->update($updates);

        return response()->json($this->transformUser($user));
    }

    private function transformUser(User $user): array
    {
        $hasAllBranchAccess = $user->hasRole(UserRole::Superadmin->value);

        $branches = $hasAllBranchAccess
            ? Branch::where('is_active', true)->get()
            : $user->branches;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatarUrl(),
            'roles' => $user->getRoleNames(),
            'branches' => $branches->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ])->values(),
        ];
    }
}
