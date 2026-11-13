<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * One account per browser: the browser's login token travels with every request, so a login or
 * registration that arrives with a valid one is refused and the signed-in account stays as it is.
 * A request without it (another browser, profile or computer) signs in independently.
 */
class SingleAccountPerBrowserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        foreach (['admin', 'tourist', 'business_owner'] as $role) {
            $id = Role::create(['name' => $role, 'description' => $role])->id;
            User::create(['role_id' => $id, 'name' => ucfirst($role).' User', 'email' => "{$role}@example.com",
                'password' => bcrypt('password'), 'status' => 'active', 'business_type' => $role === 'business_owner' ? 'accommodation' : null]);
        }
    }

    /** Signs in with no token (a fresh browser) and returns the new token. */
    private function signIn(string $role): string
    {
        $token = $this->postJson('/api/v1/login', ['email' => "{$role}@example.com", 'password' => 'password'])->assertOk()->json('token');
        $this->app['auth']->forgetGuards();

        return $token;
    }

    /** A request from the browser that holds $token. */
    private function fromBrowser(string $token, string $method, string $uri, array $data = [])
    {
        $response = $this->withToken($token)->json($method, $uri, $data);
        // Each real request starts fresh; in a test the guards (and auth:sanctum's default) carry over.
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');
        $this->flushHeaders();

        return $response;
    }

    public function test_another_account_cannot_sign_in_in_a_browser_where_the_admin_is_signed_in(): void
    {
        $admin = $this->signIn('admin');

        foreach (['tourist', 'business_owner'] as $role) {
            $this->fromBrowser($admin, 'POST', '/api/v1/login', ['email' => "{$role}@example.com", 'password' => 'password'])
                ->assertStatus(409)
                ->assertJsonPath('code', 'already_authenticated')
                ->assertJsonPath('user.email', 'admin@example.com');
        }

        // Nothing was issued for the other accounts, and the admin's session is untouched.
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->fromBrowser($admin, 'GET', '/api/v1/user')->assertOk()->assertJsonPath('role', 'admin');
    }

    public function test_signing_in_again_with_the_same_account_is_also_refused_without_a_new_token(): void
    {
        $admin = $this->signIn('admin');

        $this->fromBrowser($admin, 'POST', '/api/v1/login', ['email' => 'admin@example.com', 'password' => 'password'])->assertStatus(409);

        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_registering_a_new_account_is_refused_while_signed_in(): void
    {
        $admin = $this->signIn('admin');

        $this->fromBrowser($admin, 'POST', '/api/v1/register', ['name' => 'New Tourist', 'email' => 'new@example.com',
            'phone' => '09171234567', 'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'role' => 'tourist'])
            ->assertStatus(409)->assertJsonPath('code', 'already_authenticated');

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_after_logging_out_another_account_can_sign_in(): void
    {
        $admin = $this->signIn('admin');
        $this->fromBrowser($admin, 'POST', '/api/v1/logout')->assertOk();

        // The browser still sends the old token by mistake: it no longer counts as signed in.
        $this->fromBrowser($admin, 'POST', '/api/v1/login', ['email' => 'tourist@example.com', 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.role', 'tourist');
    }

    public function test_an_expired_token_does_not_block_a_new_sign_in(): void
    {
        $admin = $this->signIn('admin');
        DB::table('personal_access_tokens')->update(['created_at' => now()->subMinutes(config('sanctum.expiration') + 1)]);

        $this->fromBrowser($admin, 'POST', '/api/v1/login', ['email' => 'tourist@example.com', 'password' => 'password'])->assertOk();
    }

    public function test_other_browsers_and_computers_sign_in_independently(): void
    {
        $admin = $this->signIn('admin');
        $tourist = $this->signIn('tourist');

        $this->fromBrowser($admin, 'GET', '/api/v1/user')->assertOk()->assertJsonPath('role', 'admin');
        $this->fromBrowser($tourist, 'GET', '/api/v1/user')->assertOk()->assertJsonPath('role', 'tourist');
    }
}
