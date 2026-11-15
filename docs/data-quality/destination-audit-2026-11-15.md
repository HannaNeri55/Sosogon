# Destination Data Quality Audit (Phase 1B)

**Date:** November 15, 2026
**Scope:** the 87 published destinations on the live site.
**Source:** the live public API (`/api/v1/destinations` and each destination's detail), read-only.
**Nothing was changed.** No records were edited. No tourism priority was assigned or removed.

This is a snapshot of the data taken this afternoon. Live data is still being edited: several Barcelona listings were updated today. Re-check any row whose `updated_at` is later before applying a decision.

Every proposal below rests only on what each listing itself says: its description, activities, address and fields. No outside facts were added. Where a listing contradicts its own data, the evidence is quoted.

The review sheet with all 87 rows is [destination-review-2026-11-15.csv](destination-review-2026-11-15.csv). It has a **Reviewer decision** and **Reviewer notes** column for each row.

---

## 1. Summary

| Finding | Count |
|---|---|
| Category that contradicts the listing's own description | 7 (+2 consistency cases on hold) |
| `tourism_priority` assignments to review (21 set, 66 empty) | 3 to review, 1 noted as supported |
| Listings that may not be tourist destinations, or duplicate another listing | 7 |
| Name typos or unclear names | 8 |
| Wrong or missing field values (setting, hours, location, activities) | 21 |
| Opening hours empty | 2 |
| Opening hours with an estimate record (listed hours unconfirmed) | 41 |
| Entrance fee known only as an estimate | 27 |
| Activities without a duration (in 11 listings) | 18 |
| Listings with no activities | 2 (Apin's Place, Marahlee Beach Resort) |
| No amenities listed | 22 |
| No contact number | 71 |
| Barangay only in the address text, not selected | 74 |
| Fewer than 3 photos | 4 |

---

## 2. Proposed classification system

### 2.1 Rules for the existing nine categories

Classify a place by **what a visitor mainly goes there to do**, not by its name or a feature it happens to have.

| Category | Use when | Not when |
|---|---|---|
| **Beach** | A public or community shoreline where the visit is the beach itself: swimming, sand, sunset. | A privately run resort that charges for cottages, rooms or pools: use **Resort**. |
| **Island** | The destination is an island or islet reached by boat: sandbars, island hopping. | A boat *tour* to see wildlife (for example whale sharks): use **Eco-Tourism**. |
| **Mountain** | Hills, highlands, ridges and viewpoints: trekking, views, sunrise or sunset. | A camp or café that is mainly a resort or restaurant. |
| **Waterfall** | A waterfall is the main attraction. | — |
| **Eco-Tourism** | Natural sites and nature experiences: rivers, springs left natural, mangroves, forests, farms open for tours, wildlife watching. | Developed pool resorts fed by springs: use **Resort**. |
| **Historical** | Heritage sites, ruins, monuments, museums, ancestral houses, sites with a historical marker. | Modern buildings in an old style; venues with no heritage content. |
| **Church** | Churches, shrines, pilgrimage sites, religious statues and devotional walks. | Church ruins or a former church that is now a museum: use **Historical**. |
| **Park** | Public parks, promenades, plazas and public recreation spaces. | Mangrove or forest eco parks: use **Eco-Tourism**. |
| **Resort** | Privately operated, open to day visitors (pools, cottages, beach or spring resorts). | A whole villa or house rented only for overnight stays: that is an **accommodation** listing. |

**Not a tourist destination** (decide whether to unpublish or move):
- schools and offices;
- sports venues with no visitor experience;
- private villas or houses rented whole for overnight stays. These belong in **Accommodations**.

### 2.2 The limit of one category per place

The planner chooses destinations by matching interests to **category IDs that are hard-coded** in `backend/app/Services/ItineraryService.php` (`INTEREST_CATEGORIES`):

| Interest | Categories it uses |
|---|---|
| beaches | Beach, Island |
| nature | Beach, Mountain, Waterfall, Eco-Tourism, Island |
| relaxation | Beach, Park, Island, Resort |

So a **beach resort can be a Beach or a Resort, never both**:
- As **Beach**, it never counts as a Resort.
- As **Resort**, it disappears from the *Beaches* and *Nature* interests.

That is why #6 Judith Rose and #9 Marahlee are **on hold**. Today they are Beach; the rule in 2.1 says Resort; but moving them would remove them from beach trips.

**Recommendation (a later phase, needs a schema change):**
- Keep one **primary category** for display.
- Add **experience tags** to each destination: beach, island, pool, spring, river, waterfall, viewpoint, hiking, heritage, religious, museum, farm, wildlife, water sports, camping, family.
- Have the planner match interests to tags instead of hard-coded category IDs.
- Possible new categories, only if the Tourism Office wants them: **Viewpoint / Scenic route** and **Spring & River**.

### 2.3 `tourism_priority` (guidance only)

These priorities must reflect **actual tourism significance**. They are not assigned here and should not be inferred by AI.

| Level | Suggested meaning |
|---|---|
| **iconic** | Defines Sorsogon for visitors (for example, what the province is promoted for). Very few places. |
| **popular** | Well known and widely visited across the province. |
| **recommended** | Good, reliable experience; worth including. |
| **hidden_gem** | Lesser known but genuinely worthwhile. |
| *(empty)* | Not yet assessed. |

**Recommended process:**
- The Provincial Tourism Office, with each municipal tourism office, assigns levels from their own records: promotion lists, visitor arrivals, DOT or provincial references.
- Each level should note **who set it and when**. This needs `priority_set_by` and `priority_set_at` fields in a later phase.

---

## 3. Proposed category corrections

| ID | Destination | Current | Proposed | Evidence (from the listing) |
|---|---|---|---|---|
| 89 | Balogo Sprts Complex (Sorsogon City) | Historical | Park, or unpublish | "a public sports facility … a recreation venue rather than a historical or sightseeing attraction" |
| 50 | Colegio Digital Barcelona | Historical | Decide if it is a destination | "an educational institution rather than a heritage site or attraction … at most a brief photo stop" |
| 53 | Vintage Bridge (Barcelona) | Historical | Park | "an old-style bridge that has become a local recreation spot"; all 5 activities are water sports |
| 61 | 16k Blue/Pink Roses and Mama Mary statue (Casiguran) | Historical | Church | "a religious site … for prayer, reflection and devotion" |
| 72 | Donsol WhaleShark Tour information | Island | Eco-Tourism | whale shark interaction by boat (boating, diving) |
| 62 | Bigaho Eco Park (Prieto Diaz) | Park | Eco-Tourism | mangrove forest walk, "an important breeding ground for fish and crustaceans" |
| 90 | Bacon Manito Road (Sorsogon City) | Eco-Tourism | Mountain (or a future "Scenic route" category) | scenic hill drive, cycling, photography |
| 6 | Judith Rose Beach Resort | Beach | Resort — **on hold** (see 2.2) | privately run: cabanas, kubo, event hall, entrance fee |
| 9 | Marahlee Beach Resort | Beach | Resort — **on hold** (see 2.2) | privately run: cottages, function hall, aircon rooms, entrance fee |

**How these changes affect itinerary interests:**
- Historical → Park takes a place **out of Culture/History** and **into Relaxation**.
- Island → Eco-Tourism takes the whale shark tour **out of Beaches**. It stays in Nature and Adventure.
- Park → Eco-Tourism moves Bigaho **from Relaxation to Nature and Adventure**.

## 4. `tourism_priority` review (no new assignments)

There are 21 assignments on live (66 destinations have none):
- **iconic (4):** Barcelona Ruins Park, Colegio Digital Barcelona, Balogo Sprts Complex, Lebanon Beach;
- **popular (7):** Bulusan Lake, Malawmawan Island, Donsol whale shark, Lola Sayong, San Benon, Subic Beach, Paguriran Lagoon;
- **recommended (5):** St. Joseph Parish, Camp Burabod, Vintage Bridge, Firefly Watching, LagoLand;
- **hidden_gem (5):** Bayugin Falls, Nagsipit Falls, Biyong Cold Spring, Kipot River, Tangke Nature Spring.

| ID | Destination | Current | Review |
|---|---|---|---|
| 89 | Balogo Sprts Complex | iconic | **Conflicts with its own text** ("not a historical or sightseeing attraction"). Tourism Office to confirm or remove. |
| 50 | Colegio Digital Barcelona | iconic | **Conflicts with its own text** ("not a heritage site or attraction"). Tourism Office to confirm or remove. |
| 93 | Lebanon Beach | iconic | The highest level, while its own text says "quiet … uncrowded, rustic". Tourism Office to confirm it is province-defining. |
| 21 | Barcelona Ruins Park | iconic | Supported by its own text (Spanish-era Presidencia, "one of the most iconic historical landmarks" of Barcelona). Keep unless the Tourism Office decides otherwise. |

The other 17 assignments raise no contradiction with their listings. They still need the Tourism Office's confirmation under 2.3.

## 5. Keep as a destination? (decisions needed)

| ID | Destination | Question |
|---|---|---|
| 50 | Colegio Digital Barcelona | A school. Keep, or unpublish? |
| 89 | Balogo Sprts Complex | A sports venue. Keep, or unpublish? |
| 87 | Villa Kulintang (Pilar) | A private villa rented whole. Move to Accommodations? |
| 49 | Camp Burabod Farmstead (Barcelona) | "booked as a Farmer's Villa overnight stay". Accommodation? |
| 70 | Residencia Del hamor (Casiguran) | **Same business** as the accommodation "Recidencia Del Hamor Full View" (spelling differs). Keep one, or link them. |
| 42 | Apin's Place (Bulan) | Private resort "for … celebrations and gatherings"; no activities, 1 photo. |
| 43 | Cañezal Resort (Bulan) | Private pool resort with a function hall (venue-type). |

## 6. Name corrections

| ID | Current | Proposed |
|---|---|---|
| 89 | Balogo Sprts Complex | Balogo Sports Complex |
| 96 | The woods mahongay Farm | The Woods Mahogany Farm |
| 101 | St. JamesThe greater Parish Church | St. James the Greater Parish Church |
| 59 | beach Oasis | Beach Oasis |
| 103 | Palogtoc falls | Palogtoc Falls |
| 61 | 16k Blue /pink Roses and Mama Mary statue | 16K Blue/Pink Roses and Mama Mary Statue |
| 72 | Donsol WhaleShark Tour information | Donsol Whale Shark Interaction |
| 70 | Residencia Del hamor | Match the accommodation listing once the correct spelling is confirmed |

## 7. Field corrections

- **Setting:**
  - #102 Punta Diamante: *indoor* → **outdoor** (an open-air stone watchtower).
  - #34 Old Houses of Juban: *indoor* → **outdoor** (houses seen from the Maharlika Highway).
  - Missing: #58 Paguriran Lagoon (→ outdoor), #43 Cañezal, #44 Isabel's.
- **Activities that repeat another listing** (the planner could schedule the same place twice):
  - #10 Capitol Park has the activity "Museo Sorsogon visit" (#14 is its own listing).
  - #20 St. Joseph Parish has "Barcelona Ruins Park visit" (#21 is its own listing).
- **Activity typo:** #28 Malawmawan Island, "sland Hopping" → "Island Hopping".
- **Location conflicts:**
  - #45 Kapehan sa Bulod: the address says Brgy. Butag, the description says Barangay Bonga.
  - #12 Bulan Heritage Museum: the barangay is Butag, but the address is "Zone IV Poblacion".
- **Opening hours to rewrite or confirm:**
  - Emoji and notes in the hours: #2 Camp Hiraya, #51 Camp Toril.
  - Empty: #56 Barbo Ocean View, #55 Biton Beach.
  - "24 hours" or "Always Open" for paid resorts: #6, #9, #43, #42, #75, #74, #52.
  - No opening time: #95 Pagol Beach ("until 8:00 PM").
- **No activities:** #42 Apin's Place, #9 Marahlee. The planner can only plan a generic "Explore …" visit.
- **Activity durations missing:** 18 activities in 11 listings, mostly in Bulan (#1, #2, #11, #12, #13, #42–45, #55, #56) and #49.

## 8. Missing or unconfirmed information

The details are per row in the CSV (column **Missing / unconfirmed information**).

| Field | Status |
|---|---|
| Opening hours | 41 listings have an *estimate* record. Confirm that the hours shown were verified with the site. |
| Entrance fee | 27 listings have only an estimate. |
| Contact number | Missing for 71 of 87. |
| Barangay | Selected for only 13; the others have it only in the address text. |
| Amenities | Missing for 22. |

These fields don't exist yet and are needed for reliable planning (a later phase, schema change):
- recommended visit duration;
- accessibility;
- access notes (boat-only, rough road);
- seasonal and tide limits;
- **last verified date and verifier**.

---

## 9. Next steps

1. The Tourism Office or a provincial admin reviews the CSV and fills in **Reviewer decision** for each flagged row: approve, reject or change.
2. Approved corrections are applied in a separate, approved step. Use the admin destination pages, or a reviewed script that runs as a dry run first, never directly on the live database without approval.
3. `tourism_priority` is set **only by the Tourism Office** (section 2.3).
4. A later phase handles experience tags (2.2) and the new verification fields (section 8). Both need schema changes.
