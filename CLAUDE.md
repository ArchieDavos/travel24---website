# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

TRAVEL24.me — a Polish-language travel blog. Plain PHP 7/8 + MySQL (PDO), no framework, no build step, no package manager (no composer.json/package.json). Every page is a single flat `.php` file in the repo root that mixes PHP logic with inline HTML/Tailwind (loaded via CDN `<script src="https://cdn.tailwindcss.com">`, not compiled). There is no test suite, linter, or CI config.

There is no local dev environment defined (no Docker, no `.env`). Development happens by editing PHP files directly and testing against the live/shared MySQL database credentials defined in `config.php`. Treat every edit as touching what may be a production file.

## Running / testing locally

There are no build, lint, or test commands — none are configured in this repo. To sanity-check a change:

```bash
php -l somefile.php          # syntax check a single file
php -S localhost:8000        # serve the site locally (needs network access to the MySQL host in db.php, or a local DB with the same schema)
```

Since `config.php` hardcodes a remote host (`localhost` on the production server, i.e. not reachable from a generic dev machine) there is effectively no way to run this stack locally without either tunneling to the real DB or standing up a MySQL instance with a matching schema (see below) and swapping credentials in `config.php` — don't commit swapped credentials.

## Architecture

**Every entry-point file is self-contained.** There's no router, no autoloader, no shared bootstrap file beyond manual `require_once`. The recurring skeleton at the top of every public page:

```php
define('APP_ACCESS', true);              // required or db.php/config.php hard-403
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';                   // opens $pdo; also pulls in config.php internally
if (file_exists('lang.php')) require_once 'lang.php';
```

`db.php` refuses to load unless `APP_ACCESS` is defined by the includer — this is the only access-control mechanism protecting it from direct browser requests (also blocked again in `robots.txt`). `upload.php` does *not* define `APP_ACCESS` before requiring `db.php`, so it currently 403s unconditionally — treat it as dead/legacy code, not a working upload path.

**Config**: all credentials/API keys/tokens live in one place, `config.php` (root, same `APP_ACCESS` guard as `db.php`, also blocked in `robots.txt`), as `define()` constants: `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS`, `MAILERLITE_TOKEN`, `ADMIN_USER`/`ADMIN_PASS_HASH`, `CRON_BACKUP_TOKEN`. `db.php` requires it and maps the DB constants to the local `$host`/`$dbname`/`$username`/`$password` vars it already used. Any file that needs a secret *before* `db.php` is loaded (`admin.php`, `adminrezerwa.php`, `cron_backup.php`, `subscribe.php`) does `define('APP_ACCESS', true); require_once __DIR__ . '/config.php';` as its first two statements — in `admin.php`/`adminrezerwa.php` this had to move earlier than the historical `define('APP_ACCESS', true)` position because the MailerLite-stats fetch at the top of the file needs the token before the rest of the bootstrap ran. If you add a new secret, put it in `config.php` as a constant rather than hardcoding it inline — don't reintroduce the old pattern of copy-pasting tokens into multiple files.

**Shared partials** (`header.php`, `footer.php`) are `include`d, not templated — they rely on variables (`$current_lang`, `$pdo`, etc.) already existing in the including file's scope. `header.php` also queries `pages` directly to build the nav menu.

**i18n**: five locales — `pl` (default/canonical), `en`, `it`, `es`, `de`. Two parallel translation systems coexist:
- `lang.php` — a `$ui_lang` dictionary keyed by string + lang, exposed via the `__($key)` helper (reads `$_SESSION['lang']`).
- Per-page `$ui[$current_lang][...]` arrays defined inline in files like `index.php` (duplicated, not reused from `lang.php`).
- DB content rows carry per-language columns (`title`, `title_en`, `title_it`, `title_es`, `title_de`, same pattern for `content`/`description`). Every page redefines its own local `get_translated($item, $field, $lang)` helper (falls back to the `pl` column when a translated column is empty) rather than sharing one from a common file — copy this pattern if you touch translated content, don't try to dedupe it across files without checking every callsite.

