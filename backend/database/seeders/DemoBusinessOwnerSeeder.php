<?php

namespace Database\Seeders;

use App\Models\BusinessSubmission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local-only demo Business Owner accounts, each with ONE pending business
 * submission marked is_sample, taken from database/seeders/data/demo_businesses.php.
 *
 * Nothing here is approved or published: the admin still activates the
 * account, approves the submission, and publishes the listing through the
 * normal screens. Not part of DatabaseSeeder; run it by name:
 *
 *   DEMO_PREVIEW=1 php artisan db:seed --class=DemoBusinessOwnerSeeder   (prints the plan, writes nothing)
 *   DEMO_OWNER_PASSWORD=... php artisan db:seed --class=DemoBusinessOwnerSeeder
 *
 * Safeguards: refuses production; requires DEMO_OWNER_PASSWORD; refuses to
 * write unless Bulan resolves to exactly one municipality and no record
 * resolves to (or mentions) Bulan; only ever creates rows, never updates or
 * deletes; skips any business whose name already exists, so re-runs add nothing.
 */
class DemoBusinessOwnerSeeder extends Seeder
{
    public const EMAIL_DOMAIN = 'demo.sosogon.test';

    public const CONTACT = 'Not verified';

    public const SAMPLE_LABEL = 'Sample listing for demonstration only — details have not been verified with the business.';

    public const APPROXIMATE_NOTE = 'Map location is approximate.';

    public const ADMIN_NOTE = 'SAMPLE DATA from SOSOGON_Data_Collection.xlsx (status: For Research). Contact, coordinates and details are unverified.';

