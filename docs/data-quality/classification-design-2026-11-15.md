# Destination Classification: Compatibility and Rules Review (Phase 2B finalization)

**Date:** November 15, 2026
**Status:** The architecture direction is approved. The compatibility mapping and the interest-to-tag rules are **not final**.
**Not changed:** no migration, no code, and no tags written to any database (local or live).

**Evidence used:**
- the live snapshot of the 87 published destinations (read-only API);
- the local database (read-only queries);
- the current code.

All checks were run in memory and wrote nothing.

---

## 1. Verification results

| # | Check | Result | Evidence |
|---|---|---|---|
| 1 | All 87 destinations have a valid primary category | **Pass** | Live: 87 of 87 have a category ID 1–9 of type `destination`, and all are `published`. Local: same (87 published, 0 missing or invalid). Distribution: Resort 26, Beach 17, Eco-Tourism 14, Historical 10, Mountain 6, Park 5, Waterfall 4, Church 3, Island 2. |
| 2 | No category-default tag claims an unsupported experience | **Fails for the plan as approved** | The defaults "Mountain→viewpoint, Eco-Tourism→forest_mangrove, Historical→heritage…" would make **14 of 87** false claims (details below). |
| 3 | Transitional tags can't be mistaken for verified tags | **Resolved by design change** | No transitional tags are stored at all (see 2.1). |
| 4 | Stage 1 gives exactly the same eligible destinations as today | **Pass (with the design change)** | Category fallback, simulated on the live 87. All six interests are IDENTICAL: nature 43, beaches 19, culture 13, history 13, adventure 26, relaxation 50. |
| 5 | No change to existing itineraries, public category filters, PDF or admin permissions | **Pass by design** | `itineraries`, `itinerary_items` and `itinerary_stays` have no category or tag column. The public filter (`?category_id=`), the PDF badge and the admin category select are untouched. Tag edits go through the existing `AdminDestinationController::update` (`AdminScope::authorizeRecord`, `guardProvincialFields`). |
| 6 | Clear handling of unpublished, unreviewed and untagged destinations | **Defined** | See 2.3. |

### Why check 2 fails: default tags that would be false

**`forest_mangrove`** on 9 Eco-Tourism listings that are **not** forests or mangroves:
- springs and rivers: #36 Bucal-Bucalan Spring Resort, #46 Biyong Cold Spring, #60 Kipot River, #71 Sagurong, #83 Tangke Nature Spring;
- farms: #49 Camp Burabod Farmstead, #54 Balay Buhay sa Uma Bee Farm;
- a private villa: #87 Villa Kulintang;
- a road: #90 Bacon Manito Road.

**`heritage`** on Historical listings with no heritage:
- #53 Vintage Bridge (water sports);
- #61 16K Roses and Mama Mary statue (religious);
- #89 Balogo Sports Complex and #50 Colegio Digital Barcelona. A keyword check even "passed" for these two, because their text says "*not* a historical … attraction".

**`island`** on #72 Donsol whale shark tour (a boat tour, not an island).

**Conclusion:** a tag derived from a category inherits every category error (see the Phase 1B audit). Category defaults must not be **stored as tags**.

---

## 2. Revised classification rules

### 2.1 Stage 1: category fallback, no stored defaults

- A destination's **experiences are reviewed or not reviewed**. This is decided per destination, not per tag:
  - `destinations.experiences_reviewed_at` (nullable timestamp);
  - `destinations.experiences_reviewed_by` (nullable user ID);
  - the pivot `destination_experience_tag` holds **only reviewed tags**.
- **Matching rule:**
  - *Reviewed* → the destination matches an interest when one of its tags is in that interest's tag list.
  - *Not reviewed* → today's rule, unchanged: the interest's category list (`INTEREST_CATEGORIES`).
- With nothing reviewed, the results are identical to today (verified, check 4). Each later review changes only that one destination's interests, and the change is shown first in a diff report.
- **Transitional tags don't exist**, so they can't be confused with verified ones. This replaces the earlier "source = category_default" idea.

