<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\ListingVerifications;
use App\Support\AdminScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * Information Verification: the Tourism Office reviews a published listing's price, location,
 * hours and contact information and confirms the parts it checked (ListingVerifications). A
 * municipal admin works on their municipality's listings; provincial admins see every listing and
 * verify those of municipalities without their own office (AdminScope). Only provincial admins
 * change the overdue days.
 */
class AdminVerificationController extends Controller
{
    private const PER_PAGE = 20;

    /** Listing filters: a listing matches when any of its parts has that status (verified: all of them). */
    private const FILTERS = ['all', 'needs_attention', 'not_verified', 'overdue', 'changed', 'verified'];

    public function __construct(private ListingVerifications $verifications) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(array_keys(ListingVerifications::TYPES))],
            'municipality_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(self::FILTERS)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $admin = $request->user();
        $filter = $validated['status'] ?? 'needs_attention';
        $search = trim((string) ($validated['search'] ?? ''));

        $rows = collect();
        foreach ($validated['type'] ?? null ? [$validated['type']] : array_keys(ListingVerifications::TYPES) as $type) {
            $query = ListingVerifications::publicQuery($type)->with(['municipality:id,name', 'barangay:id,name']);
            $ids = AdminScope::municipalityIds($admin);
            $listings = $query
                ->when($ids !== null, fn ($q) => $q->whereIn('municipality_id', $ids))
                ->when($validated['municipality_id'] ?? null, fn ($q, $town) => $q->where('municipality_id', $town))
                ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
                ->get();
            $this->verifications->preload($type, $listings);
            foreach ($listings as $listing) {
                $rows->push($this->row($type, $listing, $request));
            }
        }

        $counts = collect(self::FILTERS)->mapWithKeys(fn ($key) => [$key => $rows->filter(fn ($row) => $this->matches($row, $key))->count()]);
        // Most in need of a check first: never verified, then changed, then overdue; then by name.
        $order = ['not_verified' => 0, 'changed' => 1, 'overdue' => 2, 'verified' => 3];
        $matching = $rows->filter(fn ($row) => $this->matches($row, $filter))
            ->sortBy([fn ($a, $b) => $this->urgency($a, $order) <=> $this->urgency($b, $order), fn ($a, $b) => strcasecmp($a['name'], $b['name'])])
            ->values();

        $page = (int) ($validated['page'] ?? 1);
        $paginator = new LengthAwarePaginator($matching->forPage($page, self::PER_PAGE)->values(), $matching->count(), self::PER_PAGE, $page);

        return response()->json($paginator->toArray() + [
            'counts' => $counts,
            'overdue_days' => ListingVerifications::overdueDays(),
            'aspects' => ListingVerifications::ASPECTS,
            'statuses' => ListingVerifications::STATUSES,
            'types' => ListingVerifications::TYPE_LABELS,
        ]);
    }

    public function show(Request $request, string $type, int $id): JsonResponse
    {
        $listing = $this->find($type, $id);
        AdminScope::authorizeRecordView($request->user(), $listing);

        return response()->json($this->details($type, $listing, $request));
    }

    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $listing = $this->find($type, $id);
        AdminScope::authorizeRecord($request->user(), $listing);
        $validated = $request->validate([
            'aspects' => ['required', 'array', 'min:1'],
            'aspects.*' => ['required', 'distinct', Rule::in(ListingVerifications::aspectsFor($type))],
            'note' => ['nullable', 'string', 'max:500'],
            'fingerprints' => ['nullable', 'array'],
            'fingerprints.*' => ['string', 'size:64'],
        ], [
            'aspects.required' => 'Choose at least one part of the information you checked.',
        ]);

        $note = trim((string) ($validated['note'] ?? '')) ?: null;
        $created = $this->verifications->verify($type, $listing, $validated['aspects'], $request->user(), $note, $validated['fingerprints'] ?? []);
        $labels = $created->map(fn ($row) => ListingVerifications::ASPECTS[$row->aspect])->all();

        return response()->json([
            'message' => (count($labels) > 1 ? implode(', ', array_slice($labels, 0, -1)).' and '.end($labels) : $labels[0]).' marked as verified.',
        ] + $this->details($type, $listing, $request), 201);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['overdue_days' => ListingVerifications::overdueDays(), 'defaults' => config('verification.overdue_days'), 'aspects' => ListingVerifications::ASPECTS]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $rules = collect(ListingVerifications::ASPECTS)->mapWithKeys(fn ($label, $aspect) => ["overdue_days.{$aspect}" => ['required', 'integer', 'min:1', 'max:3650']])->all();
        $validated = $request->validate(['overdue_days' => ['required', 'array']] + $rules);

        SiteSetting::put(ListingVerifications::SETTING_KEY, collect($validated['overdue_days'])->only(array_keys(ListingVerifications::ASPECTS))->map(fn ($days) => (int) $days)->all(), $request->user());

        return response()->json(['message' => 'Overdue periods saved.', 'overdue_days' => ListingVerifications::overdueDays()]);
    }

    /** A published listing of $type; anything else (unknown type, draft, rejected) is not found. */
    private function find(string $type, int $id): Model
    {
        abort_unless(isset(ListingVerifications::TYPES[$type]), 404);

        return ListingVerifications::publicQuery($type)->with(['municipality:id,name', 'barangay:id,name'])->findOrFail($id);
    }

    private function details(string $type, Model $listing, Request $request): array
    {
        $row = $this->row($type, $listing, $request, true);

        return [
            'listing' => $row,
            'history' => $this->verifications->historyOf($type, $listing)->map(fn ($entry) => [
                'id' => $entry->id,
                'aspect' => $entry->aspect,
                'label' => ListingVerifications::ASPECTS[$entry->aspect] ?? $entry->aspect,
                'verified_at' => $entry->verified_at->toIso8601String(),
                'verified_by' => $entry->verifier?->name,
                'note' => $entry->note,
            ])->values(),
        ];
    }

    private function row(string $type, Model $listing, Request $request, bool $withDetails = false): array
    {
        $marked = (object) [];
        AdminScope::mark($marked, $request->user(), $listing->municipality_id === null ? null : (int) $listing->municipality_id);

        return [
            'type' => $type,
            'type_label' => ListingVerifications::TYPE_LABELS[$type],
            'id' => $listing->getKey(),
            'name' => $listing->name,
            'municipality' => $listing->municipality?->name,
            'path' => ListingVerifications::TYPES[$type][1].'/'.$listing->getKey(),
            'can_manage' => $marked->can_manage,
            'managed_by' => $marked->managed_by,
            'aspects' => $this->verifications->statusOf($type, $listing, $withDetails),
        ];
    }

    private function matches(array $row, string $filter): bool
    {
        $statuses = array_column($row['aspects'], 'status');

        return match ($filter) {
            'all' => true,
            'verified' => $statuses !== [] && array_unique($statuses) === ['verified'],
            'needs_attention' => array_diff($statuses, ['verified']) !== [],
            default => in_array($filter, $statuses, true),
        };
    }

    private function urgency(array $row, array $order): int
    {
        return min(array_map(fn ($aspect) => $order[$aspect['status']], $row['aspects']) ?: [3]);
    }
}
