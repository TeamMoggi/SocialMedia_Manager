# SocialMedia Manager — development log

Newest entry first. Ids SM-001, SM-002, ... Each entry says what changed, why, how it was checked, the commits, and where it stands: **Dev** (Claude's working copy) and **App** (the owner's running copy). Tracker: https://claude.ai/artifact/Vq8Q6jpWRxQ645PNxv7PDp

---

## SM-007 — Runs only when needed (14 Nov 2026)

**Tracker:** #16 (changed at the owner's request: "keeping the app running every time will hamper my system").

**Measured before the change (App, 14 Nov):** the always-running server used about 47 MB of memory; the every-minute job started PowerShell and PHP 60 times an hour (about 4 seconds of work and 66 MB each time).

**What changed**
- **Desktop shortcut "SocialMedia Manager"** starts the app (about 2 seconds) and opens it. The app **stops itself after 30 minutes without use**; while stopped it uses no memory or CPU. An open browser tab no longer checks for messages in the background, so it does not keep the app awake (new messages appear when a page is opened).
- **Daily jobs once a day** (account check and backup) at 09:00 through one Windows task, or as soon as the laptop is on after a missed 09:00. They no longer run every minute. Each job runs directly, so a late start still does all of them, and a backup problem does not stop the account check.
- The Windows tasks **Server** and **Tick** are removed by the new install; only **Daily** remains. It never wakes the laptop and has a 1-hour limit, so a long backup no longer delays anything else (this was a deferred item from the foundation review).
- The status card and `smm:doctor` now say when the **daily jobs** last ran (orange after 26 hours).
- For posting (next build plan), the plan is a one-time wake-up at each approved Instagram/LinkedIn post's time instead of a job every minute.

**Checks**
- 67 tests pass, including: a page visit records use, the `/up` health check does not; the app stops at exactly 30 minutes without use, not at 29; `smm:daily` run late (11:23 after a missed 09:00) still checks accounts and records the run, and keeps going without a backup password; the wording for minutes, hours and days; no job is scheduled every minute.
- On the Dev copy: start → the app and its watcher run; daily jobs → "Daily jobs ran less than a minute ago"; stop → nothing left running; with a 1-minute limit and only `/up` checks, the app stopped by itself after 83 seconds. All scripts parse in Windows PowerShell 5.1 and are plain ASCII.

**To do on App after merging:** update App, then run `install-tasks.ps1` (one Windows prompt): it removes Server and Tick, adds Daily, and puts the shortcut on the desktop.

**Commits:** see the branch `feat/sm-007-on-demand`; docs: this entry.

**Where:** Dev — done. App — after the owner merges `feat/sm-007-on-demand`.

---

## SM-006 — Daily encrypted backups and the USB reminder (14 Nov 2026)

**Tracker:** #25 (and #14: the USB copy is the owner's task).

**What changed**
- A **Backups** page (left menu) with three buttons: **Back up now**, **Set backup password**, and **I copied Backups to my USB drive today**. It shows the last backup, the folder, and plain warnings.
- Once a day (checked every hour, so a laptop that was off at night still gets its backup later) the app saves a zip of the data folder: the database (a consistent copy, even while the app runs), the settings file with the app key, and all photos and videos. Every file in the zip is locked with **your backup password (AES-256)**. The newest 14 backups are kept; older ones are removed, and nothing else in the folder is ever touched.
- You type the backup password in the app; it is stored encrypted and Claude never sees it. Without it nobody can open the backups.
- A **Backups** card on the dashboard and the Backups page warn when: no password is set, the last backup failed, there has been no backup for 26 hours, or the USB copy has not been confirmed for 7 days.
- `smm:doctor` warns when no backup password is set.

**Why:** your data lives only on the laptop; the plan keeps daily encrypted copies there and a weekly copy on your USB drive.

**Checks**
- 53 tests pass, including: the zip holds the database, settings file and media, and a file cannot be read without the password; the backed-up database passes SQLite's integrity check; with 16 backups plus other files, only the 2 oldest backups are deleted; no password → clear message, nothing written; a backup 2 hours old → skipped; a successful run records the file name and clears the old error; the USB reminder is due when never confirmed or 7+ days ago; the password must be 12+ characters and typed twice the same.
- Opened in Edge on the Dev copy: the dashboard shows the status and Backups cards; the Backups page shows the buttons and warnings with no script errors.

