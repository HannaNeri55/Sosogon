<?php

namespace Tests\Feature;

use App\Mail\AdminInviteMail;
use App\Models\AccountActionLog;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The provincial administrator gives a municipality's tourism office its admin account. */
class AdminOfficeAccountTest extends TestCase
{
    use RefreshDatabase;

    private int $bulan;

    private int $matnog;

    private User $provincial;

    private User $bulanAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $role = Role::create(['name' => 'admin', 'description' => 'Admin'])->id;
        $this->bulan = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $this->matnog = DB::table('municipalities')->insertGetId(['name' => 'Matnog', 'created_at' => now(), 'updated_at' => now()]);
        $admin = function (string $email, string $scope, ?int $town) use ($role) {
            $user = User::create(['role_id' => $role, 'name' => 'Admin', 'email' => $email, 'password' => 'Secret123!', 'status' => 'active']);
            $user->forceFill(['admin_scope' => $scope, 'municipality_id' => $town])->save();

            return $user->fresh();
        };
        $this->provincial = $admin('province@example.com', User::ADMIN_SCOPE_PROVINCIAL, null);
        $this->bulanAdmin = $admin('bulan@example.com', User::ADMIN_SCOPE_MUNICIPAL, $this->bulan);
        AdminScope::forgetOwnOffices();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Maria Santos', 'email' => 'maria@example.com', 'municipality_id' => $this->matnog,
            'title' => 'Matnog Tourism Office', 'confirm_municipality' => 'matnog'], $overrides);
    }

    public function test_the_provincial_admin_creates_a_municipal_office_account_that_sets_its_own_password(): void
    {
        $this->actingAs($this->provincial)->postJson('/api/v1/admin/admin-accounts', $this->payload())
            ->assertCreated()->assertJsonPath('email_sent', true);

        $user = User::where('email', 'maria@example.com')->firstOrFail();
        $this->assertTrue($user->isMunicipalAdmin());
        $this->assertSame($this->matnog, (int) $user->municipality_id);
        $this->assertSame('Matnog Tourism Office', $user->admin_title);
        $this->assertFalse(Hash::check('Secret123!', $user->password), 'Nobody chose the password.');
        $this->assertTrue(AdminScope::hasOwnOffice($this->matnog), 'Matnog is now managed by its own office.');
        $log = AccountActionLog::where('user_id', $user->id)->sole();
        $this->assertSame(['admin_created', $this->provincial->id], [$log->action, $log->admin_id]);

        // The emailed link sets the officer's password.
        $mail = null;
        Mail::assertSent(AdminInviteMail::class, function (AdminInviteMail $sent) use (&$mail) {
            $mail = $sent;

            return $sent->hasTo('maria@example.com') && $sent->municipality === 'Matnog';
        });
        parse_str(parse_url($mail->setupUrl, PHP_URL_QUERY), $query);
        $this->postJson('/api/v1/reset-password', ['token' => $query['token'], 'email' => $query['email'],
            'password' => 'NewPass123!', 'password_confirmation' => 'NewPass123!'])->assertOk();
        $this->assertTrue(Hash::check('NewPass123!', $user->fresh()->password));
    }

    public function test_a_municipal_admin_cannot_create_admin_accounts(): void
    {
        $this->actingAs($this->bulanAdmin)->postJson('/api/v1/admin/admin-accounts', $this->payload())->assertForbidden();

        $this->assertNull(User::where('email', 'maria@example.com')->first());
    }

    public function test_a_wrong_town_name_or_a_taken_email_creates_nothing(): void
    {
        $this->actingAs($this->provincial)->postJson('/api/v1/admin/admin-accounts', $this->payload(['confirm_municipality' => 'Bulan']))
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_municipality');
        $this->actingAs($this->provincial)->postJson('/api/v1/admin/admin-accounts', $this->payload(['email' => 'BULAN@example.com']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame(2, User::count());
        Mail::assertNothingSent();
    }
}
