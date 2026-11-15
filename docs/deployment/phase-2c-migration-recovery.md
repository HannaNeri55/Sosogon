# Phase 2C Migrations: Sequence, Failure Behaviour and Recovery

These are the experience tag migrations of Phase 2C (see `docs/data-quality/classification-design-2026-11-15.md`).

> **Production recovery needs a verified backup and authorized database access.**
> Do not run any statement in this document against production without both, and without the project owner's approval. Nothing here has been run against production or verified on TiDB.

---

## 1. The migration sequence

| Step | Migration | What it does | Safe to retry? |
|---|---|---|---|
| 1 | `2026_11_16_000000_create_experience_tags` | Creates `experience_tags` (unique `slug`). | Yes. An existing table is checked and completed, or the step stops. |
| 2 | `2026_11_16_000100_create_destination_experience_tag_table` | Creates the empty pivot: two foreign keys (destination **CASCADE**, tag **RESTRICT**), the unique `destination_experience_tag_unique` and the index on `experience_tag_id`. | Yes, as step 1. |
| 3 | `2026_11_16_000200_add_experience_review_to_destinations` | Adds the nullable `experiences_reviewed_at` (indexed) and `experiences_reviewed_by` (foreign key to `users`, **SET NULL**) to `destinations`. | Yes. Existing columns are checked, and missing parts are added. |
| 4 | `2026_11_16_000300_seed_experience_tags` | Adds the 18 vocabulary tags that are missing. | Yes. It inserts missing slugs only and never changes a stored tag. |

**What no step does:**
- assign tags to destinations;
- mark a destination reviewed;
- touch any existing destination value, category, `tourism_priority`, itinerary, rating or review.

**How the checks work** (`App\Support\Migrations\SchemaGuard`):
- Each step describes the structure it expects: columns with type, nullability, unsigned and length; the primary key; indexes; foreign keys with their delete rule.
- When the object already exists, the step verifies it against that description:
  - **missing parts** (an index, a foreign key, a column in `destinations`) are added;
  - **anything different** stops the step with an error that names the problem, before any change. Examples: an unexpected column, another type, an index under the expected name on other columns, a foreign key with another rule.
- After every step, the whole structure is checked again. If the database accepted a statement but did not create the object, the step fails instead of passing quietly. This can happen, for example, on a TiDB server without foreign key support.

---

## 2. Why partial failures happen, and what each failure leaves

- MySQL, MariaDB and TiDB **do not roll back DDL**.
- Laravel runs these migrations without a transaction on MySQL, and **records a migration only after its `up()` finishes**.
- A failure therefore leaves every statement already executed in that migration, with no record of the migration. Earlier steps stay recorded; later steps don't run.

| Failure in | Possibly left behind | Next `php artisan migrate` |
|---|---|---|
| Step 1 | `experience_tags` without its unique index | Step 1 checks the columns and adds the index. |
| Step 2 | Pivot table with none, or some, of its keys | Step 2 checks the columns and adds the missing keys. |
| Step 3 | `experiences_reviewed_at` only, or both columns without the index or foreign key | Step 3 checks the existing columns and adds the rest. |
| Step 4 | Nothing: the tags are added in one INSERT | Step 4 adds the missing tags. |
| Recording a step | The whole step's objects | The step finds them complete and records itself. |

On Render, `docker/start.sh` runs `php artisan migrate --force` with `set -e` at every container start. A failed step stops that start, and the next start retries automatically from the first unrecorded step.

If a step stops with **"does not have the expected structure"**, retrying alone will not help. See section 4.

---

## 3. Inspecting partially created objects (read-only)

Run these against the database in question (local, or production only with authorization):

