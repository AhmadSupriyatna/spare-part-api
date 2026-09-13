<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    private function transformUser(User $user): array
    {
        $hasAllBranchAccess = $user->hasAnyRole([
            UserRole::Superadmin->value,
            UserRole::Supervisor->value,
        ]);

        $branches = $hasAllBranchAccess
            ? Branch::where('is_active', true)->get()
            : $user->branches;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'branches' => $branches->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ])->values(),
        ];
    }
}