### 2.2 What reviewed tags are used for
Only reviewed destinations:
- match interests by tags;
- show experience chips on public pages;
- get an "Experiences: …" line in Claude's prompt.

Unreviewed destinations keep today's behaviour everywhere: category badge, category matching, and the planner's kind and operator logic (`experienceKind`, `needsOperator`, `visitTips`, `sheltered`, `isResortDestination` read tags only when reviewed, otherwise the category as now).

### 2.3 Unpublished, unreviewed and untagged destinations
| State | Itinerary planner | Public | Admin |
|---|---|---|---|
| **Unpublished** (`draft`, `archived`) | Never eligible (the existing `published()` scope), whatever its tags | Hidden as now | Tags kept; can be reviewed. Phase 1B publish decisions come before tagging. |
| **Published, not reviewed** | Category rule (today's behaviour) | Category badge only; no experience chips | Shows "Experiences: not reviewed"; included in the review sheet |
| **Published, reviewed** | Tag rule | Category badge + experience chips | Shows "Reviewed by … on …"; editing tags keeps it reviewed and records the editor |
| **"Untagged"** | Cannot happen while reviewed: marking reviewed **requires at least one tag** (validation). To keep a place out of itineraries, unpublish it; tags are never used for that. | — | Removing the last tag clears the reviewed status (back to the category rule) |

### 2.4 Admin permissions (unchanged)
- Tags and the review mark are edited through the existing destination edit (`AdminDestinationController::update`). The same scope rules apply: provincial admins for any town, municipal admins for their own town.
- **Open decision:** should *marking reviewed* be provincial-only? If so, use `AdminScope::guardProvincialFields` exactly as for the other provincial-only fields.

---

## 3. Interest-to-tag rules: open questions (not final)

The proposed target map (from the Phase 2B design):

| Interest | Proposed tags | Questions to decide |
|---|---|---|
| nature | beach, island, waterfall, spring_river, forest_mangrove, viewpoint, hiking, farm, wildlife | Should *nature* include beaches (it does today, through the Beach category)? Should it include farms? |
| beaches | beach, island | Should *island* without a beach count (sandbars, island hopping)? |
| culture, history | heritage, museum, religious | Should *culture* and *history* differ, for example history = heritage + museum only? Today they are identical. |
| adventure | hiking, water_sports, island, waterfall, wildlife, camping | Today adventure = Mountain, Waterfall, Eco-Tourism, Island. Under tags, viewpoint-only hills and calm springs would leave *adventure*. Is that intended? |
| relaxation | beach, island, resort, pool, spring_river, recreation | Should springs and parks count? Should beaches stay (they do today)? |
| food, shopping | none | No change: planned from restaurants and shops. |

**Recommended way to decide:**
1. The Tourism Office answers the questions above.
2. Draft tags for the 87 (keyword-seeded, Phase 1B corrections applied) are reviewed in a sheet.
3. Before anything is applied, a **membership diff** shows per interest which destinations would join or leave.

Approve the rules and the tags together, after seeing the diff.

---

## 4. Implementation phases (revised; none started)

1. **2C — Storage:**
   - `experience_tags` vocabulary table and seeder;
   - `destination_experience_tag` pivot;
   - `experiences_reviewed_at` and `experiences_reviewed_by` on `destinations`.

   All additive. No tag rows are written. Admin can view and edit tags (local first).
2. **2D — Matching behind a flag:** `services.itinerary.match_by_tags` (default off). The matcher uses reviewed tags, otherwise the category rule.
   - **Tests:** with zero reviewed destinations, every interest's eligible set equals today's (flag on and off). The full itinerary suite passes both ways.
3. **2E — Review sheet and diff report:** a read-only command produces the per-destination draft-tag sheet and the interest-membership diff. Nothing is applied.
4. **2F — Apply reviewed tags** with explicit approval: local first, then live through a dry-run-first command. Turn the flag on.

## 5. Not done in this phase
No migration, no model or controller changes, no tags written, no change to `INTEREST_CATEGORIES`, and no live database access other than the public read-only API.
