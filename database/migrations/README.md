# Database migrations

Migrations are plain `.sql` files applied in filename order by
`scripts/migrate.php`, which records each applied file (and its SHA-256) in the
`schema_migrations` table.

```bash
php scripts/migrate.php --status    # what is applied / pending
php scripts/migrate.php --dry-run   # list pending, change nothing
php scripts/migrate.php             # apply pending migrations
```

## Rules

1. **Name files `NNNN_short_description.sql`** with a zero-padded, monotonically
   increasing number. Ordering is a plain string sort.

2. **Write every statement idempotently.** MariaDB 10.2+ / MySQL 8 support the
   `IF [NOT] EXISTS` forms this project relies on:

   ```sql
   ALTER TABLE t ADD COLUMN IF NOT EXISTS c INT NULL;
   ALTER TABLE t ADD INDEX IF NOT EXISTS idx_c (c);
   CREATE TABLE IF NOT EXISTS t2 (...);
   INSERT IGNORE INTO settings (`name`,`value`) VALUES ('k','v');
   ```

   A migration that fails halfway must be safe to re-run: the runner stops at
   the first failure and does not mark the file as applied.

3. **Never edit a migration that has shipped.** The runner warns about checksum
   drift in `--status`, but an already-applied file is not re-run. Add a new
   migration instead. (One deliberate exception: in 3.80.3, 0001 and 0003 had their
   `INSERT IGNORE` defaults changed to the secure values, which only affects a
   database where those rows do not exist yet. Installations that applied the old
   files see a checksum warning for them in `--status`; it is expected.)

4. **Preserve data.** Widening a column, adding a nullable column and
   backfilling, or adding a new table are all fine. Dropping or narrowing a
   column needs an explicit, separately reviewed migration.

5. **Regenerate `database/schema.sql` whenever you add or change a migration**
   (`scripts/generate_schema.sh`). Clean installs load that file. It applies every
   migration to a scratch copy of the structure, then publishes the result: the
   structure, the rows the migrations seed, and a `schema_migrations` baseline with
   each file's checksum, so the first `migrate.php` on a clean install does nothing.
   CI fails if a clean install still has a pending or changed migration.

6. **Do not put secrets in migrations.** Credentials are generated at runtime by
   the application, never seeded here.