```sql
-- Which steps are recorded
SELECT migration, batch FROM migrations WHERE migration LIKE '2026_11_16%' ORDER BY id;

-- Which objects exist
SHOW TABLES LIKE 'experience_tags';
SHOW TABLES LIKE 'destination_experience_tag';
SELECT column_name, column_type, is_nullable FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'destinations' AND column_name LIKE 'experiences%';

-- Their columns, indexes and foreign keys
SHOW CREATE TABLE experience_tags;
SHOW CREATE TABLE destination_experience_tag;
SELECT index_name, column_name, non_unique FROM information_schema.statistics
 WHERE table_schema = DATABASE() AND table_name IN ('experience_tags','destination_experience_tag','destinations')
   AND (table_name <> 'destinations' OR column_name LIKE 'experiences%');
SELECT constraint_name, table_name, referenced_table_name, delete_rule FROM information_schema.referential_constraints
 WHERE constraint_schema = DATABASE() AND table_name IN ('destination_experience_tag','destinations');

-- Data that must still be empty or NULL after a fresh deployment
SELECT COUNT(*) FROM experience_tags;                -- 18 once step 4 ran
SELECT COUNT(*) FROM destination_experience_tag;     -- 0
SELECT COUNT(*) FROM destinations WHERE experiences_reviewed_at IS NOT NULL OR experiences_reviewed_by IS NOT NULL;  -- 0
```

On TiDB, also record `SELECT VERSION(), @@tidb_enable_foreign_key;`. Foreign keys need TiDB v6.6+ with that variable ON.

## 4. Resuming, and handling an incomplete or different object

1. **A step failed for a transient reason** (connection dropped, timeout): run `php artisan migrate` again. On Render, restart or redeploy. The step completes what is missing.
2. **A step stopped with "does not have the expected structure"**:
   1. Read the message. It names the table and every difference. **"Nothing was changed by this migration"** means the step stopped before any DDL.
   2. Decide where the object came from. The usual cause is a hand-made or older version of the table or column. **Do not edit the migration to accept it.**
   3. Make the object match the description in section 1, with an approved change: drop the unexpected column, fix the type, or drop the wrong index or foreign key (the step re-adds the correct one).
   4. If the object holds no data that must be kept, you can instead remove only the Phase 2C objects (section 5) and migrate from that step.
   5. Run `php artisan migrate` again.
3. **A step reports objects still missing after it ran**: the database accepted the statement but did not create the object. Check that the server supports it (foreign keys on TiDB) **before** retrying.

## 5. Local recovery (XAMPP MariaDB)

1. Optional safety copy: `mysqldump -u <user> -p sosogon > sosogon-backup.sql`.
2. Inspect with section 3.
3. **Retryable:** `php artisan migrate`.
4. **Structural error:** fix the object (section 4), or remove only the Phase 2C objects, in this order and only those that exist:
   ```sql
   ALTER TABLE destinations DROP FOREIGN KEY destinations_experiences_reviewed_by_foreign;
   ALTER TABLE destinations DROP INDEX destinations_experiences_reviewed_by_foreign;   -- the foreign key's own index, if present
   ALTER TABLE destinations DROP INDEX destinations_experiences_reviewed_at_index;
   ALTER TABLE destinations DROP COLUMN experiences_reviewed_by, DROP COLUMN experiences_reviewed_at;
   DROP TABLE IF EXISTS destination_experience_tag;
   DROP TABLE IF EXISTS experience_tags;
   DELETE FROM migrations WHERE migration LIKE '2026_11_16%';   -- only for steps whose objects you removed
   ```
   Then run `php artisan migrate`.
5. **Rolling back recorded steps:** `php artisan migrate:rollback --step=N`. Step 3's `down()` removes only what exists. Step 4's `down()` does nothing; the tags go with step 1. Never use `migrate:fresh`, `migrate:reset` or `db:wipe` on a database whose data you need. On the development machine where Phase 2C was first built, the steps are split across two batches: read section 8 before rolling back there.

## 6. Before a production deployment

- [ ] Phase 2C tests and the full backend suite pass. CI (SQLite) passes on the pushed branch.
- [ ] The migrations ran on a disposable local MySQL-family database: fresh install, retry and partial-failure scenarios. This was done on MariaDB 10.4 on 2026-11-16, **not** on MySQL 8 or TiDB.
- [ ] **TiDB facts are known:** `SELECT VERSION(), @@tidb_enable_foreign_key;`, run read-only by someone authorized. If foreign keys are not supported or not enabled, step 2 and step 3 will **stop** (by design) and the deployment will not complete. Decide before deploying.
- [ ] **A verified, restorable backup or snapshot of the production database exists, and someone knows how to restore it.**
- [ ] Authorized database access is available during the deployment window, in case section 4 is needed.
- [ ] The Render branch that auto-deploys is confirmed. The deployment is watched in the Render logs: four migrations `DONE`, or the first error message.
- [ ] After deployment, read-only check (section 3): 18 tags, 0 pivot rows, 0 reviewed destinations, all four steps recorded. Manage Destinations and the public destination pages work.

