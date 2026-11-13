<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Registration, login and password reset reject what the forms reject, with the same messages
 * (frontend/src/utils/authValidation.js), so the rules hold even when the form is bypassed.
 */
class AuthValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Role::create(['name' => 'tourist', 'description' => 'Tourist']);
        Role::create(['name' => 'admin', 'description' => 'Admin']);
    }

    private function register(array $overrides = [])
    {
        return $this->postJson('/api/v1/register', array_merge([
            'name' => 'Hanna Neri', 'email' => 'hanna@gmail.com', 'phone' => '09171234567',
            'password' => 'Hanna123!', 'password_confirmation' => 'Hanna123!', 'role' => 'tourist',
        ], $overrides));
    }

    public function test_the_official_password_examples(): void
    {
        $passes = fn (string $password) => Validator::make(['password' => $password], ['password' => [new StrongPassword]])->passes();

        foreach (['Hanna123!', 'Sosogon2026@', 'Travel@123', 'Bulan#2026A', 'MyPassword1!'] as $password) {
            $this->assertTrue($passes($password), "{$password} should be accepted.");
        }
        foreach (['hanna', 'hanna123', 'HANNANERI', 'hanna123!', 'HANNA123!', 'HannaNeri!', 'Hanna123', '12345678', 'password', 'Hanna 123!'] as $password) {
            $this->assertFalse($passes($password), "{$password} should be rejected.");
        }

        $message = Validator::make(['password' => 'hanna123'], ['password' => [new StrongPassword]])->errors()->first('password');
        $this->assertSame('Your password needs an uppercase letter (A–Z) and a special character (e.g. ! @ # $ % & *).', $message);
    }

    public function test_registration_accepts_valid_details_and_keeps_the_password_as_typed(): void
    {
        $this->register(['phone' => '0917 123-4567'])->assertCreated();

        $user = User::where('email', 'hanna@gmail.com')->firstOrFail();
        $this->assertSame('09171234567', $user->phone, 'Spaces and dashes are dropped from the phone number.');
        $this->assertTrue(Hash::check('Hanna123!', $user->password));
    }

    public function test_registration_rejects_each_invalid_field_with_its_message(): void
    {
        $this->postJson('/api/v1/register', ['role' => 'tourist'])->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Enter your full name.')
            ->assertJsonPath('errors.email.0', 'Enter your email address.')
            ->assertJsonPath('errors.phone.0', 'Enter your phone number.')
            ->assertJsonPath('errors.password.0', 'Enter a password.')
            ->assertJsonPath('errors.password_confirmation.0', 'Re-enter your password.');

        $this->register(['email' => 'hanna@gmail'])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Enter a valid email address, like juandelacruz@gmail.com.');
        $this->register(['name' => '   '])->assertJsonPath('errors.name.0', 'Enter your full name.');
        $this->register(['name' => 'Hanna 2026'])->assertJsonPath('errors.name.0', 'Use letters only. Spaces, periods, hyphens and apostrophes are fine.');
        $this->register(['phone' => '12345'])->assertJsonValidationErrors('phone');
        $this->register(['password' => 'HannaNeri!', 'password_confirmation' => 'HannaNeri!'])
            ->assertJsonPath('errors.password.0', 'Your password needs a number (0–9).');
        $this->register(['password_confirmation' => 'Hanna123?'])->assertJsonPath('errors.password_confirmation.0', 'Passwords do not match.');
        $this->assertSame(0, User::count());

        // A name with ñ, a period and a hyphen, and a number from abroad, are fine.
        $this->register(['name' => 'Ma. Niña Dela Cruz-Santos', 'phone' => '+1 415 555 2671'])->assertCreated();
    }

    public function test_an_email_can_only_register_once(): void
    {
        $this->getJson('/api/v1/register/email-available?email=hanna@gmail.com')->assertOk()->assertJsonPath('available', true);
        User::create(['role_id' => Role::where('name', 'tourist')->value('id'), 'name' => 'Hanna Neri', 'email' => 'hanna@gmail.com', 'password' => 'Hanna123!', 'status' => 'active']);

        $this->getJson('/api/v1/register/email-available?email=HANNA@gmail.com')->assertJsonPath('available', false);
        $this->register(['name' => 'Someone Else'])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'This email is already registered. Log in instead, or use a different email.');
        $this->getJson('/api/v1/register/email-available?email=hanna@gmail')->assertUnprocessable();
    }

    public function test_login_checks_its_fields_and_keeps_wrong_credentials_vague(): void
    {
        User::create(['role_id' => Role::where('name', 'tourist')->value('id'), 'name' => 'Hanna Neri', 'email' => 'hanna@gmail.com', 'password' => 'Hanna123!', 'status' => 'active']);

        $this->postJson('/api/v1/login', [])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Enter your email address.')
            ->assertJsonPath('errors.password.0', 'Enter your password.');
        $this->postJson('/api/v1/login', ['email' => 'hanna@gmail', 'password' => 'x'])
            ->assertJsonPath('errors.email.0', 'Enter a valid email address, like juandelacruz@gmail.com.');

        $wrongPassword = $this->postJson('/api/v1/login', ['email' => 'hanna@gmail.com', 'password' => 'Hanna123?'])->assertUnauthorized()->json('message');
        $unknownEmail = $this->postJson('/api/v1/login', ['email' => 'nobody@gmail.com', 'password' => 'Hanna123!'])->assertUnauthorized()->json('message');
        $this->assertSame($wrongPassword, $unknownEmail);

        $this->postJson('/api/v1/login', ['email' => 'hanna@gmail.com', 'password' => 'Hanna123!'])->assertOk();
    }

    public function test_a_new_password_from_a_reset_follows_the_same_rules(): void
    {
        $this->postJson('/api/v1/reset-password', ['token' => 'x', 'email' => 'hanna@gmail.com', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'Your password needs an uppercase letter (A–Z), a number (0–9) and a special character (e.g. ! @ # $ % & *).');
        $this->postJson('/api/v1/reset-password', ['token' => 'x', 'email' => 'hanna@gmail.com', 'password' => 'Hanna123!', 'password_confirmation' => 'Hanna123'])
            ->assertJsonPath('errors.password_confirmation.0', 'Passwords do not match.');
    }
}
