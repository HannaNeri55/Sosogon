<?php

namespace App\Console\Commands;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Municipality;
use Illuminate\Console\Command;

/**
 * Recomputes stored average_rating / total_reviews from the reviews that are
 * publicly visible (see config/reviews.php). Run after changing
 * SHOW_DEMO_REVIEWS. Bulan destinations are skipped unless --include-bulan.
 */
class RecalculateRatings extends Command
{
    protected $signature = 'ratings:recalculate {--include-bulan : Also recalculate destinations in the Municipality of Bulan}';

    protected $description = 'Recalculate stored listing ratings from visible reviews';

    public function handle(): int
    {
        $bulanId = Municipality::where('name', 'Bulan')->value('id');
        $changed = 0;

        foreach ([Destination::class, Accommodation::class, FoodBeverage::class] as $class) {
            $query = $class::query();
            if ($class === Destination::class && $bulanId && ! $this->option('include-bulan')) {
                $query->where('municipality_id', '!=', $bulanId);
            }

            foreach ($query->get() as $listing) {
                $average = round((float) ($listing->ratings()->visible()->avg('score') ?? 0), 2);
                $total = $listing->ratings()->visible()->count();

                if ((float) $listing->average_rating !== $average || (int) $listing->total_reviews !== $total) {
                    $listing->forceFill(['average_rating' => $average, 'total_reviews' => $total])->save();
                    $changed++;
                }
            }
        }

        $this->info("Ratings recalculated; {$changed} listing(s) changed.");

        return self::SUCCESS;
    }
}
