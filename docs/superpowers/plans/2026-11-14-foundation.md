# SocialMedia_Manager Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Laravel 13 + Filament 5 app that runs only on the owner's laptop. It keeps its data outside the code folder, starts with Windows, runs timed jobs every minute, holds brands and encrypted account access, and makes daily encrypted backups. This is the base that publishing (plan 2) and analytics (plan 3) build on.

**Architecture:** One Laravel app in `App` (the owner's running copy, always `main`) and a git worktree `Dev` (Claude's copy, dry-run posting). Each copy finds its data folder through a git-ignored `.data-dir` file. The `.env`, SQLite database, Laravel storage and media all live there. Windows Task Scheduler runs PHP's built-in server on `127.0.0.1:8090` and `php artisan schedule:run` every minute. Filament provides the screens, with Brand as the tenant.

**Tech Stack:** PHP 8.5 (owner installed it with winget, ZTS x64), Composer 2.11, Laravel ^13.0, Filament ^5.0 (Livewire 4), SQLite, PHPUnit, PowerShell 5.1 scripts, Windows Task Scheduler.

**Spec:** `docs/PROJECT_PLAN.html` (v1.0, approved 14 Nov 2026). This plan covers spec tasks #2, #4, #15, #16, #17 and #25. Owner tasks (#3, #6, #7, #9, #11, #13) and the tracker (#5) are done outside this plan.

## Global Constraints

