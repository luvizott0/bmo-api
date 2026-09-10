<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('user can register, receiving token, personal workspace, and default categories', function () {
    $payload = [
        'name' => 'João Silva',
        'email' => 'joao@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ];

    $response = $this->postJson('/api/auth/register', $payload);

    $response->assertCreated()
        ->assertJsonStructure([
            'message',
            'user' => ['id', 'name', 'email'],
            'workspace' => ['id', 'name', 'is_personal'],
            'token',
        ]);

    $this->assertDatabaseHas('users', ['email' => 'joao@example.com']);
    $user = User::where('email', 'joao@example.com')->first();
    expect($user->workspaces)->toHaveCount(1);
    expect($user->personalWorkspace()->categories()->count())->toBeGreaterThan(5);
});

test('user cannot register with duplicate email', function () {
    User::factory()->create(['email' => 'joao@example.com']);

    $response = $this->postJson('/api/auth/register', [
        'name' => 'João Outro',
        'email' => 'joao@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('user can login with valid credentials', function () {
    $user = User::factory()->create([
        'email' => 'joao@example.com',
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'joao@example.com',
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['message', 'user', 'token']);
});

test('user cannot login with invalid credentials', function () {
    User::factory()->create([
        'email' => 'joao@example.com',
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'joao@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('authenticated user can view their profile and workspaces', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/api/auth/me');

    $response->assertOk()
        ->assertJsonPath('user.email', $user->email);
});

test('authenticated user can logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/auth/logout');

    $response->assertOk()
        ->assertJson(['message' => 'Logged out successfully.']);

    expect($user->tokens()->count())->toBe(0);
});

test('authenticated user can change password with correct current password', function () {
    $user = User::factory()->create([
        'password' => bcrypt('old-password123'),
    ]);

    $response = $this->actingAs($user)
        ->putJson('/api/auth/password', [
            'current_password' => 'old-password123',
            'password' => 'new-password456',
            'password_confirmation' => 'new-password456',
        ]);

    $response->assertOk()
        ->assertJson(['message' => 'Password updated successfully.']);

    expect(Hash::check('new-password456', $user->fresh()->password))->toBeTrue();
});

test('authenticated user cannot change password with incorrect current password', function () {
    $user = User::factory()->create([
        'password' => bcrypt('old-password123'),
    ]);

    $response = $this->actingAs($user)
        ->putJson('/api/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password456',
            'password_confirmation' => 'new-password456',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);
});

test('authenticated user cannot change password when confirmation does not match', function () {
    $user = User::factory()->create([
        'password' => bcrypt('old-password123'),
    ]);

    $response = $this->actingAs($user)
        ->putJson('/api/auth/password', [
            'current_password' => 'old-password123',
            'password' => 'new-password456',
            'password_confirmation' => 'different-password',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});
