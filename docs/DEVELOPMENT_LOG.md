# SocialMedia Manager — development log

Newest entry first. Ids SM-001, SM-002, ... Each entry says what changed, why, how it was checked, the commits, and where it stands: **Dev** (Claude's working copy) and **App** (the owner's running copy). Tracker: https://claude.ai/artifact/Vq8Q6jpWRxQ645PNxv7PDp

---

## SM-001 — Tools, the Laravel skeleton and the data folder (14 Nov 2026)

**Tracker:** #2 (tools, done), #4 (project and folders).

**What changed**
- The owner installed PHP 8.5.12 (winget) and Composer 2.11.2 (`D:\Tools\ComposerSetup`). Claude switched on the PHP extensions Laravel needs (exif, ftp, fileinfo, gd, intl, pdo_sqlite, sodium, sqlite3, zip), raised the upload limit to 200 MB, and backed up `php.ini` as `php.ini.bak-20261114`.
- Laravel 13.42 project in `D:\Pravin\Moggi\SocialMedia_Manager\App`, Git branch `main`.
- Data lives outside the code: the git-ignored `.data-dir` file names the folder (`..\Data` for App). The settings file (`.env`), the database (`database.sqlite`) and Laravel's storage are there. Nothing from it can reach GitHub.
- `php artisan smm:doctor`: checks PHP, extensions, the data folder, the app key, database updates, encrypted-backup support and the publish mode.
- Live posting is allowed only in the folder named `App`; any other copy runs in dry run.
- `scripts\windows\setup.ps1`: first-time setup of a copy. Running it again keeps the existing app key.

**Why:** the plan's "local first" and "data security" decisions: the code is backed up on GitHub, your data never is.

**Checks**
- 11 tests pass (`php artisan test`), including: a `.data-dir` written by Windows PowerShell with a BOM still works; a data folder inside the code folder is refused, whatever the letter case; live mode is refused outside the App folder; the self-check fails when a database update is waiting.
- App set up with `-PublishMode live`: self-check all OK. A second run keeps the same app key. `git status --ignored` shows `.data-dir` ignored and no data files listed.

**Commits:** code `c95c021`; docs: this entry.

**Where:** Dev — not created yet (next step). App — set up, self-check all OK.