## 7. Production recovery (only with a verified backup and authorized access)

1. Don't redeploy repeatedly. Read the first migration error in the Render logs.
2. Confirm the live site still works. If it does not, roll back the deployment in Render.
3. With authorized read-only access, inspect (section 3), including `VERSION()` and `@@tidb_enable_foreign_key`.
4. **Retryable failure:** restart or redeploy. The steps resume.
5. **Structural error, or a database without the needed feature:** stop. Plan the fix, take or confirm a backup, and apply an approved change (section 4) or remove only the Phase 2C objects (the section 5 list, adapted to what exists). On some TiDB versions, drop indexes before dropping a column.
6. Re-check with section 3, then deploy again.
7. **Never** run `migrate:fresh`, `migrate:reset`, `db:wipe` or `db:seed` against production.

## 8. Local migration history on the original development database

**This section applies only to the local XAMPP database (`sosogon`) of the machine where Phase 2C was first implemented.** A fresh installation and production have a different history (see 8.3).

### 8.1 What happened (verified on 2026-11-16)

1. The first version of Phase 2C was a **single** migration, `2026_11_16_000000_create_experience_tags`. It created both tables, added the review columns to `destinations` and seeded the vocabulary. It ran on the local database and was **recorded in batch 11**.
2. The migrations were then restructured into the four steps of section 1. **Step 1 keeps the same filename**, but it now creates only the `experience_tags` table.
3. Because that filename was already recorded, Laravel treats step 1 as already run there. Steps 2, 3 and 4 ran with an ordinary `php artisan migrate` and were **recorded in batch 12**. Their checks found every object complete and correct, so they ran no DDL. Step 4 added no tags, since all 18 existed.
4. **The existing local schema and data were preserved.** No table, column or migration record was dropped or deleted. The `experience_tags`, `destination_experience_tag`, `destinations`, `categories`, `itineraries`, `itinerary_items`, `ratings` and `users` tables had the same checksums before and after. All 87 destinations stayed published and not reviewed, with no tags.

| Migration | Local batch | What its current code does |
|---|---|---|
| `2026_11_16_000000_create_experience_tags` | 11 (recorded when it still did everything) | Creates `experience_tags` only |
| `2026_11_16_000100_create_destination_experience_tag_table` | 12 | Pivot table and its keys |
| `2026_11_16_000200_add_experience_review_to_destinations` | 12 | Review columns, index and foreign key |
| `2026_11_16_000300_seed_experience_tags` | 12 | Adds the missing vocabulary tags |

### 8.2 Rolling back on that local database

- **Ordinary rollback works in the right order.** `php artisan migrate:rollback` rolls back batch 12 first: steps 4, 3, then 2. The pivot table and the review columns are removed, and `experience_tags` stays. A second `php artisan migrate:rollback` then rolls back batch 11, and step 1 drops `experience_tags`. This is the dependency order: the pivot table, which references `experience_tags`, is gone before `experience_tags` is dropped.
- **Rolling back batch 11 alone can fail.** If batch 11 is targeted while batch 12 is still applied (for example `php artisan migrate:rollback --batch=11`), step 1 tries to drop `experience_tags` while the pivot table still references it through a foreign key, and the database refuses. This was not executed; it is the expected behaviour of the foreign key.
- **Such a failure is not data loss.** The `DROP TABLE` statement is refused as a whole. `experience_tags`, its 18 tags, the pivot table, the review columns and the batch 11 record all stay as they were.
- **Do not force it.** Do not drop tables by hand, run `SET FOREIGN_KEY_CHECKS = 0`, or otherwise bypass the foreign key to make that rollback succeed. Roll back batch 12 first, as above, and only when a rollback is really needed.

### 8.3 Fresh installations and production are different

- **None of the four migrations has been deployed or executed in production.** On the first deployment, `php artisan migrate --force` (run by `docker/start.sh`) executes steps 1 to 4 in order, normally recorded together in one new batch. Step 1 there is the current, table-only version; the original single migration never runs.
- A fresh local or test database behaves the same way: four steps, same batch, and a rollback of that batch runs steps 4, 3, 2 and 1 in the correct order.
- The batch 11 / batch 12 split exists **only** on the original development database. It needs no action there, and it must not be reproduced elsewhere, for example by copying migration records between databases.