- PHP 8.5.12 at `C:\Users\pravi\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe` (on the user PATH). Composer 2.11.2 in `D:\Tools\ComposerSetup` (on the machine PATH), with Composer's home in `%APPDATA%\Composer`. The owner installed both on 14 Nov 2026; C: has had plenty of room since the clean-up. Shells opened before the install need these folders added to `PATH` by hand.
- `laravel/framework ^13.0`, `filament/filament ^5.0`. No Node.js, no custom Filament theme, no Vite build.
- Folders: `D:\Pravin\Moggi\SocialMedia_Manager\App` (repo, `main`), `...\Dev` (git worktree), `...\Data` (App's data), `...\DevData` (Dev's data), `...\Backups` (App's backups). Dev's backups go to `...\DevData\backups`.
- Never in Git: `.data-dir`, any `.env` with real values, `*.sqlite`, media, backups. `.env.example` holds keys only.
- The server listens on `127.0.0.1` only, port `8090` (App). Dev uses `8091` when Claude runs it by hand.
- `SMM_PUBLISH_MODE=live` is allowed only when the code folder's name is `App`. Dev is always `dry-run`.
- Store times in UTC; show them in the brand's timezone (default `Asia/Kolkata`).
- Brand text: "Moggies International" exactly, with the logo on a light plate and alt text "Moggies International logo". Panel name: "SocialMedia Manager".
- Development log ids `SM-001`, `SM-002`, ... in `docs/DEVELOPMENT_LOG.md`. Each task ends with a code commit and a `docs:` commit (log entry + `docs/HOW_TO_USE.html`). Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- One branch per task (`feat/sm-00N-<topic>`) in `Dev`. If the previous task's pull request isn't merged yet, base the branch on it and tell the owner the merge order. No `gh` CLI: give the owner `https://github.com/TeamMoggi/SocialMedia_Manager/pull/new/<branch>`.
- PowerShell scripts must run on Windows PowerShell 5.1 (no `&&`, `??` or ternary).

## Review Focus

1. A `.data-dir` written by PowerShell 5.1 starts with a UTF-8 BOM and ends with a newline. It must still resolve (Task 2, `test_bom_and_newline_are_ignored`).
2. Paths on Windows are case-insensitive. A data folder given as `d:\...\app\data` must still count as "inside the code folder" and be refused (Task 2, `test_path_inside_code_folder_rejected_case_insensitively`).
3. A Dev copy switched to live posting would use real accounts. Live mode outside the folder named `App` must be refused by `smm:doctor` and by `PublishGuard` (Task 2, `test_live_mode_refused_outside_app_folder`).
4. Task Scheduler stops tasks after 72 hours by default, which would silently kill the server. The Server task needs no time limit (Task 6, verification step 7).
5. Backup pruning must never delete anything that isn't one of its own backup files (lesson from 7 Nov 2026). Prune only names matching `^smm-backup-\d{8}-\d{4}\.zip$` (Task 7, `test_prune_keeps_newest_14_and_ignores_other_files`).

---

### Task 1: PHP and Composer — DONE 14 Nov 2026

The owner installed PHP 8.5.12 (winget) and Composer 2.11.2 (Composer-Setup, in `D:\Tools\ComposerSetup`). Claude backed up `php.ini` to `php.ini.bak-20261114` and enabled `exif, ftp, fileinfo, gd, intl, pdo_sqlite, sodium, sqlite3, zip` (curl, mbstring and openssl were already on). It also set `memory_limit = 512M`, `upload_max_filesize = 200M`, `post_max_size = 210M` and `date.timezone = UTC`. Checked: all 17 needed extensions load, PHP starts with no warnings, `ZipArchive` AES-256 → `bool(true)`, `composer diagnose` all OK, and `git ls-remote` on the new private repo works (empty). A `winget upgrade` of PHP may reset `php.ini`; `smm:doctor` (Task 2) catches missing extensions. The original steps follow for reference.

**Files:** none in the repo (tools only).

- [ ] **Step 1:** Download the latest PHP 8.4 NTS x64 zip from https://windows.php.net/download/, check its SHA256 against the page, and extract it to `D:\Tools\php`.
- [ ] **Step 2:** Run `D:\Tools\php\php.exe -v`. Expected: `PHP 8.4.`
- [ ] **Step 3:** Copy `php.ini-development` to `php.ini`. Set `extension_dir = "ext"`. Enable `curl, fileinfo, ftp, gd, intl, mbstring, openssl, pdo_sqlite, sqlite3, sodium, zip`. Set `date.timezone = UTC`, `memory_limit = 512M`, `upload_max_filesize = 200M`, `post_max_size = 210M`.
- [ ] **Step 4:** Run `D:\Tools\php\php.exe -m`. Expected: every extension from Step 3 is listed.
- [ ] **Step 5:** Run `D:\Tools\php\php.exe -r "var_dump(ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true));"`. Expected: `bool(true)`. If it's false, stop and report, because Task 7 depends on it.
- [ ] **Step 6:** Download `composer.phar` (latest 2.x) to `D:\Tools\composer`, check its SHA256 against https://getcomposer.org/download/, and add `composer.bat` containing `@"D:\Tools\php\php.exe" "%~dp0composer.phar" %*`. Set the user environment variables `COMPOSER_HOME` and `COMPOSER_CACHE_DIR` (values above), and add `D:\Tools\php;D:\Tools\composer` to the user PATH.
- [ ] **Step 7:** In a new shell, run `composer -V` and `composer config --global home`. Expected: `Composer version 2.` and `D:\Tools\composer\home`.

### Task 2: Laravel skeleton with the data folder outside the code

**Files:**
- Create (in a temp folder, then moved into `App`, which already holds `docs/`): Laravel 13 skeleton from `composer create-project laravel/laravel:^13.0`. Delete the `.env` and `database/database.sqlite` it creates.
- Create: `app/Support/DataDir.php`, `app/Support/PublishGuard.php`, `config/smm.php`, `app/Console/Commands/DoctorCommand.php`, `tests/bootstrap.php`, `scripts/windows/setup.ps1`, `docs/DEVELOPMENT_LOG.md`, `docs/HOW_TO_USE.html`
- Modify: `bootstrap/app.php`, `phpunit.xml` (bootstrap → `tests/bootstrap.php`; keep sqlite `:memory:`; add a fixed test-only `APP_KEY`), `.gitignore` (add `.data-dir`), `.env.example`, `database/seeders/DatabaseSeeder.php` (remove the skeleton's `test@example.com` user so it never lands in real data)
- Test: `tests/Unit/DataDirTest.php`, `tests/Unit/PublishGuardTest.php`, `tests/Feature/DoctorCommandTest.php`

**Interfaces:**
- Produces: `DataDir::resolve(string $basePath): string`. The `SMM_DATA_DIR` environment variable wins, otherwise `<basePath>/.data-dir` is read. Strips the BOM and whitespace. The path must be absolute, must exist, and must not be inside `$basePath` (case-insensitive). Throws `RuntimeException` with messages starting `SocialMedia Manager data folder:`.
- Produces: `PublishGuard::allowsLive(string $basePath): bool` (true only when `basename($basePath) === 'App'`) and `PublishGuard::effectiveMode(string $configured, string $basePath): string` (returns `dry-run` when live isn't allowed).
- Produces: `config('smm.*')`: `mode` (`SMM_MODE`, default `local`), `publish_mode` (`SMM_PUBLISH_MODE`, default `dry-run`), `port` (`SMM_PORT`, 8090), `backup_dir` (`SMM_BACKUP_DIR`), `backup_keep` 14, `usb_reminder_days` 7, `heartbeat_stale_minutes` 3, `token_expiry_warning_days` 7.
- Produces: container binding `smm.data_dir` (string). `bootstrap/app.php` calls `useEnvironmentPath($dataDir)` and `useStoragePath($dataDir.'/storage')`.
- Produces: `php artisan smm:doctor`. Prints one line per check with `OK`, `WARN` or `FAIL`; exit code 1 if any check fails.

- [ ] **Step 1: Write the failing tests** `DataDirTest`: `test_reads_path_from_data_dir_file`, `test_env_var_overrides_file`, `test_missing_file_and_env_throws` (message contains `.data-dir`), `test_relative_path_rejected`, `test_missing_folder_rejected`, `test_path_inside_code_folder_rejected_case_insensitively`, `test_bom_and_newline_are_ignored` (file content `"\xEF\xBB\xBF".$path."\r\n"` resolves to `$path`). `PublishGuardTest`: `test_live_allowed_only_in_folder_named_App` (`...\App` → true, `...\Dev` → false), `test_live_mode_refused_outside_app_folder` (`effectiveMode('live', '...\Dev') === 'dry-run'`). `DoctorCommandTest`: `test_doctor_passes_in_test_environment` (exit 0; output contains `PHP 8.4`, `Data folder`, `Publish mode: dry-run`).
- [ ] **Step 2:** Run `php artisan test`. Expected: the new tests FAIL (classes missing).
- [ ] **Step 3:** Implement `DataDir`, `PublishGuard`, `config/smm.php`, `DoctorCommand` and the `bootstrap/app.php` changes. Doctor checks: PHP ≥ 8.3, the Task 1 extensions, data folder writable, `APP_KEY` set, database reachable, no pending migrations, AES zip support, publish-mode guard (FAIL with `Live posting is only allowed in the App folder`). `tests/bootstrap.php` sets `SMM_DATA_DIR` to a fresh `sys_get_temp_dir().'/smm-test-data'` with a `storage` tree.
- [ ] **Step 4:** Run `php artisan test`. Expected: all PASS.
- [ ] **Step 5:** Write `scripts/windows/setup.ps1 -DataDir <abs> [-PublishMode live|dry-run] [-BackupDir <abs>] [-Port 8090]`. It creates `DataDir\{storage\app,storage\framework\cache,storage\framework\sessions,storage\framework\views,storage\logs,media,run}`, writes `.data-dir`, copies `.env.example` to `DataDir\.env` if missing, sets `SMM_PUBLISH_MODE`, `SMM_BACKUP_DIR`, `SMM_PORT` and `DB_DATABASE=<DataDir>\database.sqlite`, creates the empty database, runs `key:generate --force`, `migrate --force` and `db:seed --force`, then `smm:doctor`.
- [ ] **Step 6:** In `App`, run `git init -b main`, then `setup.ps1 -DataDir D:\Pravin\Moggi\SocialMedia_Manager\Data -PublishMode live -BackupDir D:\Pravin\Moggi\SocialMedia_Manager\Backups`. Expected: doctor shows no FAIL. Run `git status --ignored`. Expected: `.data-dir` is ignored and no data file shows as untracked.
- [ ] **Step 7: Commit** `chore: Laravel 13 skeleton with the data folder outside the code`. Then the `docs:` commit: `DEVELOPMENT_LOG.md` SM-001 (tools + skeleton) and the first `HOW_TO_USE.html` (what it is, the folders, the brand header). Use the plain style of the moggies.in guide.
- [ ] **Step 8:** Run `git worktree add ..\Dev -b feat/sm-002-panel`, then in `Dev` run `composer install` (each copy has its own `vendor`) and `setup.ps1 -DataDir D:\Pravin\Moggi\SocialMedia_Manager\DevData -PublishMode dry-run -Port 8091`. Expected: doctor shows `Publish mode: dry-run`, no FAIL.
- [ ] **Step 9:** Add the remote `https://github.com/TeamMoggi/SocialMedia_Manager.git` (created by the owner on 14 Nov 2026, empty, reachable) and push `main`.

### Task 3: Filament panel, this-laptop-only access, owner without login, branding

**Files:**
- Create: `app/Providers/Filament/AppPanelProvider.php` (panel id `app`, path `''`), `app/Http/Middleware/EnsureLocalRequest.php`, `app/Http/Middleware/AutoLoginLocalOwner.php`, `database/seeders/OwnerSeeder.php`, `resources/views/filament/brand.blade.php`, `public/brand/logo.png`, `public/brand/favicon.png` (from `docs/assets`)
- Modify: `composer.json` (add `filament/filament:^5.0`), `app/Models/User.php` (implements `FilamentUser`), `database/seeders/DatabaseSeeder.php`, `bootstrap/app.php` (prepend `EnsureLocalRequest` to the web group)
- Test: `tests/Feature/LocalAccessTest.php`

**Interfaces:**
- Consumes: `config('smm.mode')`.
- Produces: `OwnerSeeder` creates the user `owner@smm.local` (name `Owner`) with `firstOrCreate`. `AutoLoginLocalOwner` logs that user in when `smm.mode === 'local'` and nobody is logged in. `EnsureLocalRequest` returns 403 when `smm.mode === 'local'` and the client IP isn't `127.0.0.1` or `::1`.
- Produces: the panel brand view renders the logo on a white rounded plate (`alt="Moggies International logo"`), live text `Moggies International`, and small text `SocialMedia Manager`. Primary colour `#1b4c86`. The panel has `->databaseNotifications()` enabled.

- [ ] **Step 1: Write the failing tests:** `test_panel_opens_without_login_from_loopback` (GET `/` with `REMOTE_ADDR=127.0.0.1` follows to 200, and `auth()->user()->email === 'owner@smm.local'`), `test_request_from_other_device_is_refused` (`REMOTE_ADDR=192.168.1.20` → 403), `test_hosted_mode_does_not_auto_login` (`smm.mode=hosted` → not 200 and the visitor is a guest), `test_brand_header_shows_company_name_and_logo_alt` (response contains `Moggies International` and `alt="Moggies International logo"`).
- [ ] **Step 2:** Run `php artisan test --filter=LocalAccessTest`. Expected: FAIL.
- [ ] **Step 3:** Install Filament 5, create the panel, and implement the middleware, seeder and brand view as in Interfaces. Hosted mode keeps Filament's normal `Authenticate` (login comes in Phase 4).
- [ ] **Step 4:** Run `php artisan test`. Expected: all PASS.
- [ ] **Step 5:** Run `php artisan serve --host=127.0.0.1 --port=8091` in Dev and open http://127.0.0.1:8091 with Python Playwright (Edge, from the scratchpad). Expected: the dashboard with the brand header, and no login screen. Close the test browser.
- [ ] **Step 6: Commit** `feat: Filament panel that opens only on this laptop` and the `docs:` commit (SM-002, guide: how to open the app).

### Task 4: Brands

**Files:**
- Create: `database/migrations/*_create_brands_table.php` (`name`, `slug` unique, `timezone` default `Asia/Kolkata`), `*_create_brand_user_table.php`, `app/Models/Brand.php`, `app/Filament/Pages/Tenancy/RegisterBrand.php`, `app/Filament/Pages/Tenancy/EditBrand.php`, `database/seeders/BrandSeeder.php`
- Modify: `app/Models/User.php` (implements `HasTenants`), `AppPanelProvider.php` (`->tenant(Brand::class, slugAttribute: 'slug')`, registration, profile), `DatabaseSeeder.php`
- Test: `tests/Feature/BrandTest.php`

**Interfaces:**
- Produces: `Brand` with `users(): BelongsToMany`. `User::brands(): BelongsToMany`, `getTenants(Panel $panel): Collection`, `canAccessTenant(Model $tenant): bool`. `BrandSeeder` creates `Moggies International` / `moggies-international` and attaches the owner. Later brand-owned models use a `brand()` BelongsTo relationship, so Filament scopes them automatically.

- [ ] **Step 1: Write the failing tests:** `test_owner_lands_on_moggies_international` (GET `/` redirects to `/moggies-international`, which shows `Moggies International`), `test_owner_can_add_a_second_brand` (RegisterBrand with name `Moggies Export Services` → slug `moggies-export-services`, owner attached), `test_brand_name_must_be_unique`, `test_owner_cannot_open_a_brand_they_are_not_attached_to` (→ 404).
- [ ] **Step 2:** Run `php artisan test --filter=BrandTest`. Expected: FAIL.
- [ ] **Step 3:** Implement as in Interfaces. The slug is generated from the name.
- [ ] **Step 4:** Run `php artisan test`. Expected: all PASS.
- [ ] **Step 5: Commit** `feat: brands, starting with Moggies International` and the `docs:` commit (SM-003, guide: adding a brand).

### Task 5: Connected accounts store (encrypted)

**Files:**
- Create: `app/Enums/Network.php` (`FacebookPage='facebook_page'`, `Instagram='instagram'`, `LinkedInOrganization='linkedin_org'`, with `label(): string`), `app/Enums/AccountStatus.php` (`Connected`, `Expiring`, `Expired`, `Error`, with `color(): string`), `database/migrations/*_create_connected_accounts_table.php`, `app/Models/ConnectedAccount.php`, `app/Domain/Accounts/AccountHealth.php`, `app/Console/Commands/CheckAccountsCommand.php`, `app/Filament/Resources/ConnectedAccounts/ConnectedAccountResource.php` (+ List page)
- Modify: `app/Models/Brand.php` (`connectedAccounts(): HasMany`), `routes/console.php` (schedule)
- Test: `tests/Feature/ConnectedAccountTest.php`, `tests/Unit/AccountHealthTest.php`

**Interfaces:**
- Produces: table `connected_accounts`: `brand_id` (FK, cascade), `network`, `external_id`, `name`, `access_token` text, `refresh_token` text nullable, `token_expires_at` nullable, `status` default `connected`, `last_error` nullable, `meta` json nullable, unique (`brand_id`, `network`, `external_id`). The model casts both tokens with `encrypted`, hides them from arrays, and has `brand(): BelongsTo`.
- Produces: `AccountHealth::evaluate(?CarbonImmutable $expiresAt, AccountStatus $current, CarbonImmutable $now, int $warningDays): AccountStatus`. `Error` stays `Error`; no expiry → `Connected`; `expiresAt <= now` → `Expired`; within `warningDays` → `Expiring`.
- Produces: `php artisan smm:check-accounts` updates every account's status and sends one Filament database notification to the owner when an account changes to Expiring or Expired. Scheduled daily at 09:00 Asia/Kolkata.
- Produces: the read-only Connected accounts list (network, name, status badge, expiry shown in the brand's timezone) with a confirmed "Disconnect" action. Connecting comes in plan 2.

- [ ] **Step 1: Write the failing tests:** `test_tokens_are_encrypted_at_rest` (raw DB value doesn't contain `secret-token-123`; the model returns it), `test_tokens_are_hidden_from_arrays`, an `AccountHealthTest` data provider (null → Connected; yesterday → Expired; in 3 days → Expiring; in 30 days → Connected; Error with expiry in 30 days → Error), `test_check_accounts_notifies_once_when_expiring` (run twice → 1 notification), `test_accounts_list_only_shows_current_brand`.
- [ ] **Step 2:** Run `php artisan test`. Expected: the new tests FAIL.
- [ ] **Step 3:** Implement as in Interfaces.
- [ ] **Step 4:** Run `php artisan test`. Expected: all PASS.
- [ ] **Step 5: Commit** `feat: encrypted store for connected accounts` and the `docs:` commit (SM-004).

### Task 6: Timed jobs, system status and Windows start-up

**Files:**
- Create: `database/migrations/*_create_settings_table.php` (`key` string primary, `value` text nullable), `app/Models/Setting.php`, `app/Console/Commands/HeartbeatCommand.php`, `app/Domain/System/SystemHealth.php`, `app/Filament/Widgets/SystemStatusWidget.php`, `scripts/windows/start.ps1`, `stop.ps1`, `tick.ps1`, `install-tasks.ps1`, `update.ps1`, `Update and start.bat` (repo root)
- Modify: `routes/console.php`, `DoctorCommand.php` (heartbeat check, WARN only)
- Test: `tests/Feature/SettingTest.php`, `tests/Feature/HeartbeatTest.php`, `tests/Unit/SystemHealthTest.php`, `tests/Feature/SystemStatusWidgetTest.php`

**Interfaces:**
- Produces: `Setting::get(string $key, mixed $default = null): mixed`, `Setting::put(string $key, mixed $value): void`, `Setting::getSecret(string $key): ?string`, `Setting::putSecret(string $key, string $value): void` (Crypt-encrypted).
- Produces: `smm:heartbeat` writes `heartbeat_at` (ISO 8601 UTC), scheduled every minute. `SystemHealth::heartbeatAgeMinutes(CarbonImmutable $now): ?int`, `SystemHealth::schedulerIsStale(CarbonImmutable $now): bool` (null or older than `smm.heartbeat_stale_minutes`).
- Produces: `SystemStatusWidget` on the dashboard shows the publish mode (`Dry run: nothing is posted` or `Live`), when timed jobs last ran, and the data folder.
- Produces: Task Scheduler folder `\SocialMediaManager\` with tasks `Server` (at startup: `start.ps1`, keeps running) and `Tick` (at startup, repeating every 1 minute indefinitely: `tick.ps1` → `php artisan schedule:run`, MultipleInstances IgnoreNew, 10-minute limit). Both use LogonType S4U ("run whether logged on or not, don't store password"), so no windows flash. They run on battery. No WakeToRun. Registering them needs one admin approval.
- Produces: `start.ps1` refuses with `Port 8090 is used by another program` if the port is taken, writes `<data>\run\server.pid`, and has `-Open` to open the browser. `update.ps1` (called by `Update and start.bat`): stops unless the branch is `main` and the tree is clean; fetches; lists incoming commits; asks Y/N; then `git pull --ff-only`, `composer install --no-dev --no-interaction`, `php artisan migrate --force`, `php artisan optimize:clear`, restarts the Server task, runs `smm:doctor`, opens the app. It logs to `<data>\logs\update.log`.

- [ ] **Step 1: Write the failing tests:** `test_setting_round_trip`, `test_secret_is_encrypted_at_rest`, `test_heartbeat_records_now`, a `SystemHealthTest` provider (null → stale; 2 min → fresh; 5 min → stale), `test_widget_shows_dry_run_badge`, `test_schedule_runs_heartbeat_every_minute` (`schedule:list` output contains `smm:heartbeat` and `* * * * *`).
- [ ] **Step 2:** Run `php artisan test`. Expected: the new tests FAIL.
- [ ] **Step 3:** Implement the PHP side as in Interfaces.
- [ ] **Step 4:** Run `php artisan test`. Expected: all PASS.
- [ ] **Step 5:** Write the PowerShell scripts and `Update and start.bat` as in Interfaces.
- [ ] **Step 6: Commit** `feat: timed jobs, system status and Windows start-up` and the `docs:` commit (SM-005, guide: starting, updating, what the status card means).
- [ ] **Step 7: Verify on App after the owner merges** (the owner approves the admin prompt). Run `install-tasks.ps1`, then `Start-ScheduledTask -TaskPath \SocialMediaManager\ -TaskName Server`. Check `(Get-ScheduledTask -TaskPath \SocialMediaManager\ -TaskName Server).Settings.ExecutionTimeLimit`. Expected: `PT0S`. After 2 minutes, `php artisan smm:doctor` shows timed jobs ran 0–1 minutes ago, and http://127.0.0.1:8090 opens. Running `Update and start.bat` with nothing new prints `Already up to date`. Running it with a changed file prints `Your App folder has changes` and stops.

### Task 7: Daily encrypted backups and the USB reminder

**Files:**
- Create: `app/Domain/Backup/BackupMaker.php`, `app/Domain/Backup/BackupPruner.php`, `app/Domain/Backup/UsbReminder.php`, `app/Console/Commands/BackupCommand.php`, `app/Filament/Pages/Backups.php`, `app/Filament/Widgets/BackupStatusWidget.php`
- Modify: `routes/console.php`, `DoctorCommand.php` (backup password set: WARN only)
- Test: `tests/Feature/BackupTest.php`, `tests/Unit/BackupPrunerTest.php`, `tests/Unit/UsbReminderTest.php`, `tests/Feature/BackupsPageTest.php`

**Interfaces:**
- Consumes: `Setting` (Task 6), `smm.data_dir`, `config('smm.backup_dir')`, `config('smm.backup_keep')`.
- Produces: `BackupMaker::make(string $targetDir, string $password, CarbonImmutable $now): string`. It returns the zip path `smm-backup-YYYYMMDD-HHmm.zip` (local time Asia/Kolkata), creating the target folder if needed. It copies the database with `VACUUM INTO` a temp file, then zips `database.sqlite`, `.env` and `media/**`, each entry `EM_AES_256` with the password.
- Produces: `BackupPruner::prune(string $dir, int $keep): array` (deleted file names). It only considers names matching `^smm-backup-\d{8}-\d{4}\.zip$` and keeps the newest `$keep`.
- Produces: `smm:backup {--if-older-than= : hours}`. It skips (printing `Skipped`) when `last_backup_at` is newer than that. It fails with exit code 1 and `Set a backup password in the app first` when the `backup_password` secret is missing, and records `last_backup_error`. On success it sets `last_backup_at` and `last_backup_file` and clears the error. Scheduled hourly with `--if-older-than=20`.
- Produces: `UsbReminder::isDue(?CarbonImmutable $confirmedAt, CarbonImmutable $now, int $days): bool`. The Backups page sets the password (entered twice, at least 12 characters, stored with `putSecret`; the owner types it, Claude never sees it), shows the last backup, has "Back up now", and has "I copied the Backups folder to my USB drive today" (sets `usb_copy_confirmed_at`). `BackupStatusWidget` warns when there's been no backup in 26 hours, no password is set, or the USB copy is due.

- [ ] **Step 1: Write the failing tests:** `test_backup_zip_holds_database_env_and_media_encrypted` (reading an entry without the password fails; with it, the content matches), `test_backup_database_passes_integrity_check` (`PRAGMA integrity_check` = `ok`, and the `brands` table exists), `test_prune_keeps_newest_14_and_ignores_other_files` (16 backups + `notes.txt` + `smm-backup-old.zip` → only the 2 oldest backups are deleted), `test_backup_fails_clearly_without_password`, `test_if_older_than_skips_recent_backup`, a `UsbReminderTest` provider (null → due; 3 days → not due; 8 days → due), `test_password_must_match_and_be_12_characters`.
- [ ] **Step 2:** Run `php artisan test`. Expected: the new tests FAIL.
- [ ] **Step 3:** Implement as in Interfaces.
- [ ] **Step 4:** Run `php artisan test`. Expected: all PASS.
- [ ] **Step 5: Commit** `feat: daily encrypted backups and the USB reminder` and the `docs:` commit (SM-006, guide: setting the backup password, copying `Backups` to the USB drive, and that opening a backup needs the password plus 7-Zip or Claude).
- [ ] **Step 6: Verify on App after merge:** the owner sets the backup password in the app. Then `php artisan smm:backup` creates one zip in `D:\Pravin\Moggi\SocialMedia_Manager\Backups`, and Explorer can't open its entries without the password.
