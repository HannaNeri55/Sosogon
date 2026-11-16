# Experience Tagging Guide for Tourism Office Reviewers

**Date:** November 16, 2026
**For:** the provincial and municipal tourism office staff who review destinations in the SOSOGON admin.
**Worksheet:** [experience-tagging-worksheet-2026-11-16.csv](experience-tagging-worksheet-2026-11-16.csv) lists the 87 published destinations, grouped by municipality.

## 1. What this is for

Each destination has one **primary category** (Beach, Mountain, Church…). A category alone can't say everything a place offers: a beach resort is both a beach and a resort, and a heritage church is both religious and historical.

**Experience tags** record what visitors can actually do at a place. A destination can have several tags.

Later, the AI trip planner can use your confirmed tags to decide which places fit a tourist's interests (Nature, Beaches, Culture, History, Adventure, Relaxation). This is switched **off** for now. Nothing changes for tourists while you work.

- Tags are only visible in the admin.
- The primary category, the place's public page and its priority stay exactly as they are.

## 2. How to tag a destination

1. Log in to the admin and open **Manage Destinations**.
2. Open the destination and click **Edit**.
3. Scroll to the **Experiences** section.
4. Tick **every** experience that applies (see section 4).
5. Only when you are sure, tick **"Reviewed: these experiences are confirmed"**.
6. Click **Save**.

The list shows each destination's Experiences and whether it is reviewed. Municipal staff can tag the destinations of their own municipality; provincial staff can tag all.

## 3. Rules

1. **Tag only what the place really offers.** Use the listing's own details (description, activities, photos) and what your office has confirmed. Do not tag from guesses or from the category name alone.
2. **Tag everything that applies.** Once matching is turned on, a *reviewed* destination is matched **only by its tags**, no longer by its category. A missing tag means the place will not appear for that interest.
   - Example: a waterfall that also has a trail needs both **Waterfall** and **Hiking or trekking**.
3. **"Reviewed" means confirmed.** A destination needs at least one tag before it can be marked reviewed.
   - Tags saved *without* the Reviewed mark are drafts: the planner ignores them, and the place keeps matching by its category.
   - When unsure, leave Reviewed unticked and write why in the worksheet's Notes.
4. **Do the Phase 1B decisions first.** Eighteen rows have a "Phase 1B note": a possible category correction, a listing that may not be a tourist destination, a duplicate, or a name fix. Decide those before tagging. Do not tag a listing that will be unpublished or merged.
5. **Do not change other fields while tagging.** Leave the category, tourism priority and status as they are unless that change was decided separately.

## 4. The 18 experiences

The **Interests** column shows which tourist interests each tag brings a place into, once matching is on. Food and Shopping are planned from restaurants and shops, so no tag affects them.

| Experience | Use it when… | Interests |
|---|---|---|
| **Beach** | Visitors swim, walk on the sand or watch the sea at a shoreline. | Nature, Beaches, Relaxation |
| **Island or sandbar** | An island, islet or sandbar reached by boat, including island hopping. | Nature, Beaches, Adventure, Relaxation |
| **Waterfall** | Visitors go to see a waterfall, and often swim there. | Nature, Adventure |
| **Spring or river** | A natural cold or hot spring, or a river kept mostly natural. | Nature, Relaxation |
| **Swimming pool** | Built swimming pools open to visitors. | Relaxation |
| **Water sports** | Kayaking, paddleboarding, surfing, snorkeling, diving or other water activities. | Adventure |
| **Viewpoint** | A hill, highland or lookout visited **mainly for the view**. | Nature |
| **Hiking or trekking** | A trail, climb or trek **on foot**. | Nature, Adventure |
| **Forest or mangrove** | A forest, mangrove area or eco park with natural vegetation. | Nature |
| **Farm visit** | A working farm or garden open for tours or farm activities. | Nature |
| **Wildlife watching** | Seeing animals in the wild: whale sharks, fireflies, birds, marine life in a sanctuary. | Nature, Adventure |
| **Scenic route** | A road or route enjoyed for the drive or ride itself, with stops along the way. | Nature, Adventure |
| **Heritage site** | Ruins, old houses, monuments, lighthouses, or places with a historical marker. | Culture, History |
| **Museum** | A museum or gallery with exhibits. | Culture, History |
| **Religious site** | A church, shrine or pilgrimage site visited for worship or devotion. | Culture, History |
| **Resort (day use)** | A privately run resort that day visitors can enter, with cottages, pools or a beach. | Relaxation |
| **Recreation area** | A public park, promenade, plaza, playground or sports facility. | Relaxation |
| **Camping** | Tent pitching or overnight camping on site. | Nature, Adventure |

## 5. Points to watch

### Adventure
These tags put a place under **Adventure**: Island, Waterfall, Water sports, Hiking, Wildlife, Scenic route and Camping.

A place tagged **only** with Viewpoint, Forest or mangrove, Farm visit, or Spring or river will **not** appear under Adventure.
- If visitors climb or walk a trail to get there, add **Hiking or trekking**.
- If they kayak, snorkel or surf, add **Water sports**.

### Combinations to check
These are ways to read a listing, not decisions for any particular place:
- **A resort with a beach:** Beach + Resort, and Swimming pool if it has one.
- **A historic church:** Religious site, and Heritage site if it is a historic structure or has a historical marker.
- **A hill reached by a trail:** Viewpoint + Hiking or trekking. Viewpoint alone if visitors drive up or the view is the only activity.
- **A wildlife tour by boat:** Wildlife watching, plus Water sports if visitors swim, snorkel or dive.
- **A spring resort:** Spring or river + Resort, and Swimming pool if there are built pools.

### Other cases
- **Draft tags:** saved tags without the Reviewed mark are ignored by the planner.
- **No activities listed:** two listings (Apin's Place, Marahlee Beach Resort) have no activities. Check the place before tagging.

## 6. Using the worksheet

The worksheet lists every published destination, sorted by municipality:
- what the listing says (a short excerpt);
- its listed activities (taken from a local copy, so check the live page if anything looks different);
- its Phase 1B note.

To use it:
1. Write the chosen tags in **Experience tags**, separated by semicolons. For example: `Beach; Resort (day use); Swimming pool`.
2. Fill in **Reviewed?** (yes / not yet), **Reviewed by** and **Date**.
3. Enter the same choices in the admin (section 2). **The admin is the record**; the worksheet is for planning and sign-off.

## 7. After tagging

1. Return the completed worksheet.
2. The developer runs a **read-only comparison report**. It lists, for each interest and municipality:
   - which places would be added or removed;
   - any municipality that would lose its last place for an interest;
   - reviewed places with missing or unrecognized tags.
3. Your office reviews that report. Matching is turned on only after that review and a separate decision.
