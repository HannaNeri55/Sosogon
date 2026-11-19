<?php

namespace App\Console\Commands;

use App\Models\Municipality;
use App\Services\ListingDataChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The data problems ListingDataChecks finds in public listings, as a summary here and a CSV in
 * storage/app (one row per problem). Read-only: nothing is changed.
 */
class ListingDataChecksReport extends Command
{
    protected $signature = 'listings:data-checks {--town= : Only this municipality (by name)}';

    protected $description = 'List data problems in published listings (read-only) and save them as a CSV';

    public function handle(ListingDataChecks $checks): int
    {
        $towns = null;
        if ($name = $this->option('town')) {
            $id = Municipality::where('name', $name)->value('id');
            if ($id === null) {
                $this->error("No municipality named {$name}.");

                return self::FAILURE;
            }
            $towns = [(int) $id];
        }

        $issues = $checks->run($towns);
        $this->line('Database: '.DB::connection()->getDatabaseName().'. '.$issues->count().' problems in '.$issues->unique(fn ($i) => $i['type'].$i['id'])->count().' listings.');
        $this->table(['Problem', 'High', 'Medium', 'Low'], $issues->groupBy('code')->map(fn ($group, $code) => [
            $code, $group->where('severity', 'high')->count(), $group->where('severity', 'medium')->count(), $group->where('severity', 'low')->count(),
        ])->sortByDesc(fn ($row) => $row[1] * 1000 + $row[2] * 10 + $row[3])->values()->all());

        $path = storage_path('app/data-checks-'.now()->format('Ymd-His').'.csv');
        $out = fopen($path, 'w');
        fputcsv($out, ['severity', 'type', 'id', 'name', 'municipality', 'problem', 'message', 'fixed_by']);
        foreach ($issues as $i) {
            fputcsv($out, [$i['severity'], ListingDataChecks::TYPES[$i['type']], $i['id'], $i['name'], $i['municipality'], $i['code'], $i['message'], $i['fixed_by']]);
        }
        fclose($out);
        $this->info("Saved: {$path}");

        return self::SUCCESS;
    }
}
