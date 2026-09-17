<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserAvatarService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct(private readonly UserAvatarService $avatars) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::query()->with('branches')->orderBy('name');

        if ($role = $request->string('role')->toString()) {
            $query->role($role);
        }

        return UserResource::collection($query->get());
    }

    public function store(StoreUserRequest $request): UserResource
    {
        $data = $request->safe()->only(['name', 'email', 'password', 'role', 'branch_ids']);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_active' => true,
        ]);

        if ($request->hasFile('avatar')) {
            $user->update(['avatar_path' => $this->avatars->store($request->file('avatar'))]);
        }

        $user->assignRole($data['role']);
        $user->branches()->sync($data['branch_ids'] ?? []);

        return new UserResource($user->load('branches'));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $data = $request->safe()->only(['name', 'email', 'password', 'role', 'branch_ids']);

        $updates = collect($data)->only(['name', 'email'])->all();
        if (! empty($data['password'])) {
            $updates['password'] = Hash::make($data['password']);
        }
        $user->update($updates);

        if ($request->hasFile('avatar')) {
            $this->avatars->delete($user->avatar_path);
            $user->update(['avatar_path' => $this->avatars->store($request->file('avatar'))]);
        }

        if (array_key_exists('role', $data)) {
            $user->syncRoles([$data['role']]);
        }

        if (array_key_exists('branch_ids', $data)) {
            $user->branches()->sync($data['branch_ids'] ?? []);
        }

        return new UserResource($user->load('branches'));
    }

    /**
     * Deactivating (not deleting) a user preserves every historical FK that
     * points at them — task assignee, installed_by, rescheduled_by, and so
     * on — while blocking them from logging in (see AuthController::login).
     */
    public function deactivate(Request $request, User $user): UserResource
    {
        abort_if($request->user()->is($user), 422, 'Tidak bisa menonaktifkan akun sendiri.');

        $user->update(['is_active' => false]);

        return new UserResource($user->load('branches'));
    }

    public function activate(User $user): UserResource
    {
        $user->update(['is_active' => true]);

        return new UserResource($user->load('branches'));
    }
}
