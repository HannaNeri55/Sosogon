<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeedbackSubmission;
use App\Support\AdminScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The Tourism Office's Feedback & Concerns queue. A municipal admin sees and handles feedback
 * about their municipality's listings; provincial admins see everything and handle general
 * feedback and listings of municipalities without their own office (AdminScope). Status moves
 * between Pending, Under Review, Resolved and Rejected; notes are internal and kept as a history.
 */
class AdminFeedbackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'open', ...array_keys(FeedbackSubmission::STATUSES)])],
            'category' => ['nullable', Rule::in(array_keys(FeedbackSubmission::CATEGORIES))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $validated['status'] ?? 'open';
        $admin = $request->user();

        $submissions = $this->visible(FeedbackSubmission::query(), $request)
            ->when($status === 'open', fn ($q) => $q->open())
            ->when(! in_array($status, ['all', 'open'], true), fn ($q) => $q->where('status', $status))
            ->when($validated['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when(trim((string) ($validated['search'] ?? '')), function ($q, $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('subject', 'like', $like)
                    ->orWhere('message', 'like', $like)->orWhere('email', 'like', $like)->orWhere('name', 'like', $like));
            })
            ->with(['municipality:id,name', 'handler:id,name'])
            ->latest()
            ->orderByDesc('id')
            ->paginate(15);

        $submissions->getCollection()->each(fn (FeedbackSubmission $s) => $this->describe($s, $request));

        $counts = $this->visible(FeedbackSubmission::query(), $request)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json($submissions->toArray() + [
            'counts' => collect(FeedbackSubmission::STATUSES)->map(fn ($label, $key) => (int) ($counts[$key] ?? 0)),
            // Waiting on this admin: open feedback they can act on (for the menu badge).
            'open_count' => AdminScope::applyManageable(FeedbackSubmission::query()->open()->toBase(), $admin)->count(),
            'statuses' => FeedbackSubmission::STATUSES,
            'categories' => FeedbackSubmission::CATEGORIES,
            'modules' => FeedbackSubmission::MODULES,
        ]);
    }

    /** Open feedback this admin can act on, for the menu badge. */
    public function openCount(Request $request): JsonResponse
    {
        return response()->json(['open_count' => AdminScope::applyManageable(FeedbackSubmission::query()->open()->toBase(), $request->user())->count()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $submission = FeedbackSubmission::with(['municipality:id,name', 'handler:id,name', 'notes.author:id,name'])->findOrFail($id);
        AdminScope::authorizeRecordView($request->user(), $submission);

        return response()->json(['feedback' => $this->describe($submission, $request)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $submission = FeedbackSubmission::findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $submission);
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(FeedbackSubmission::STATUSES))],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($submission, $validated, $request) {
            if ($submission->status !== $validated['status']) {
                $submission->forceFill(['status' => $validated['status'], 'handled_by' => $request->user()->id, 'handled_at' => now()])->save();
            }
            $this->addNote($submission, $validated['note'] ?? null, $request);
        });

        return response()->json(['message' => 'Feedback updated.', 'feedback' => $this->fresh($submission, $request)]);
    }

    public function storeNote(Request $request, int $id): JsonResponse
    {
        $submission = FeedbackSubmission::findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $submission);
        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        $this->addNote($submission, $validated['note'], $request);

        return response()->json(['message' => 'Note added.', 'feedback' => $this->fresh($submission, $request)], 201);
    }

    /** What the admin may see: their municipality's feedback, or everything for a provincial admin. */
    private function visible(Builder $query, Request $request): Builder
    {
        $ids = AdminScope::municipalityIds($request->user());

        return $ids === null ? $query : $query->whereIn('municipality_id', $ids);
    }

    private function addNote(FeedbackSubmission $submission, ?string $note, Request $request): void
    {
        if (($note = trim((string) $note)) !== '') {
            $submission->notes()->create(['user_id' => $request->user()->id, 'note' => $note]);
        }
    }

    private function fresh(FeedbackSubmission $submission, Request $request): FeedbackSubmission
    {
        return $this->describe($submission->fresh(['municipality:id,name', 'handler:id,name', 'notes.author:id,name']), $request);
    }

    /** Adds the labels, the listing's name and link, and whether this admin can act on it. */
    private function describe(FeedbackSubmission $submission, Request $request): FeedbackSubmission
    {
        $listing = $submission->listing();
        AdminScope::mark($submission, $request->user(), $submission->municipality_id);

        return $submission->setAttribute('category_label', FeedbackSubmission::CATEGORIES[$submission->category] ?? $submission->category)
            ->setAttribute('status_label', FeedbackSubmission::STATUSES[$submission->status] ?? $submission->status)
            ->setAttribute('module_label', FeedbackSubmission::MODULES[$submission->module] ?? null)
            ->setAttribute('listing', $submission->listing_type ? [
                'type' => $submission->listing_type,
                'id' => $submission->listing_id,
                'name' => $listing?->name ?? 'Listing no longer available',
                'path' => $listing ? self::PUBLIC_PATHS[$submission->listing_type].'/'.$listing->id : null,
            ] : null);
    }

    private const PUBLIC_PATHS = [
        'destination' => '/destinations',
        'accommodation' => '/accommodations',
        'food_beverage' => '/food-beverage',
        'retail_shop' => '/shops',
    ];
}
