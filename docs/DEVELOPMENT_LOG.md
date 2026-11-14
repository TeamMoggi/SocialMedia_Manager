# SocialMedia Manager — development log

Newest entry first. Ids SM-001, SM-002, ... Each entry says what changed, why, how it was checked, the commits, and where it stands: **Dev** (Claude's working copy) and **App** (the owner's running copy). Tracker: https://claude.ai/artifact/Vq8Q6jpWRxQ645PNxv7PDp

---

## SM-003 — Brands, starting with Moggies International (14 Nov 2026)

**Tracker:** #15 (app skeleton: brands).

**What changed**
- Every screen now works inside one **brand**. Moggies International is the first; the app opens straight into it (address `/moggies-international`).
- Another business can be added from the brand menu at the top left ("Add a brand"), for example Moggies Export Services. Each brand will have its own accounts, posts and numbers. Names must be different.
- "Brand settings" holds the brand's name and the time zone used for post times and reports (Asia/Kolkata by default).

**Why:** the owner may add accounts beyond Moggies later; building brands in from the start avoids reworking every screen.

**Checks:** 19 tests pass, including: the app opens on Moggies International; adding "Moggies Export Services" creates it with the address `moggies-export-services`, the India time zone, and the owner attached; a second brand with the same name is refused; a brand the owner isn't attached to answers "not found".

**Commits:** see the branch `feat/sm-003-brands`; docs: this entry.

**Where:** Dev — done. App — after the owner merges `feat/sm-002-panel`, then `feat/sm-003-brands`.

---

## SM-002 — The app's screens, this laptop only, no login (14 Nov 2026)

**Tracker:** #15 (app skeleton; brands follow in SM-003).

**What changed**
- Filament 5.11 (with Livewire 4.4) gives the app its screens at the app's home address. The header shows the Moggies International mark on a white plate with the name as live text and "SocialMedia Manager" under it; Moggies blue (#1b4c86) for buttons and links. A bell for in-app notifications.
- On the laptop, any request from another device (for example a phone on the same Wi-Fi) is refused, and the owner is signed in automatically, so there is no login screen. When the app moves online (Phase 4) the normal login takes over.
- `setup.ps1` now writes `.data-dir` before installing packages, so a fresh copy (a new laptop) needs only the code and this script. Found while creating the Dev copy: Composer's last step could not run without a data folder.
- Dev copy created: `D:\Pravin\Moggi\SocialMedia_Manager\Dev` (branch work, dry run) with its own `DevData` folder.

**Why:** "local first, no login for now" from the plan, with the brand rule (mark on a light plate, live-text name).

**Checks**
- 15 tests pass, including: opening the app from the laptop shows the dashboard signed in as the owner; a request from 192.168.1.20 gets "forbidden"; in hosted mode nobody is signed in automatically; the header shows the company name and the logo's alt text.
- Opened in Edge at desktop and phone width: dashboard, brand header, no login form, no script errors.
- Found and fixed while testing: Laravel moves its login check ahead of other steps, so the automatic sign-in had to sit right after the session starts.

**Commits:** `e989797` (setup fix), `688dd3f` (screens); docs: this entry.

**Where:** Dev — done, checked in the browser. App — after the owner merges `feat/sm-002-panel`.

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
