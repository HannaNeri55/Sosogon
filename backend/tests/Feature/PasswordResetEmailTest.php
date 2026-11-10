<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The reset email goes out during the request itself, so it never waits on a queue worker. */
class PasswordResetEmailTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'If an account with that email exists, a password reset link has been sent.';

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'role_id' => Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id,
            'name' => 'Tourist', 'email' => 'tourist@gmail.com', 'password' => bcrypt('old-password'),
        ]);
    }

    public function test_the_reset_link_is_sent_right_away_to_the_account_email(): void
    {
        Mail::fake();
        config(['app.frontend_url' => 'https://sosogon.test']);

        $this->postJson('/api/v1/forgot-password', ['email' => 'tourist@gmail.com'])
            ->assertOk()
            ->assertExactJson(['message' => self::MESSAGE]);

        Mail::assertNothingQueued();
        Mail::assertSent(PasswordResetMail::class, fn ($mail) => $mail->hasTo('tourist@gmail.com')
            && str_starts_with($mail->resetUrl, 'https://sosogon.test/reset-password?token=')
            && str_ends_with($mail->resetUrl, '&email=tourist%40gmail.com'));
    }

    public function test_an_unknown_email_sends_nothing_but_gets_the_same_message(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@gmail.com'])
            ->assertOk()
            ->assertExactJson(['message' => self::MESSAGE]);

        Mail::assertNothingSent();
    }

    public function test_an_smtp_failure_is_logged_without_revealing_the_account(): void
    {
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('535 Username and Password not accepted'));
        Log::shouldReceive('error')->once()->with('Failed to send password reset email', \Mockery::on(
            fn ($context) => $context['email'] === 'tourist@gmail.com' && str_contains($context['error'], '535')
        ));

        $this->postJson('/api/v1/forgot-password', ['email' => 'tourist@gmail.com'])
            ->assertOk()
            ->assertExactJson(['message' => self::MESSAGE]);
    }
}
