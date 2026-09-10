<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('unauthenticated users are redirected to login when visiting /admin/users', function () {
    $response = $this->get('/admin/users');

    $response->assertRedirect('/admin/login');
});

test('non-admin users receive 403 forbidden when visiting /admin/users', function () {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $response = $this->actingAs($user)->get('/admin/users');

    $response->assertForbidden();
});

test('admin user can log in with username "admin"', function () {
    User::factory()->create([
        'name' => 'admin',
        'email' => 'admin@admin.com',
        'password' => Hash::make('Zephryne2301*'),
        'is_admin' => true,
    ]);

    $response = $this->post('/admin/login', [
        'login' => 'admin',
        'password' => 'Zephryne2301*',
    ]);

    $response->assertRedirect('/admin/users');
    $this->assertAuthenticated();
});

test('admin user can log in with email "admin@admin.com"', function () {
    User::factory()->create([
        'name' => 'admin',
        'email' => 'admin@admin.com',
        'password' => Hash::make('Zephryne2301*'),
        'is_admin' => true,
    ]);

    $response = $this->post('/admin/login', [
        'login' => 'admin@admin.com',
        'password' => 'Zephryne2301*',
    ]);

    $response->assertRedirect('/admin/users');
    $this->assertAuthenticated();
});

test('admin login fails with invalid credentials', function () {
    User::factory()->create([
        'name' => 'admin',
        'email' => 'admin@admin.com',
        'password' => Hash::make('Zephryne2301*'),
        'is_admin' => true,
    ]);

    $response = $this->post('/admin/login', [
        'login' => 'admin',
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors('login');
    $this->assertGuest();
});

test('non-admin users cannot log in through the admin login form', function () {
    User::factory()->create([
        'name' => 'regular',
        'email' => 'regular@example.com',
        'password' => Hash::make('password123'),
        'is_admin' => false,
    ]);

    $response = $this->post('/admin/login', [
        'login' => 'regular@example.com',
        'password' => 'password123',
    ]);

    $response->assertSessionHasErrors('login');
    $this->assertGuest();
});

test('admin user can view the user management dashboard', function () {
    $admin = User::factory()->create([
        'name' => 'admin',
        'is_admin' => true,
    ]);

    $response = $this->actingAs($admin)->get('/admin/users');

    $response->assertOk()
        ->assertSee('Gestão de Usuários')
        ->assertSee('Novo Usuário');
});

test('admin user can create a new user with default password "password" and personal workspace', function () {
    $admin = User::factory()->create([
        'name' => 'admin',
        'is_admin' => true,
    ]);

    $response = $this->actingAs($admin)->post('/admin/users', [
        'name' => 'Carlos Pereira',
        'email' => 'carlos@example.com',
    ]);

    $response->assertRedirect('/admin/users')
        ->assertSessionHas('success');

    $this->assertDatabaseHas('users', [
        'name' => 'Carlos Pereira',
        'email' => 'carlos@example.com',
        'is_admin' => false,
    ]);

    $newUser = User::where('email', 'carlos@example.com')->first();
    expect($newUser)->not->toBeNull();
    expect(Hash::check('password', $newUser->password))->toBeTrue();

    // Verify personal workspace creation and categories
    $personalWorkspace = $newUser->personalWorkspace();
    expect($personalWorkspace)->not->toBeNull();
    expect($personalWorkspace->owner_id)->toBe($newUser->id);
    expect($personalWorkspace->categories()->count())->toBeGreaterThan(5);
});

test('user creation validates required name and unique email', function () {
    $admin = User::factory()->create([
        'name' => 'admin',
        'is_admin' => true,
    ]);

    User::factory()->create([
        'email' => 'existing@example.com',
    ]);

    $response = $this->actingAs($admin)->post('/admin/users', [
        'name' => '',
        'email' => 'existing@example.com',
    ]);

    $response->assertSessionHasErrors(['name', 'email']);
});

test('admin can log out', function () {
    $admin = User::factory()->create([
        'name' => 'admin',
        'is_admin' => true,
    ]);

    $response = $this->actingAs($admin)->post('/admin/logout');

    $response->assertRedirect('/admin/login');
    $this->assertGuest();
});

test('UserSeeder correctly seeds admin user and DatabaseSeeder does not seed Alex', function () {
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseHas('users', [
        'name' => 'admin',
        'email' => 'admin@admin.com',
        'is_admin' => true,
    ]);

    $admin = User::where('email', 'admin@admin.com')->first();
    expect($admin)->not->toBeNull();
    expect(Hash::check('Zephryne2301*', $admin->password))->toBeTrue();
    expect($admin->isAdmin())->toBeTrue();
    expect($admin->personalWorkspace())->not->toBeNull();

    // Alex should not be in the database
    $this->assertDatabaseMissing('users', [
        'email' => 'alex@flux.app',
    ]);
});
