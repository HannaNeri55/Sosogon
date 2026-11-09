<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A failed login never says which of the two was wrong. */
class LoginMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'role_id' => Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id,
            'name' => 'Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('correct-password'),
        ]);
    }

    public function test_a_wrong_password_and_an_unknown_email_get_the_same_message(): void
    {
        foreach ([
            ['email' => 'tourist@example.com', 'password' => 'wrong-password'],
            ['email' => 'nobody@example.com', 'password' => 'correct-password'],
        ] as $credentials) {
            $this->postJson('/api/v1/login', $credentials)
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'Incorrect email or password.']);
        }
    }
}