Language selection precedence (repeated per-page, not centralized): `?lang=` query param > `$_SESSION['lang']` > browser `Accept-Language` (index.php only) > `pl`.

**Database schema** (MySQL, no migration tool — schema changes are one-off scripts or inline `ALTER TABLE ... try/catch` guards executed on every `admin.php` load, see below). Core tables: `posts` (blog entries; `parent_id` links sub-posts/"stages" of a trip to a main post; `is_published`, `views`, `real_views`), `post_photos` (per-post gallery images), `pages` (static CMS pages like About/Privacy, keyed by `slug`), `albums` (photo galleries; `destination`, `is_published`), `album_photos`, `comments` (moderated, `is_approved`; **no working submission form exists in `post.php`** — only admin-side moderation is wired up), `media`. `admin.php` runs idempotent `ALTER TABLE ... ADD COLUMN` calls wrapped in `try/catch` near its top as an ad-hoc schema-migration mechanism — if you add a column, follow that pattern rather than assuming a migrations directory exists.

**Admin panel** (`admin.php`): single file, single hardcoded user (`admin` / bcrypt hash inline), session-based auth with login-attempt rate limiting, CSRF token per session checked on every POST. Not a REST API — one big procedural script that branches on `isset($_POST['add_post'])`, `isset($_POST['update_post'])`, `isset($_POST['delete_album'])`, etc., each block doing its own PDO calls and image handling (`resize_and_save_image()`), then falling through to render the whole dashboard HTML. Also proxies two external APIs: DeepL (`action=deepl_translate`, API key passed per-request from the admin UI, not stored) and OneSignal push (`send_onesignal_push()`).

**`adminrezerwa.php` is a spare/fallback copy of the admin panel** ("rezerwa" = Polish for "spare/backup", kept on disk as a just-in-case fallback copy of `admin.php` — it has nothing to do with trip/booking reservations despite the name looking similar). It's not live/routed anywhere and has already drifted from `admin.php` (different DeepL endpoint, missing publish-toggle in the quick-edit section). Don't edit it expecting it to be live, and don't assume it needs to be kept in sync with `admin.php` — check with the user before changing or deleting it.

**Uploads**: images go to `uploads/` (album photos) and `uploads/posts/` (post galleries), served directly as static files — there's no CDN/object storage layer. `admin.php`'s `resize_and_save_image()` handles EXIF-orientation correction and downscaling on upload.

**Backups**: `cron_backup.php` is a token-authenticated (`?token=...`, hardcoded secret) endpoint that dumps the whole DB to `backups/*.sql` via raw `SHOW CREATE TABLE`/`SELECT *` and keeps only the 2 most recent files, intended to be hit by a cron job or manually. `admin.php` also has a "download latest backup" action gated by session auth + CSRF.

## Security-sensitive things to know before touching this code

- **Secrets are hardcoded in `config.php`, not in env vars** — still committed to the repo in plaintext, just centralized instead of duplicated. Everything that isn't public reads from there: DB credentials, MailerLite API token, admin bcrypt password hash, cron backup token. (OneSignal app ID stays inline in `header.php`/`post.php` — it's meant to be public, a client-side push SDK key.) If a secret is ever rotated, `config.php` is now the only file to touch.
- Queries throughout use PDO prepared statements (`ATTR_EMULATE_PREPARES => false` is set deliberately in `db.php`) — keep using parameterized queries, don't string-concatenate user input into SQL, including in `admin.php`'s many POST-handling blocks.
- `admin.php`/`adminrezerwa.php` check CSRF tokens (`hash_equals`) on every POST except login and backup-download. Preserve this when adding new POST actions.
- If you touch anything under `admin.php`, `adminrezerwa.php`, `db.php`, `config.php`, `cron_backup.php`, or the secrets they hold, flag it explicitly — this is a live production site with real user data (comments, subscribers) and no staging environment.
