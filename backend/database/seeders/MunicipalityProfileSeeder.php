<?php

namespace Database\Seeders;

use App\Models\Municipality;
use Illuminate\Database\Seeder;

/**
 * The researched About-page profiles of the 15 local government units (data/municipality_profiles.php).
 * Fills only fields that are still empty, so anything a provincial admin has written is never
 * replaced; facts that could not be verified stay empty and show as "Not available".
 */
class MunicipalityProfileSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = require database_path('seeders/data/municipality_profiles.php');

        foreach ($profiles as $name => $profile) {
            $municipality = Municipality::where('name', $name)->first();
            if (! $municipality) {
                continue;
            }

            foreach ($profile as $field => $value) {
                if ($field === 'lgu_type' || blank($municipality->{$field})) {
                    $municipality->{$field} = $value;
                }
            }
            $municipality->save();
        }
    }
}
