---
name: mysql-patterns
description: Use when writing or reviewing MySQL/MariaDB queries, migrations, or schema in this CodeIgniter 4 project (xpages.vn). Covers safe parameterization with CI4 Query Builder, indexing strategy, EXPLAIN reading, FULLTEXT vs LIKE, charset/collation, JSON columns, pagination, soft deletes, and migration patterns. Reject patterns that are Postgres-only or assume Eloquent.
---

# MySQL / MariaDB Patterns — CodeIgniter 4

This project runs on **MySQL 8.x / MariaDB 10.5+** via CI4 Query Builder and `CodeIgniter\Model`. Use these patterns whenever writing queries, indexes, migrations, or reviewing slow SQL.

## When to invoke

- Adding/changing a query in `app/Models/**` or `app/Controllers/**`
- Writing a migration in `app/Database/Migrations/**`
- Tuning slow pages / N+1 problems
- Designing a new table or index
- Reviewing a query for safety (SQL injection) or correctness

## Hard rules (non-negotiable)

1. **NEVER concatenate user input into SQL.** Always use Query Builder bindings or `?` placeholders with `$db->query($sql, [$param1, $param2])`.
2. **NEVER use `query($rawSql)` with interpolated PHP variables.** That's how SQL injection happens.
3. **NEVER `SELECT *` in production code.** List columns explicitly — cheaper, clearer, survives schema changes.
4. **Every query that filters/sorts/joins on a column must be index-backed.** If you can't prove the index exists, run `SHOW INDEX FROM <table>` and add one in a migration.
5. **No queries inside loops** (N+1). Use `whereIn()`, `JOIN`, or batch with `findAll(ids)`.
6. **Pagination must be bounded** — no unbounded `findAll()` over large tables; use `paginate()` or `limit() + offset()`.
7. **Use transactions** for multi-statement writes: `$db->transStart() … $db->transComplete()`.
8. **Charset is `utf8mb4` + `utf8mb4_unicode_ci`** — not `utf8` (which is 3-byte and breaks emoji/CJK).

## Safe Query Patterns (CI4)

### Query Builder with bindings (preferred)

```php
$rows = $db->table('posts')
    ->select('id, title, slug, created_at')
    ->where('status', 'published')
    ->where('author_id', $authorId)        // bound automatically
    ->whereIn('category_id', $categoryIds) // safe array binding
    ->orderBy('created_at', 'DESC')
    ->limit(20, $offset)
    ->get()
    ->getResultArray();
```

### Raw query — only with explicit bindings

```php
$sql = 'SELECT id, title FROM posts WHERE author_id = ? AND status = ?';
$rows = $db->query($sql, [$authorId, 'published'])->getResultArray();
```

### LIKE — escape `%` and `_` in user input

CI4 Query Builder does this for you:

```php
$db->table('posts')->like('title', $userInput, 'both')->get();
// generates: WHERE `title` LIKE '%<escaped>%' with % and _ escaped
```

Never build LIKE yourself: `WHERE title LIKE '%$input%'` ← **forbidden**.

### Insert / Update / Delete

```php
$db->table('posts')->insert([
    'title'  => $title,
    'slug'   => $slug,
    'status' => 'draft',
]);

$db->table('posts')
    ->where('id', $id)
    ->where('author_id', $authorId)   // always scope writes
    ->update(['status' => 'published']);

// Bulk insert — single round-trip
$db->table('post_tags')->insertBatch($rows);
```

### Transactions

```php
$db->transStart();
$db->table('posts')->insert($postData);
$postId = $db->insertID();
$db->table('post_tags')->insertBatch($tagRows);
$db->transComplete();
if ($db->transStatus() === false) {
    // rolled back
}
```

## Indexing Strategy

### When to add an index

Add an index when a column appears in:
- `WHERE` (equality or range)
- `JOIN ... ON`
- `ORDER BY`
- `GROUP BY`
- Foreign keys (always)

Do NOT index:
- Tiny tables (< ~1000 rows) — full scan is faster
- Columns with very low cardinality (e.g. `is_active` boolean) unless used in composite
- Columns you never filter/sort on (indexes cost writes + storage)

### Composite indexes — order matters

Rule: **most selective first**, then equality columns, then range/sort columns.

For `WHERE status = ? AND author_id = ? ORDER BY created_at DESC`:

```php
// migration
$this->forge->addKey(['status', 'author_id', 'created_at'], false, false, 'idx_posts_status_author_created');
```

A composite `(status, author_id, created_at)` covers:
- `WHERE status = ?` ✅
- `WHERE status = ? AND author_id = ?` ✅
- `WHERE status = ? AND author_id = ? ORDER BY created_at` ✅
- `WHERE author_id = ?` ❌ (leftmost prefix rule)

