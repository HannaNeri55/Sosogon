<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\Municipality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * SOSOGON's curated destinations (services.itinerary.default_towns, priority_destinations and
 * full_day_destinations): where a trip the tourist chose no municipality for may go, which
 * destinations the tourism office promotes, and which deserve a day of their own.
 *
 * Everything is matched by name against the database: a town by its name, a destination by its
 * exact name within the town it's listed under. A renamed listing, or one moved to another town,
 * simply stops matching (and is logged); no ID is assumed and nothing is guessed. With none of
 * the default towns in the database there is no curated scope (active() is false).
 */
class PriorityDestinations
{
    private ?array $resolved = null;

    /** Reads the configuration and the database again on next use (each new trip: listings change). */
    public function forget(): void
    {
        $this->resolved = null;
    }

    /** Whether the curated scope applies: at least one default town exists. */
    public function active(): bool
    {
        return $this->resolve()['towns'] !== [];
    }

    /** IDs of the default towns found in the database. */
    public function townIds(): array
    {
        return $this->resolve()['towns'];
    }

    /** Priority destinations listed outside the default towns: the only places a default trip takes from their towns. */
    public function extraIds(): array
    {
        return $this->resolve()['extra'];
    }

    public function isPriority(Destination $destination): bool
    {
        return in_array((int) $destination->id, $this->resolve()['priority'], true);
    }

    public function isFullDay(Destination $destination): bool
    {
        return in_array((int) $destination->id, $this->resolve()['full_day'], true);
    }

    /** Narrows a destinations query to the curated scope; unchanged when there is none. */
    public function constrain(Builder $query): Builder
    {
        if (! $this->active()) {
            return $query;
        }
        $extra = $this->extraIds();

        return $query->where(fn ($scope) => $scope->whereIn('destinations.municipality_id', $this->townIds())
            ->when($extra !== [], fn ($scope) => $scope->orWhereIn('destinations.id', $extra)));
    }

    /** Whether a destination is within the curated scope (always, when there is none). */
    public function inScope(Destination $destination): bool
    {
        return ! $this->active()
            || in_array((int) $destination->municipality_id, $this->townIds(), true)
            || in_array((int) $destination->id, $this->extraIds(), true);
    }

    /** @return array{towns: array<int, int>, extra: array<int, int>, priority: array<int, int>, full_day: array<int, int>} */
    private function resolve(): array
    {
        $towns = (array) config('services.itinerary.default_towns', []);
        $priority = (array) config('services.itinerary.priority_destinations', []);
        $fullDay = (array) config('services.itinerary.full_day_destinations', []);
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $normal = fn (string $name) => mb_strtolower(trim($name));
        $townIds = Municipality::whereIn('name', array_unique([...$towns, ...array_keys($priority), ...array_keys($fullDay)]))
            ->get(['id', 'name'])->mapWithKeys(fn ($m) => [$normal($m->name) => (int) $m->id]);
        $defaultTowns = collect($towns)->map(fn ($t) => $townIds[$normal($t)] ?? null)->filter()->values()->all();

        $missing = [];
        $find = function (array $byTown) use ($townIds, $normal, &$missing): array {
            $ids = [];
            foreach ($byTown as $town => $names) {
                $listed = isset($townIds[$normal($town)])
                    ? Destination::where('municipality_id', $townIds[$normal($town)])->whereIn('name', $names)->get(['id', 'name'])
                        ->mapWithKeys(fn ($d) => [$normal($d->name) => (int) $d->id])
                    : collect();
                foreach ($names as $name) {
                    if (isset($listed[$normal($name)])) {
                        $ids[] = $listed[$normal($name)];
                    } else {
                        $missing[] = "{$name} ({$town})";
                    }
                }
            }

            return array_values(array_unique($ids));
        };
        $priorityIds = $find($priority);
        $fullDayIds = $find($fullDay);
        if ($defaultTowns !== [] && $missing !== []) {
            Log::warning('Curated itinerary destinations not found in their towns', array_values(array_unique($missing)));
        }

        $outside = $priorityIds === [] ? [] : Destination::whereIn('id', $priorityIds)->whereNotIn('municipality_id', $defaultTowns)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $this->resolved = [
            'towns' => $defaultTowns,
            'extra' => $defaultTowns === [] ? [] : $outside,
            'priority' => $priorityIds,
            'full_day' => $fullDayIds,
        ];
    }
}
