# iWorks.ie Search & Replace

Free WordPress database search and replace for [wordpress.org](https://wordpress.org/plugins/iworksie-search-replace/): row-by-row preview, one-click undo, a Change domain preset and serialized-safe editing. Built on [`iworks-tools-core`](https://github.com/littlegreenman70/iworks-tools-core).

## Requirements

- WordPress 6.5+
- PHP 7.4+ (the code is written to PHP 7.4 syntax; CI runs the tests on 7.4 and 8.3)
- Composer (development)

## How it works

| Piece | File |
|---|---|
| Matching (plain, regex, change-domain variants in one pass) | `src/Engine/Matcher.php`, `src/Engine/DomainPairs.php` |
| Serialized data: a small parser that rewrites `s:<len>:` without ever calling `unserialize()` on live data | `src/Engine/SerializedWalker.php` |
| One cell, with nested serialized values | `src/Engine/ValueReplacer.php` |
| Preview / apply / undo as one chunked core job (`search_replace`) | `src/Jobs/SearchReplaceJob.php` |
| Undo storage `{base_prefix}iwiesr_backup` (original value + hash of the value written) | `src/Database/BackupStore.php` |
| Run history (option `iwiesr_runs`, last 5 kept) | `src/Database/Runs.php` |

Flow: the form posts to `iwiesr_prepare`, which validates and parks the request (`iwiesr_pending`) behind a single-use token. The core job runner (`iwiesr_job_start|step|cancel`) receives only the token, because it sanitises its parameters as plain text, which would alter search strings. Apply always re-uses the exact config of the last preview.

Undo restores a cell only if its current value still hashes to what this plugin wrote; anything edited since is left alone and reported.

## Local development

```bash
composer install
ln -sfn "$(pwd)" /opt/homebrew/var/www/www.iworks.new/wp-content/plugins/iworksie-search-replace
wp plugin activate iworksie-search-replace --path=/opt/homebrew/var/www/www.iworks.new
```

```bash
composer lint
composer analyse
composer test:unit
bin/install-wp-tests.sh iworks_tests_sr root '' 127.0.0.1 latest
composer test:integration
composer build
```

## Filters

| Filter | Default |
|---|---|
| `iwiesr_keep_runs` | 5 runs keep undo data |
| `iwiesr_batch_size` | 100 rows per query |
| `iwiesr_preview_samples` | 200 changes listed in the preview |
| `iwiesr_excluded_columns` | `users.user_pass`, `users.user_activation_key`, `posts.guid` (unless chosen) |

## License

GPL-2.0-or-later