### Covering indexes

If a query only needs columns that are all in the index, MySQL can serve it from the index alone (no row lookup). Useful for hot list queries:

```sql
-- INDEX(status, created_at, id, title)
SELECT id, title FROM posts WHERE status='published' ORDER BY created_at DESC LIMIT 20;
```

## Reading EXPLAIN

Always `EXPLAIN` a query you're not sure about. Key columns:

| Column | Good | Bad |
|---|---|---|
| `type` | `const`, `eq_ref`, `ref`, `range` | `ALL` (full scan), `index` (full index scan) |
| `key` | a named index | `NULL` (no index used) |
| `rows` | small number | millions when you expect few |
| `Extra` | `Using index` (covering), `Using where` | `Using filesort`, `Using temporary` |

```sql
EXPLAIN SELECT id, title FROM posts WHERE status='published' ORDER BY created_at DESC LIMIT 20;
```

If `Using filesort` appears on a hot path, the `ORDER BY` is not index-backed — add or reorder the composite index.

## Full-Text Search vs LIKE

This project does multi-term AND full-text search. Use **`FULLTEXT` index + `MATCH … AGAINST`**, not `LIKE '%term%'` (which can never use an index).

### Migration

```php
// MyISAM is not required on MySQL 5.6+/MariaDB 10.0+ — InnoDB supports FULLTEXT
$this->db->query("ALTER TABLE posts ADD FULLTEXT INDEX ft_posts_title_content (title, content)");
```

### Query (AND mode for multi-term)

```php
$terms = '+php +codeigniter';   // boolean mode: + = required
$rows = $db->query(
    "SELECT id, title, MATCH(title, content) AGAINST(? IN BOOLEAN MODE) AS score
     FROM posts
     WHERE MATCH(title, content) AGAINST(? IN BOOLEAN MODE)
       AND status = 'published'
     ORDER BY score DESC
     LIMIT 20",
    [$terms, $terms]
)->getResultArray();
```

Notes:
- `ft_min_word_len` (MyISAM) / `innodb_ft_min_token_size` defaults to 3 — shorter terms are ignored. Test with realistic input.
- For Vietnamese, set `innodb_ft_enable_stopword = OFF` and consider a custom stopword list; CJK needs ngram parser (`WITH PARSER ngram`).
- Fallback to `LIKE` only for tiny tables or admin search.

## Soft Deletes

CI4 Models support soft deletes natively:

```php
protected $useSoftDeletes = true;
protected $deletedField   = 'deleted_at';
```

In raw Query Builder, you must add `WHERE deleted_at IS NULL` yourself, or the row will appear in results. Composite index for soft-deleted tables:

```
INDEX (deleted_at, status, created_at)
```

so that the `deleted_at IS NULL` predicate is index-backed.

## Pagination — beware deep OFFSET

`LIMIT 20 OFFSET 100000` forces MySQL to scan + discard 100k rows. For deep pagination use **keyset pagination**:

```php
// instead of OFFSET
$rows = $db->table('posts')
    ->select('id, title, created_at')
    ->where('status', 'published')
    ->where('created_at <', $lastSeenCreatedAt) // cursor
    ->orderBy('created_at', 'DESC')
    ->limit(20)
    ->get()->getResultArray();
```

CI4's `paginate()` is fine for shallow admin lists; switch to keyset for public infinite scroll over big tables.

## JSON Columns

MySQL 5.7+/MariaDB 10.2+ support JSON. Use for flexible metadata, not as a substitute for relational design.

```php
// migration
$this->forge->addField([
    'meta' => ['type' => 'JSON', 'null' => true],
]);

// query
$db->table('posts')
    ->where("JSON_EXTRACT(meta, '$.featured')", true)
    ->get();

// generated column for indexing a JSON path
$this->db->query("
    ALTER TABLE posts
    ADD COLUMN featured TINYINT(1) GENERATED ALWAYS AS
      (JSON_EXTRACT(meta, '$.featured')) VIRTUAL,
    ADD INDEX idx_posts_featured (featured)
");
```

## Charset / Collation

- Tables: `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` (or `utf8mb4_0900_ai_ci` on MySQL 8).
- Connection: ensure `app/Config/Database.php` has `'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_unicode_ci'`.
- Verify legacy tables: `SHOW TABLE STATUS;` — convert with `ALTER TABLE x CONVERT TO CHARACTER SET utf8mb4`.

## Migration Patterns (CI4 Forge)