**To do on App after merging:** set the backup password (you), then **Back up now** once and check a zip appears in `D:\Pravin\Moggi\SocialMedia_Manager\Backups`.

**Commits:** see the branch `feat/sm-006-backups`; docs: this entry.

**Where:** Dev — done. App — after the owner merges the branches in order (`feat/sm-002-panel` … `feat/sm-006-backups`).

---

## SM-005 — Timed jobs, the status card, starting with Windows (14 Nov 2026)

**Tracker:** #16.

**What changed**
- **Update and start.bat** in the App folder: double-click it to bring in the changes you approved on GitHub, update the app and its database, restart it and open it in the browser. It lists the changes and asks before installing, and stops without touching anything if the App folder has local changes.
- Two Windows tasks (installed once from the App copy, one administrator prompt): **Server** starts the app at `http://127.0.0.1:8090` when Windows starts and keeps it running with no time limit; **Tick** runs the timed jobs every minute (posting at the set times later, the daily account check now). They run without a window and without storing your password, and they never wake the laptop.
- A **status card** on the dashboard: Live or Dry run, when the timed jobs last ran (orange if more than 3 minutes ago), and the data folder.
- `smm:doctor` now also says when the timed jobs last ran.
- Scripts: `start.ps1` (refuses if another program uses the port), `stop.ps1` (stops only this copy's server), `serve.ps1`, `tick.ps1`, `install-tasks.ps1`, `update.ps1`.

**Why:** posts must go out on time without opening anything, and updates must be one double-click.

**Checks**
- 40 tests pass, including: settings and encrypted secrets; the heartbeat records the time; the heartbeat is scheduled every minute; 2 minutes is fresh and 5 is stale; the card shows "Dry run: nothing is posted" and "Timed jobs ran 1 minute ago"; the self-check warns when timed jobs have not run.
- Scripts run on the Dev copy: start → the app answers on port 8091; start again → "already running"; tick → the self-check shows "Timed jobs ran less than a minute ago"; stop → the port is free with no PHP left running; another program on the port → "Port 8091 is used by another program"; update on a branch other than main → refused. All scripts parse in Windows PowerShell 5.1 and are plain ASCII.
- Found and fixed: Windows PowerShell 5.1 treated PHP's normal start-up message as an error and stopped the server; the scripts now let Windows handle the output.

**Still to check on App after merging:** install the tasks (one Windows prompt), confirm the Server task has no time limit, the app opens at `http://127.0.0.1:8090`, and the status card turns green within 2 minutes.

**Commits:** see the branch `feat/sm-005-startup`; docs: this entry.

**Where:** Dev — done. App — after the owner merges `feat/sm-002-panel` … `feat/sm-005-startup` in order.

---

## SM-004 — Encrypted store for account access (14 Nov 2026)

**Tracker:** #17.

**What changed**
- A **Connected accounts** screen per brand lists each Facebook Page, Instagram account and LinkedIn company page with its state: Connected, Access expires soon, Access expired (reconnect), or Problem (reconnect). Each can be **disconnected** (the app forgets its access; published posts stay online). Connecting accounts comes with the Facebook/Instagram and LinkedIn steps.
- Account access (the networks' tokens) is stored **encrypted** with the app key and never shown in lists, exports or logs.
- Every day at 09:00 India time the app checks each account. When access is about to expire (7 days) or has expired, a message appears under the bell, once per change.

**Why:** the tokens can post as the business; the plan says they are encrypted and that the app warns before access runs out.

**Checks:** 29 tests pass, including: the database never holds a token in plain text; tokens are left out of any list or export of an account; the daily check marks an account expiring 3 days ahead and sends exactly one message even when run twice; each brand sees only its own accounts; the six expiry cases (none, passed, 3 days, 30 days, a problem stays a problem, fine again after reconnecting).

**Commits:** see the branch `feat/sm-004-accounts`; docs: this entry.

**Where:** Dev — done. App — after the owner merges `feat/sm-002-panel`, `feat/sm-003-brands`, then `feat/sm-004-accounts`.

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