    private const LISTING_TABLES = ['accommodation' => 'accommodations', 'retail_product' => 'retail_shops'];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo business owners cannot be seeded in production.');
        }

        $preview = getenv('DEMO_PREVIEW') === '1';
        $password = getenv('DEMO_OWNER_PASSWORD') ?: env('DEMO_OWNER_PASSWORD');
        if (! $preview && ! $password) {
            throw new RuntimeException('Set DEMO_OWNER_PASSWORD (local only) before seeding demo business owners.');
        }

        $plan = $this->plan(require database_path('seeders/data/demo_businesses.php'));

        if ($preview) {
            $this->printPlan($plan);

            return;
        }

        $role = Role::where('name', 'business_owner')->firstOrFail();
        $created = [];

        DB::transaction(function () use ($plan, $role, $password, &$created) {
            foreach ($plan as $row) {
                if ($row['action'] !== 'create') {
                    continue;
                }

                $owner = User::where('email', $row['email'])->first() ?? User::create([
                    'role_id' => $role->id,
                    'name' => $row['owner_name'],
                    'email' => $row['email'],
                    'password' => $password,
                    'status' => 'pending',
                    'business_type' => $row['type'],
                ]);

                $permitPath = "business-permits/{$owner->id}/sample-permit.pdf";

                (new BusinessSubmission)->forceFill([
                    'user_id' => $owner->id,
                    'business_name' => $row['name'],
                    'type' => $row['type'],
                    'description' => $row['description'],
                    'address' => $row['address'],
                    'municipality_id' => $row['municipality_id'],
                    'barangay_id' => $row['barangay_id'],
                    'latitude' => $row['latitude'],
                    'longitude' => $row['longitude'],
                    'contact_number' => self::CONTACT,
                    'operating_hours' => $row['operating_hours'],
                    'business_permit_path' => $permitPath,
                    'status' => 'pending',
                    'admin_notes' => $row['admin_notes'],
                    'is_sample' => true,
                ])->save();

                $created[] = $permitPath;
            }
        });

        // Each owner gets its own copy on the private disk (withdrawing a submission deletes its permit file).
        $pdf = file_get_contents(database_path('seeders/data/sample-permit.pdf'));
        foreach ($created as $path) {
            Storage::disk('local')->put($path, $pdf);
        }

        $skipped = count($plan) - count($created);
        $this->command?->info('Demo business owners: '.count($created)." created, {$skipped} skipped (already present). Bulan untouched.");
    }

    /**
     * Resolves every record and checks the Bulan guard before anything is written.
     * Throws on any problem, so a bad record stops the whole run.
     */
    private function plan(array $records): array
    {
        $bulanIds = DB::table('municipalities')->whereRaw('LOWER(TRIM(name)) = ?', ['bulan'])->pluck('id');
        if ($bulanIds->count() !== 1) {
            throw new RuntimeException("Expected exactly one Bulan municipality, found {$bulanIds->count()}. Nothing was written.");
        }
        $bulanId = $bulanIds->first();

        $plan = [];
        foreach ($records as $record) {
            $haystack = implode(' ', [$record['name'], $record['municipality'], $record['barangay'], $record['address']]);
            if (preg_match('/\bbulan\b/i', $haystack)) {
                throw new RuntimeException("Record \"{$record['name']}\" mentions Bulan. Nothing was written.");
            }

            $towns = DB::table('municipalities')->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($record['municipality']))])->pluck('id');
            if ($towns->count() !== 1) {
                throw new RuntimeException("Municipality \"{$record['municipality']}\" for \"{$record['name']}\" did not resolve to exactly one row. Nothing was written.");
            }
            $townId = $towns->first();
            if ((int) $townId === (int) $bulanId) {
                throw new RuntimeException("Record \"{$record['name']}\" resolves to Bulan. Nothing was written.");
            }

            if (! isset(self::LISTING_TABLES[$record['type']])) {
                throw new RuntimeException("Unsupported business type \"{$record['type']}\" for \"{$record['name']}\".");
            }

            $email = 'owner.'.Str::slug($record['name']).'@'.self::EMAIL_DOMAIN;
            // array_merge so the composed description replaces the source one.
            $plan[] = array_merge($record, [
                'municipality_id' => $townId,
                'barangay_id' => DB::table('barangays')->where('municipality_id', $townId)
                    ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($record['barangay']))])->value('id'),
                'email' => $email,
                'owner_name' => "Demo Owner – {$record['name']}",
                'description' => trim($record['description'].($record['approximate'] ? ' '.self::APPROXIMATE_NOTE : '').' '.self::SAMPLE_LABEL),
                'admin_notes' => self::ADMIN_NOTE.($record['approximate'] ? ' '.self::APPROXIMATE_NOTE : ''),
                'action' => $this->existing($record) ? 'skip' : 'create',
            ]);
        }

        if (count(array_unique(array_column($plan, 'email'))) !== count($plan)) {
            throw new RuntimeException('Two demo records produce the same owner email. Nothing was written.');
        }

        return $plan;
    }

    /** True when this business already exists as a submission or listing of the same type (from any owner). */
    private function existing(array $record): bool
    {
        $name = mb_strtolower(trim($record['name']));

        return DB::table('business_submissions')->where('type', $record['type'])->whereRaw('LOWER(TRIM(business_name)) = ?', [$name])->exists()
            || DB::table(self::LISTING_TABLES[$record['type']])->whereRaw('LOWER(TRIM(name)) = ?', [$name])->exists();
    }

    private function printPlan(array $plan): void
    {
        $this->command?->line('DEMO PREVIEW — nothing is written.');
        foreach ($plan as $row) {
            $this->command?->line(sprintf(
                '[%s] %s | %s | %s (id %d) | barangay "%s" %s | %s, %s%s | hours: %s | %s',
                $row['action'], $row['type'] === 'accommodation' ? 'Accommodation' : 'Retail shop', $row['name'],
                $row['municipality'], $row['municipality_id'], $row['barangay'],
                $row['barangay_id'] ? "→ id {$row['barangay_id']}" : '→ no exact match (left empty)',
                $row['latitude'], $row['longitude'], $row['approximate'] ? ' (approx.)' : '',
                $row['operating_hours'] ?? '(empty)', $row['email'],
            ));
        }
        $this->command?->line(sprintf('Would create %d, skip %d. Bulan records: none.',
            count(array_filter($plan, fn ($r) => $r['action'] === 'create')),
            count(array_filter($plan, fn ($r) => $r['action'] === 'skip'))));
    }
}