```php
public function up()
{
    $this->forge->addField([
        'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
        'author_id'  => ['type' => 'INT', 'unsigned' => true],
        'title'      => ['type' => 'VARCHAR', 'constraint' => 255],
        'slug'       => ['type' => 'VARCHAR', 'constraint' => 255],
        'status'     => ['type' => 'ENUM', 'constraint' => ['draft','published','archived'], 'default' => 'draft'],
        'content'    => ['type' => 'LONGTEXT', 'null' => true],
        'meta'       => ['type' => 'JSON', 'null' => true],
        'created_at' => ['type' => 'DATETIME', 'null' => true],
        'updated_at' => ['type' => 'DATETIME', 'null' => true],
        'deleted_at' => ['type' => 'DATETIME', 'null' => true],
    ]);
    $this->forge->addPrimaryKey('id');
    $this->forge->addUniqueKey('slug', 'uq_posts_slug');
    $this->forge->addKey(['status', 'author_id', 'created_at'], false, false, 'idx_posts_status_author_created');
    $this->forge->addKey('deleted_at', false, false, 'idx_posts_deleted_at');
    $this->forge->addForeignKey('author_id', 'users', 'id', 'CASCADE', 'RESTRICT', 'fk_posts_author');
    $this->forge->createTable('posts', true, ['ENGINE' => 'InnoDB', 'CHARSET' => 'utf8mb4', 'COLLATE' => 'utf8mb4_unicode_ci']);

    // FULLTEXT must be added via raw SQL — Forge doesn't expose it
    $this->db->query('ALTER TABLE posts ADD FULLTEXT INDEX ft_posts_title_content (title, content)');
}

public function down()
{
    $this->forge->dropTable('posts', true);
}
```

### Safe schema changes on big tables

`ALTER TABLE` on a 10M-row table can lock for minutes. Mitigations:

1. **MySQL 8 `ALGORITHM=INPLACE, LOCK=NONE`** for index adds:
   ```sql
   ALTER TABLE posts ADD INDEX idx_x (x), ALGORITHM=INPLACE, LOCK=NONE;
   ```
2. **`pt-online-schema-change`** (Percona Toolkit) for risky changes.
3. **Backfill in batches** — never `UPDATE table SET col = ...` on the whole table in one statement; loop with `LIMIT 1000` and a primary key cursor.

## Common Pitfalls

| Symptom | Likely cause | Fix |
|---|---|---|
| `Using filesort` on hot list | `ORDER BY` not in index | Extend composite index with the sort column at the end |
| Slow `WHERE col IN (huge list)` | Too many bindings or no index | Index the column; consider temp table for very large lists |
| Slow `LIKE '%term%'` | Cannot use index | Use `FULLTEXT` + `MATCH ... AGAINST` |
| Slow deep pagination | High `OFFSET` | Switch to keyset / cursor pagination |
| Duplicate row results | `JOIN` to one-to-many without `GROUP BY` / `DISTINCT` | Use subquery or `EXISTS`, or aggregate explicitly |
| `Data truncated for column` | Mismatched charset / column too small | `utf8mb4`, widen column |
| `Cannot add foreign key constraint` | Type/charset mismatch or orphan rows | Match exact column type+charset, clean orphans first |
| N+1 in a list view | Loading related rows in a loop | Eager-load with `whereIn(parentIds)` and group in PHP |

## Postgres-only things — REJECT in this project

- `RETURNING` clause → use `insertID()` after `insert()`
- `ON CONFLICT … DO UPDATE` → MySQL uses `INSERT … ON DUPLICATE KEY UPDATE` or `REPLACE INTO`
- Array columns (`text[]`, `jsonb[]`) → use a join table or `JSON`
- `CITEXT`, partial indexes, `EXCLUDE` constraints → not available; use case-insensitive collation + composite uniqueness
- `LATERAL JOIN` → rewrite with subqueries
- `ILIKE` → MySQL `LIKE` is case-insensitive on `_ci` collations

## Eloquent-only things — REJECT in this project

- `Model::with('relation')` → no automatic eager loading; do `whereIn` + group manually
- `$post->author` magic relation → fetch via JOIN or a second query in the controller
- `Model::scopeActive()` → just write `where('status','active')` or a model method
- `whereHas` / `hasOne` / `belongsToMany` → translate to explicit JOINs

## Quick Audit Checklist

Before merging a query/migration:

- [ ] Bindings only — no string interpolation
- [ ] Explicit column list (no `SELECT *`)
- [ ] `EXPLAIN` shows index used, no `ALL` scan on hot path
- [ ] Composite index order matches `WHERE … ORDER BY` shape
- [ ] Writes are scoped (e.g. `WHERE id = ? AND author_id = ?`)
- [ ] Loops don't issue queries (no N+1)
- [ ] Pagination bounded (LIMIT + cursor for deep pages)
- [ ] Soft-delete predicate (`deleted_at IS NULL`) included if applicable
- [ ] Charset utf8mb4, FK types match
- [ ] Migration is reversible (`down()` works)
- [ ] Big-table `ALTER` uses online algorithm or batched approach
