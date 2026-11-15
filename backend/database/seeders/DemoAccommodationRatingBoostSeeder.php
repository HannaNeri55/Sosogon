<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\Rating;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Local/demo only. Raises the sample ratings of selected accommodations: their
 * demo reviews of 1 to 3 stars become 4 or 5 stars, then, while the average is
 * still below 4.6, demo 4-star reviews become 5 stars. Each changed review gets
 * a new comment from DemoBusinessReviewSeeder's generator so text and score agree.
 *
 * Only rows flagged is_demo are changed, and only those whose comment starts
 * with "Demo Review:" or that have no comment (a star rating alone keeps none).
 * Genuine reviews, reviewers and review dates are kept.
 *
 * Idempotent: once a listing has no demo review below 4 stars and averages at
 * least 4.6, a rerun leaves it as it is.
 */
class DemoAccommodationRatingBoostSeeder extends DemoBusinessReviewSeeder
{
    private const PREFIX = 'Demo Review: ';

    /** Snapshot of accommodation ID => expected name; guards against changing the wrong records. */
    private const ACCOMMODATIONS = [
        24 => 'Siama Surf Resort',
        28 => 'Modesta Resort',
        37 => 'Recidencia Del Hamor Full View',
        44 => 'Mai’s Hotel and Resort',
        98 => 'IM Inn Sorsogon - Iconic Mardini Inn',
        101 => 'Casa Sereno Spring Resort',
        103 => 'FAITH Residences',
        108 => 'Melad Hotel',
        110 => "Gabz'K Hotel & Resort",
    ];

    /** Out of 100: how many raised reviews become 5 stars; the rest become 4. */
    private const FIVE_STAR_SHARE = 70;

    /** The average each listing is brought up to, if its demo 4-star reviews allow. */
    private const TARGET_AVERAGE = 4.6;

    public function run(): void
    {
        // Demo data must never be seeded in production.
        if (app()->environment('production')) {
            throw new RuntimeException('Demo reviews cannot be changed in production.');
        }

        $accommodations = Accommodation::whereIn('id', array_keys(self::ACCOMMODATIONS))->get()->keyBy('id');

        foreach (self::ACCOMMODATIONS as $id => $name) {
            if ($accommodations->get($id)?->name !== $name) {
                throw new RuntimeException("Accommodation ID {$id} does not match the expected property \"{$name}\".");
            }
        }

        $this->used = Rating::where('comment', 'like', self::PREFIX.'%')->pluck('comment')
            ->mapWithKeys(fn ($comment) => [Str::after($comment, self::PREFIX) => true])
            ->all();

        DB::transaction(function () use ($accommodations) {
            foreach (self::ACCOMMODATIONS as $id => $name) {
                $accommodation = $accommodations->get($id);
                $before = $accommodation->average_rating;

                // Seeded from the listing, so every run raises the same reviews the same way.
                $random = new Randomizer(new Mt19937(crc32('rating-boost#'.$id)));

                $demo = fn () => $accommodation->ratings()
                    ->where('is_demo', true)
                    ->where(fn ($q) => $q->where('comment', 'like', self::PREFIX.'%')->orWhereNull('comment'))
                    ->orderBy('id');

                $raised = 0;

                foreach ($demo()->where('score', '<=', 3)->get() as $rating) {
                    $this->raise($rating, $random->getInt(1, 100) <= self::FIVE_STAR_SHARE ? 5 : 4, $random);
                    $raised++;
                }

                $ratings = $accommodation->ratings()->whereNull('hidden_at');
                $sum = (int) (clone $ratings)->sum('score');
                $count = (clone $ratings)->count();

                foreach ($demo()->where('score', 4)->get() as $rating) {
                    if ($count === 0 || $sum / $count >= self::TARGET_AVERAGE) {
                        break;
                    }
                    $this->raise($rating, 5, $random);
                    $sum++;
                    $raised++;
                }

                // Mirrors ratings:recalculate.
                $accommodation->forceFill([
                    'average_rating' => round((float) ($accommodation->ratings()->visible()->avg('score') ?? 0), 2),
                    'total_reviews' => $accommodation->ratings()->visible()->count(),
                ])->save();

                $this->command?->info("{$name}: {$raised} demo reviews raised, average {$before} → {$accommodation->average_rating}.");
            }
        });
    }

    /** Gives a demo review its new score, and a matching comment unless it is a star rating alone. */
    private function raise(Rating $rating, int $score, Randomizer $random): void
    {
        $rating->timestamps = false;
        $rating->forceFill([
            'score' => $score,
            'comment' => $rating->comment === null ? null : self::PREFIX.$this->comment('stay', $score, $random),
        ])->save();
    }
}
