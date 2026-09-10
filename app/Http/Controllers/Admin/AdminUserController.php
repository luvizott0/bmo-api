<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\CategorySeeder;
use Database\Seeders\StockCategorySeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    /**
     * Display a listing of the users and the creation form.
     */
    public function index(): View
    {
        $users = User::withCount('workspaces')->latest()->get();

        return view('admin.users', compact('users'));
    }

    /**
     * Store a newly created user with default password and initialized personal workspace.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $newUser = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make('password'),
                'is_admin' => false,
            ]);

            $workspace = Workspace::create([
                'owner_id' => $newUser->id,
                'name' => 'My Personal Workspace',
                'is_personal' => true,
            ]);

            $workspace->members()->attach($newUser->id, [
                'role' => WorkspaceRole::Owner->value,
            ]);

            CategorySeeder::seedForWorkspace($workspace);
            StockCategorySeeder::seedForWorkspace($workspace);

            return $newUser;
        });

        return redirect()->route('admin.users.index')->with(
            'success',
            "Usuário \"{$user->name}\" ({$user->email}) criado com sucesso! A senha inicial padrão é \"password\"."
        );
    }
}
