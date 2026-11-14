<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ReviewsItemChanges;
use App\Http\Controllers\Controller;
use App\Models\AccommodationRoom;
use App\Models\ActivityLog;
use App\Models\MenuItem;
use App\Models\RetailProduct;
use App\Support\AdminScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * One review queue over rooms, menu items and products, and bulk decisions on it.
 * Every decision, single or bulk, goes through the per-type controller's own approve or reject
 * action, so the rules stay in one place: approving an item never publishes its listing, and a
 * proposed change applies only the exact version the admin reviewed.
 *
 * A municipal admin's queue, counts and business filter hold only items of their municipality's
 * listings (AdminScope); a decision on any other item is refused by the per-type action.
 */
class AdminItemReviewController extends Controller
{
    use ReviewsItemChanges;

    private const TIMEZONE = 'Asia/Manila';

    private const MAX_BULK_ITEMS = 200;

    private const KINDS = [
        'rooms' => [
            'model' => AccommodationRoom::class,
            'table' => 'accommodation_rooms',
            'controller' => AdminAccommodationRoomController::class,
            'business_table' => 'accommodations',
            'business_key' => 'accommodation_id',
            'name' => 'COALESCE(item.name, item.room_number)',
            'with' => ['images', 'accommodation:id,name,user_id,status,municipality_id', 'accommodation.user:id,name', 'accommodation.municipality:id,name'],
        ],
        'menu' => [
            'model' => MenuItem::class,
            'table' => 'menu_items',
            'controller' => AdminMenuItemController::class,
            'business_table' => 'food_beverages',
            'business_key' => 'food_beverage_id',
            'name' => 'item.name',
            'with' => ['foodBeverage:id,name,user_id,status,municipality_id', 'foodBeverage.user:id,name', 'foodBeverage.municipality:id,name'],
        ],
        'products' => [
            'model' => RetailProduct::class,
            'table' => 'retail_products',
            'controller' => AdminRetailProductController::class,
            'business_table' => 'retail_shops',
            'business_key' => 'retail_shop_id',
            'name' => 'item.name',
            'with' => ['municipality', 'images', 'user:id,name', 'shop:id,name,user_id,status', 'shop.user:id,name'],
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tab' => 'nullable|in:new,changes,rejected',
            'kind' => 'nullable|in:rooms,menu,products',
            'business' => ['nullable', 'regex:/^(rooms|menu|products):\d+$/'],
            'q' => 'nullable|string|max:255',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'sort' => 'nullable|in:newest,oldest',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $tab = $validated['tab'] ?? 'new';
        $kinds = isset($validated['kind']) ? [$validated['kind']] : array_keys(self::KINDS);

        $query = DB::query()->fromSub($this->queue($tab, $kinds), 'reviews');

        if (! empty($validated['business'])) {
            [$businessKind, $businessId] = explode(':', $validated['business']);
            $query->where('kind', $businessKind)->where('business_id', (int) $businessId);
        }
        if (! empty($validated['q'])) {
            $term = '%'.$validated['q'].'%';
            $query->where(fn ($match) => $match->where('item_name', 'like', $term)->orWhere('business_name', 'like', $term));
        }

        // Proposed changes keep their date as ISO text; the bound has to compare the same way.
        $format = $tab === 'changes' ? 'Y-m-d\TH:i:s' : 'Y-m-d H:i:s';
        if (! empty($validated['from'])) {
            $query->where('submitted_on', '>=', Carbon::parse($validated['from'], self::TIMEZONE)->startOfDay()->utc()->format($format));
        }
        if (! empty($validated['to'])) {
            $query->where('submitted_on', '<=', Carbon::parse($validated['to'], self::TIMEZONE)->endOfDay()->utc()->format($format));
        }

        $direction = ($validated['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc';
        $page = $query->orderBy('submitted_on', $direction)->orderBy('kind')->orderBy('item_id', $direction)
            ->paginate($validated['per_page'] ?? 25);

        return response()->json(array_merge($this->withRecords($page)->toArray(), [
            'counts' => $this->counts(),
            'businesses' => $this->businesses($tab, $kinds),
        ]));
    }

    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'scope' => 'required|in:new,changes',
            'items' => 'required|array|min:1|max:'.self::MAX_BULK_ITEMS,
            'items.*.kind' => 'required|in:rooms,menu,products',
            'items.*.id' => 'required|integer',
            'items.*.changes_hash' => 'nullable|string',
            'notes' => [
                'nullable', 'string', 'max:1000',
                'required_if:action,reject',
                ...($request->input('scope') === 'changes' && $request->input('action') === 'reject' ? ['min:5'] : []),
            ],
        ]);

        $results = collect($validated['items'])
            ->unique(fn ($item) => "{$item['kind']}:{$item['id']}")
            ->map(fn ($item) => $this->decide($request, $validated, $item))
            ->values();

        $succeeded = $results->where('ok', true)->count();
        $failed = $results->count() - $succeeded;

        if ($succeeded > 0) {
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => "bulk_{$validated['action']}_items",
                'description' => sprintf(
                    'Bulk %s of %s: %d succeeded, %d failed',
                    $validated['action'] === 'approve' ? 'approval' : 'rejection',
                    $validated['scope'] === 'changes' ? 'proposed changes' : 'new items',
                    $succeeded,
                    $failed,
                ),
            ]);
        }

        return response()->json(['succeeded' => $succeeded, 'failed' => $failed, 'results' => $results]);
    }

    /**
     * One item, through its own controller action. Each action commits on its own,
     * so one bad item cannot undo or block the others.
     */
    private function decide(Request $request, array $validated, array $item): array
    {
        $config = self::KINDS[$item['kind']];
        $changes = $validated['scope'] === 'changes';
        $method = $validated['action'].($changes ? 'Changes' : '');

        $single = Request::create('/', 'PUT', array_filter([
            'notes' => $validated['notes'] ?? null,
            'changes_hash' => $item['changes_hash'] ?? null,
        ]));
        $single->setUserResolver($request->getUserResolver());

        $result = [
            'kind' => $item['kind'],
            'id' => $item['id'],
            'name' => $config['model']::whereKey($item['id'])->value($item['kind'] === 'rooms' ? DB::raw('COALESCE(name, room_number)') : 'name'),
        ];

        try {
            $response = app($config['controller'])->{$method}($single, $item['id']);

            return $result + ['ok' => $response->getStatusCode() < 300, 'message' => $response->getData()->message ?? ''];
        } catch (ModelNotFoundException) {
            return $result + ['ok' => false, 'message' => 'Already processed or no longer available.'];
        } catch (ValidationException $e) {
            return $result + ['ok' => false, 'message' => collect($e->errors())->flatten()->first() ?? 'This item could not be processed.'];
        } catch (HttpExceptionInterface $e) {
            return $result + ['ok' => false, 'message' => $e->getMessage() ?: 'This item could not be processed.'];
        } catch (\Throwable $e) {
            report($e);

            return $result + ['ok' => false, 'message' => 'This item could not be processed.'];
        }
    }

    /** One row shape across the three item tables, limited to a review tab. */
    private function queue(string $tab, array $kinds)
    {
        $branches = array_map(fn ($kind) => $this->branch($kind, $tab), $kinds);
        $union = array_shift($branches);

        foreach ($branches as $branch) {
            $union->unionAll($branch);
        }

        return $union;
    }

    /**
     * One kind's items joined to their business, limited to the admin's municipalities: a room's
     * or dish's listing must be in them, and a product's own municipality and its shop's.
     */
    private function scoped(string $kind)
    {
        $config = self::KINDS[$kind];
        $query = DB::table("{$config['table']} as item")
            ->leftJoin("{$config['business_table']} as business", 'business.id', '=', "item.{$config['business_key']}");

        $ids = AdminScope::municipalityIds(request()->user());
        if ($ids === null) {
            return $query;
        }
        if ($kind !== 'products') {
            return $query->whereIn('business.municipality_id', $ids);
        }

        return $query->whereIn('item.municipality_id', $ids)
            ->where(fn ($q) => $q->whereNull('business.id')->orWhereIn('business.municipality_id', $ids));
    }

    private function branch(string $kind, string $tab)
    {
        $config = self::KINDS[$kind];

        $query = $this->scoped($kind)
            ->select([
                DB::raw("'{$kind}' as kind"),
                'item.id as item_id',
                DB::raw("{$config['name']} as item_name"),
                'business.id as business_id',
                'business.name as business_name',
                match ($tab) {
                    'changes' => 'item.pending_changes->submitted_at as submitted_on',
                    'rejected' => 'item.updated_at as submitted_on',
                    default => 'item.created_at as submitted_on',
                },
            ]);

        return $tab === 'changes'
            ? $query->where('item.pending_changes->status', 'pending')
            : $query->where('item.status', $tab === 'rejected' ? 'rejected' : 'pending');
    }

    /** Swaps each page row for the full record the review panel needs. */
    private function withRecords(LengthAwarePaginator $page): LengthAwarePaginator
    {
        $records = [];

        foreach ($page->getCollection()->groupBy('kind') as $kind => $rows) {
            $config = self::KINDS[$kind];
            $models = $config['model']::with($config['with'])->whereIn('id', $rows->pluck('item_id'))->get();
            $this->withPendingChanges($models);
            $records[$kind] = $models->keyBy('id');
        }

        return $page->through(fn ($row) => [
            'key' => "{$row->kind}-{$row->item_id}",
            'kind' => $row->kind,
            'id' => $row->item_id,
            'name' => $row->item_name,
            'business_key' => $row->business_id ? "{$row->kind}:{$row->business_id}" : null,
            'business_name' => $row->business_name,
            'submitted_on' => $row->submitted_on,
            'row' => $records[$row->kind][$row->item_id] ?? null,
        ]);
    }

    private function counts(): array
    {
        $counts = ['new' => 0, 'changes' => 0, 'rejected' => 0, 'total' => 0];

        foreach (array_keys(self::KINDS) as $kind) {
            $counts['new'] += $this->scoped($kind)->where('item.status', 'pending')->count();
            $counts['rejected'] += $this->scoped($kind)->where('item.status', 'rejected')->count();
            $counts['changes'] += $this->scoped($kind)->where('item.pending_changes->status', 'pending')->count();
            $counts['total'] += $this->scoped($kind)->count();
        }

        return $counts;
    }

    /** The businesses that have something in this tab, for the filter menu. */
    private function businesses(string $tab, array $kinds): array
    {
        return DB::query()->fromSub($this->queue($tab, $kinds), 'reviews')
            ->whereNotNull('business_id')
            ->select('kind', 'business_id', 'business_name')
            ->distinct()
            ->orderBy('business_name')
            ->get()
            ->map(fn ($row) => ['key' => "{$row->kind}:{$row->business_id}", 'name' => $row->business_name])
            ->all();
    }
}
