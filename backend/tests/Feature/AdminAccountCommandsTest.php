<?php

namespace Tests\Feature;

use App\Models\AccountActionLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\TestProvincialAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * admin:assign and admin:create (who manages which municipality) and the test provincial admin
 * seeder. Test accounts only, on the test database.
 */
class AdminAccountCommandsTest extends TestCase
{
    use RefreshDatabase;

    private int $bulan;

    private int $matnog;

    private int $adminRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminRole = Role::create(['name' => 'admin', 'description' => 'Admin'])->id;
        Role::create(['name' => 'tourist', 'description' => 'Tourist']);
        Role::create(['name' => 'business_owner', 'description' => 'Business Owner']);
        $this->bulan = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $this->matnog = DB::table('municipalities')->insertGetId(['name' => 'Matnog', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function admin(string $email = 'tester.admin@example.com'): User
    {
        return User::create(['role_id' => $this->adminRole, 'name' => 'Tester Admin', 'email' => $email, 'password' => 'Original123!',
            'phone' => '09171234567', 'status' => 'active']);
    }

    // ==================== admin:assign ====================

    public function test_assigning_a_municipal_scope_asks_for_the_municipality_name_and_keeps_everything_else(): void
    {
        $admin = $this->admin();
        $before = $admin->fresh()->only(['name', 'email', 'password', 'phone', 'role_id', 'status', 'remember_token', 'created_at']);

        $this->artisan('admin:assign', ['user' => 'TESTER.ADMIN@example.com', '--municipality' => 'bulan'])
            ->expectsOutputToContain('Not assigned (full access until assigned)')
            ->expectsConfirmation('Apply this to Tester Admin <tester.admin@example.com>?', 'yes')
            ->expectsQuestion("Type the municipality's name to confirm (Bulan)", 'Bulan')
            ->expectsOutputToContain('Bulan Tourism Office')
            ->assertSuccessful();

        $admin->refresh();
        $this->assertSame([User::ADMIN_SCOPE_MUNICIPAL, $this->bulan], [$admin->admin_scope, (int) $admin->municipality_id]);
        $this->assertEquals($before, $admin->only(array_keys($before)), 'Credentials and account data are untouched.');
        $this->assertTrue(Hash::check('Original123!', $admin->password));
        $log = AccountActionLog::where('action', 'admin_scope_changed')->sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('Console (artisan admin:assign)', $log->admin_name);
    }

    public function test_a_provincial_scope_drops_the_municipality_and_a_designation_can_be_set_and_cleared(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['admin_scope' => 'municipal', 'municipality_id' => $this->bulan])->save();

        $this->artisan('admin:assign', ['user' => (string) $admin->id, '--provincial' => true, '--title' => 'System Administrator'])
            ->expectsConfirmation('Apply this to Tester Admin <tester.admin@example.com>?', 'yes')
            ->expectsOutputToContain('designation "System Administrator"')
            ->assertSuccessful();
        $admin->refresh();
        $this->assertSame(User::ADMIN_SCOPE_PROVINCIAL, $admin->admin_scope);
        $this->assertNull($admin->municipality_id, 'No leftover municipality.');
        $this->assertSame('System Administrator', $admin->adminProfile()['designation']);

        // Designation only: the scope stays.
        $this->artisan('admin:assign', ['user' => $admin->email, '--clear-title' => true])
            ->expectsConfirmation('Apply this to Tester Admin <tester.admin@example.com>?', 'yes')
            ->assertSuccessful();
        $this->assertNull($admin->fresh()->admin_title);
        $this->assertSame(User::ADMIN_SCOPE_PROVINCIAL, $admin->fresh()->admin_scope);
        $this->assertSame('Provincial Administrator', $admin->fresh()->adminProfile()['designation']);
    }

    public function test_dry_runs_declined_confirmations_and_a_mistyped_municipality_change_nothing(): void
    {
        $admin = $this->admin();

        $this->artisan('admin:assign', ['user' => $admin->email, '--municipality' => 'Bulan', '--dry-run' => true])
            ->expectsOutputToContain('Dry run: nothing was saved.')
            ->assertSuccessful();
        $this->artisan('admin:assign', ['user' => $admin->email, '--municipality' => 'Bulan'])
            ->expectsConfirmation('Apply this to Tester Admin <tester.admin@example.com>?', 'no')
            ->assertFailed();
        $this->artisan('admin:assign', ['user' => $admin->email, '--municipality' => (string) $this->matnog])
            ->expectsConfirmation('Apply this to Tester Admin <tester.admin@example.com>?', 'yes')
            ->expectsQuestion("Type the municipality's name to confirm (Matnog)", 'Bulan')
            ->assertFailed();

        $admin->refresh();
        $this->assertNull($admin->admin_scope);
        $this->assertNull($admin->municipality_id);
        $this->assertSame(0, AccountActionLog::count());
    }

    public function test_invalid_requests_are_refused(): void
    {
        $admin = $this->admin();
        $tourist = User::create(['role_id' => Role::where('name', 'tourist')->value('id'), 'name' => 'Some Tourist', 'email' => 'tourist@example.com', 'password' => 'Original123!']);

        $this->artisan('admin:assign', ['user' => $admin->email, '--municipality' => 'Atlantis'])
            ->expectsOutputToContain('No municipality matches "Atlantis"')->assertFailed();
        $this->artisan('admin:assign', ['user' => $admin->email, '--municipality' => '999'])->assertFailed();
        $this->artisan('admin:assign', ['user' => $admin->email, '--provincial' => true, '--municipality' => 'Bulan'])
            ->expectsOutputToContain('not both')->assertFailed();
        $this->artisan('admin:assign', ['user' => $admin->email])
            ->expectsOutputToContain('Nothing to change')->assertFailed();
        $this->artisan('admin:assign', ['user' => $admin->email, '--title' => '  '])->assertFailed();
        $this->artisan('admin:assign', ['user' => 'nobody@example.com', '--provincial' => true])->assertFailed();

        $this->artisan('admin:assign', ['user' => $tourist->email, '--provincial' => true])
            ->expectsOutputToContain('Only admin accounts can have an admin scope')->assertFailed();
        $tourist->refresh();
        $this->assertNull($tourist->admin_scope);
        $this->assertSame('tourist', $tourist->role->name, 'The role is never changed.');
        $this->assertNull($admin->fresh()->admin_scope);
    }

    // ==================== admin:create ====================

    public function test_creating_a_municipal_admin_with_a_hidden_password(): void
    {
        $this->artisan('admin:create', ['email' => 'maria.santos@example.com', '--name' => 'Maria Santos', '--municipality' => 'Bulan', '--title' => 'Bulan Tourism Office'])
            ->expectsOutputToContain('Municipal: Bulan')
            ->expectsQuestion('Password for the new admin (hidden; at least 8 characters with upper and lower case, a number and a symbol)', 'Bulan2026!')
            ->expectsQuestion('Type the password again', 'Bulan2026!')
            ->expectsConfirmation('Create this admin account for Maria Santos <maria.santos@example.com>?', 'yes')
            ->expectsQuestion("Type the municipality's name to confirm (Bulan)", 'bulan')
            ->doesntExpectOutputToContain('Bulan2026!')
            ->assertSuccessful();

        $admin = User::where('email', 'maria.santos@example.com')->sole();
        $this->assertTrue($admin->isMunicipalAdmin());
        $this->assertSame($this->bulan, (int) $admin->municipality_id);
        $this->assertSame('Bulan Tourism Office', $admin->admin_title);
        $this->assertTrue(Hash::check('Bulan2026!', $admin->password));
        $this->assertSame(1, AccountActionLog::where('action', 'admin_created')->count());
    }

    public function test_creating_a_provincial_admin_and_a_dry_run(): void
    {
        $this->artisan('admin:create', ['email' => 'juan.cruz@example.com', '--name' => 'Juan Cruz', '--provincial' => true, '--dry-run' => true])
            ->expectsOutputToContain('Provincial Administrator')
            ->expectsOutputToContain('Dry run: nothing was created')
            ->assertSuccessful();
        $this->assertSame(0, User::count());

        $this->artisan('admin:create', ['email' => 'juan.cruz@example.com', '--name' => 'Juan Cruz', '--provincial' => true])
            ->expectsQuestion('Password for the new admin (hidden; at least 8 characters with upper and lower case, a number and a symbol)', 'Province2026#')
            ->expectsQuestion('Type the password again', 'Province2026#')
            ->expectsConfirmation('Create this admin account for Juan Cruz <juan.cruz@example.com>?', 'yes')
            ->assertSuccessful();
        $admin = User::sole();
        $this->assertTrue($admin->isProvincialAdmin());
        $this->assertSame(User::ADMIN_SCOPE_PROVINCIAL, $admin->admin_scope);
        $this->assertNull($admin->municipality_id);
    }

    public function test_creation_is_refused_without_a_scope_with_a_taken_email_or_a_weak_password(): void
    {
        $this->admin('taken@example.com');
        $base = ['--name' => 'New Admin'];

        $this->artisan('admin:create', ['email' => 'TAKEN@example.com', '--provincial' => true] + $base)
            ->expectsOutputToContain('already exists')->assertFailed();
        $this->artisan('admin:create', ['email' => 'new@example.com'] + $base)
            ->expectsOutputToContain('Choose the admin\'s scope')->assertFailed();
        $this->artisan('admin:create', ['email' => 'new@example.com', '--municipality' => 'Atlantis'] + $base)->assertFailed();
        $this->artisan('admin:create', ['email' => 'new@example.com', '--provincial' => true])->assertFailed();
        $this->artisan('admin:create', ['email' => 'not-an-email', '--provincial' => true] + $base)->assertFailed();
        $this->artisan('admin:create', ['email' => 'new@example.com', '--provincial' => true] + $base)
            ->expectsQuestion('Password for the new admin (hidden; at least 8 characters with upper and lower case, a number and a symbol)', 'password')
            ->assertFailed();
        $this->artisan('admin:create', ['email' => 'new@example.com', '--provincial' => true] + $base)
            ->expectsQuestion('Password for the new admin (hidden; at least 8 characters with upper and lower case, a number and a symbol)', 'Province2026#')
            ->expectsQuestion('Type the password again', 'Province2026?')
            ->assertFailed();

        $this->assertSame(1, User::count(), 'Only the existing account.');
    }

    // ==================== Test provincial admin seeder ====================

    public function test_the_test_provincial_admin_seeder_is_safe(): void
    {
        $runWith = function (array $env) {
            foreach ($env as $key => $value) {
                putenv("{$key}={$value}");
            }
            try {
                (new TestProvincialAdminSeeder)->run();
            } finally {
                foreach (array_keys($env) as $key) {
                    putenv($key);
                }
            }
        };

        // No password given: nothing is created.
        try {
            $runWith([]);
            $this->fail('Expected a refusal without a password.');
        } catch (RuntimeException) {
            $this->assertSame(0, User::count());
        }

        $runWith(['TEST_PROVINCIAL_ADMIN_PASSWORD' => 'TestOnly2026!']);
        $account = User::where('email', TestProvincialAdminSeeder::EMAIL)->sole();
        $this->assertTrue($account->isProvincialAdmin());
        $this->assertSame(User::ADMIN_SCOPE_PROVINCIAL, $account->admin_scope);
        $this->assertTrue(Hash::check('TestOnly2026!', $account->password));

        // It never touches other accounts, and it can be removed.
        $other = $this->admin();
        $runWith(['TEST_PROVINCIAL_ADMIN' => 'remove']);
        $this->assertNull(User::where('email', TestProvincialAdminSeeder::EMAIL)->first());
        $this->assertNull($other->fresh()->admin_scope);

        // Not in production.
        $this->app['env'] = 'production';
        try {
            $runWith(['TEST_PROVINCIAL_ADMIN_PASSWORD' => 'TestOnly2026!']);
            $this->fail('Expected a refusal in production.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not run in production', $e->getMessage());
        }
        $this->assertNull(User::where('email', TestProvincialAdminSeeder::EMAIL)->first());
    }
}
