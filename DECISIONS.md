# Decisions

| Date (Europe/Dublin) | Decision | Reason |
|----------------------|----------|--------|
| 2026-11-26 | Built directly by Claude (not via a Cursor prompt), same structure as `iworksie-health-check` | Cormac's request for this plugin. |
| 2026-11-26 | Code written to PHP 7.4 syntax (no union types, `match`, named args, nullsafe, `str_contains`, enums, readonly, constructor promotion); docblock types like Health Check | Plugin declares PHP 7.4+; shared hosts. Checked with PHPCompatibilityWP `7.4-` and real PHP 7.4 test runs. |
| 2026-11-26 | Serialized data is edited by a small parser (`SerializedWalker`) instead of unserialize → replace → serialize | Never instantiates objects (no `__wakeup`/`__destruct` gadget risk), keeps objects of classes that are not loaded intact, preserves private/protected property names and `C:` payloads byte-for-byte. The result is still checked with `unserialize(..., ['allowed_classes' => false])` before writing; unreadable data is skipped and reported. |
| 2026-11-26 | Only string values are replaced; array keys and property names are not | Replacing keys can merge or drop array entries. Same behaviour as Better Search Replace. |
| 2026-11-26 | Change domain = one case-insensitive single-pass regex over all variants (http, https, //, JSON-escaped, URL-encoded, www twin) with a boundary look-ahead | Single pass means a replacement is never replaced again (e.g. example.com → staging.example.com). The look-ahead stops old.com matching old.com.au / old.community. |
| 2026-11-26 | Preview is mandatory: Apply re-uses the stored config of the last preview (by preview id), not the form | "What you saw is what is applied" and no way to skip the preview. |
| 2026-11-26 | Requests reach the core job runner via a single-use token (`iwiesr_pending`, 15 min, same user) | Core `Jobs\Manager::ajax_start` runs `sanitize_text_field` over params, which would strip tags, `%xx` and repeated spaces from search strings. |
| 2026-11-26 | One job id (`search_replace`) for preview, apply and undo; prepare refuses while one is in progress, the screen offers Resume / Stop | Core resumes an existing running job on start; without this an interrupted Apply could be resumed by clicking Preview. |
| 2026-11-26 | Undo table `{base_prefix}iwiesr_backup` stores the original as LONGBLOB plus sha1 of the value written; undo restores only cells whose current value still matches that hash, and deletes each backup row once restored | Never overwrite later edits; a cancelled undo can be resumed without false conflicts. |
| 2026-11-26 | Last 5 runs keep undo data (`iwiesr_keep_runs`), pruned when a new Apply starts | Plan default; bounded storage. |
| 2026-11-26 | Tables need a primary key (or NOT NULL unique key) and are walked with keyset pagination on that key, `(k1,k2) > (v1,v2)` for composite keys | Undo needs a stable row address; OFFSET paging would skip rows once applied rows stop matching the LIKE pre-filter. |
| 2026-11-26 | SQL `LIKE` pre-filter on literal fragments; skipped for regex, and for case-insensitive searches on blob / `*_bin` columns | Big speed-up on large tables while staying correct (PHP does the exact match). |
| 2026-11-26 | Never touched: `users.user_pass`, `users.user_activation_key`, `posts.guid` (opt-in), key columns, `iwiesr_*` options / sitemeta, the backup table (filter `iwiesr_excluded_columns`) | Plan requirement; own rows hold the search text and job state. |
| 2026-11-26 | Multisite: super admins only (`manage_network_options`); table list = current site's tables, plus global tables on the main site | Users/usermeta are network-wide, so site admins must not be able to rewrite them. Network-admin site picker deferred. |
| 2026-11-26 | Very short searches (< 4 characters, plain mode) need an "I understand" tick | Guard rail from competitor reviews. |
| 2026-11-26 | Regex: pattern typed without delimiters, `~` escaped for the user, patterns that can match empty text are rejected, PCRE failures skip the value | Prevents "insert everywhere" and silent corruption on backtrack-limit errors. |
| 2026-11-26 | No WP-CLI command in 1.0 | `wp search-replace` already exists; can add `wp iworksie-sr` later if wanted. |
| 2026-11-26 | `wp_cache_flush()` after each Apply/Undo step that wrote | Rows are updated directly in SQL, so object caches would otherwise serve stale options/posts. |


## Known limitations

- If PHP is killed in the middle of an Apply step (after some rows are written but before the step's state is saved), the next step re-reads those rows. That is harmless when the replacement no longer matches the search (the usual case), but a replacement that contains the search text (e.g. `foo` → `foobar`) could be applied twice to those rows. Steps use at most 40% of `max_execution_time` (core budget), so this needs a hard crash; every write is still backed up, so Undo recovers it.
- Snippets for serialized values show the first matching string inside the data; the counts always cover every match.

## Candidates for core

- Let a job opt out of the `sanitize_text_field` pass in `Jobs\Manager::ajax_start` (would remove the token hand-off).
- `SearchReplace\Engine` can move into core when Migrate v3 needs it (per build plan).

## Before wordpress.org submission

- Banner/icon artwork (`.wordpress-org/banner-*.png`, `icon-*.png`). Screenshots 1–4 are real captures from a WordPress 6.5 / PHP 7.4 site.
