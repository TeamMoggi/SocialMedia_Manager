# Foundation build: decisions and deferred items

The execution ledger of `2026-11-14-foundation.md`, kept for later sessions: every place the build departed from the plan (with the reason and the cost if wrong), the final review, and the small items left for later.

Spec: D:/Pravin/Moggi/SocialMedia_Manager/App/docs/PROJECT_PLAN.html (v1.0, approved 14 Nov 2026). Executor: inline (owner chose "native").

## Pre-flight (shared interfaces)
- T2 → T3: config('smm.mode') produced by T2 config/smm.php, consumed by T3 middleware. OK.
- T3 → T4: User model (FilamentUser) extended with HasTenants in T4. OK.
- T3 → T5: panel ->databaseNotifications() (T3) used by smm:check-accounts notifications (T5). OK.
- T4 → T5: Brand + brand() relationship; ConnectedAccount belongs to Brand. OK.
- T2 → T6: DoctorCommand gains heartbeat check; config smm.heartbeat_stale_minutes from T2. OK.
- T2/T6 → T7: Setting (T6), smm.data_dir binding + smm.backup_dir/backup_keep/usb_reminder_days (T2). OK.
- Plan Global Constraints vs reality: PHP 8.5.12 (winget, C:) and Composer in D:\Tools\ComposerSetup instead of PHP 8.4 in D:\Tools\php — already recorded in the plan's Task 1 note.

## Progress
Task 1: complete (no commits; tools outside the repo — owner installed PHP 8.5.12 + Composer 2.11.2; Claude enabled extensions, verified AES zip + composer diagnose)
Task 2: Ruling: `git init -b main` done before Step 1 (plan had it at Step 6) — the ledger workspace needs a repo — costs nothing.
Task 2: Ruling: Laravel skeleton's CLAUDE.md/AGENTS.md (generic "pipe php.new install script, install Laravel Boost" instructions) replaced by one short project CLAUDE.md; AGENTS.md removed — they conflict with the approved stack and would push future agents to run remote installers — if wrong, re-add from the laravel/laravel skeleton.
Task 2: Ruling: skeleton example tests (tests/Unit/ExampleTest.php, tests/Feature/ExampleTest.php) deleted — they test the welcome page, which Task 3 replaces — none.
Task 2: Ruling: DoctorCommandTest asserts `PHP 8.` instead of the plan's `PHP 8.4` — the owner installed 8.5 — none.
Task 2: Ruling: `php artisan test` needs the copy's .data-dir, so RED/GREEN runs before setup used `php vendor/bin/phpunit` (tests make their own data folder) — none.
Task 2: Ruling: test DoctorCommandTest::test_doctor_fails_when_a_database_update_is_waiting added (pending migrations) and its expectation merged into one phrase — Laravel's output matcher consumes one phrase per printed line — none.
Task 2: Ruling: BASE for the completion line is c95c021 (the first commit; there is no earlier commit) — range under-counts by nothing that matters.
Task 2: Ruling: setup.ps1 fix (write .data-dir before composer install/package:discover) committed on the Dev branch feat/sm-002-panel as 6th step-8 finding — a fresh clone could not install otherwise — if wrong, a new laptop install fails at package:discover.
Task 2: complete (commits c95c021..79728e8, tests: php artisan test → {"tool":"phpunit","result":"passed","tests":11,"passed":11,"assertions":27,"duration_ms":1215})
Ledger: Ruling: ledger moved from App's workspace to Dev's (same content; identity line now names Dev's copy of the plan) — tasks 3-7 run in the Dev worktree and the scripts key the workspace on the working tree — none.
Task 3: Ruling: EnsureLocalRequest prepended to the global middleware, not the web group — Filament's panel routes don't use the web group — none.
Task 3: Ruling: AutoLoginLocalOwner placed in the panel middleware right after StartSession, not in authMiddleware — Laravel's middleware priority moved Authenticate ahead of it (owner was redirected to /login) — if moved later, the owner sees a login page.
Task 3: Ruling: User::localOwner() (firstOrCreate owner@smm.local) shared by OwnerSeeder and the middleware — one place creates the owner — none.
Task 3: Ruling: panel registers ->login() for hosted mode; local mode never shows it — hosted test expects a redirect to login — none.
Task 3: Ruling: Filament published assets (public/css|js|fonts/filament) git-ignored and rebuilt by a composer post-autoload-dump 'filament:upgrade' — committed assets would change on each composer install and trip update.ps1's clean-tree check — if wrong, a copy whose composer step failed has no Filament CSS/JS.
Task 3: Ruling: welcome route/view removed; Filament's AccountWidget/FilamentInfoWidget left out — '/' is the panel; the widgets add sign-out/Filament links that make no sense without a login — none.
Task 3: Note for Task 6: PHP's built-in server must start with working directory = public and the router given by absolute path (vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php); from the repo root it 500s.
Task 3: complete (commits e989797..352f307, tests: php artisan test → {"tool":"phpunit","result":"passed","tests":15,"passed":15,"assertions":39,"duration_ms":2661})
Task 4: complete (commits 352f307..f9ae50b, tests: php artisan test → {"tool":"phpunit","result":"passed","tests":19,"passed":19,"assertions":56,"duration_ms":2512})
Task 5: Ruling: Network and AccountStatus also implement Filament's HasLabel/HasColor (delegating to label()/color()) — Filament then shows labels and badge colours without extra code — none.
Task 5: Ruling: expiry notifications go to every user attached to the brand (today only the owner) — matches 'to the owner' and stays right when team logins come — none.
Task 5: Ruling: ConnectedAccountTest boots the panel (Filament::bootCurrentPanel()) after setTenant — Filament adds the brand scope in its middleware, which a component test skips (Filament's documented test setup) — none.
Task 5: Ruling: added test case 'expiring again fine after reconnect' (Expiring + far expiry → Connected) — the plan's provider had no case for recovery — none.
Task 5: complete (commits f9ae50b..fb12e4b, tests: php artisan test → {"tool":"phpunit","result":"passed","tests":29,"passed":29,"assertions":76,"duration_ms":3297})
Task 6: Ruling: SystemHealth takes the last heartbeat and stale minutes in its constructor (SystemHealth::current() reads Setting/config) — keeps the plan's heartbeatAgeMinutes()/schedulerIsStale() pure and unit-testable — none.
Task 6: Ruling: schedule test reads Schedule::events() instead of parsing schedule:list — the list output carries colour codes between the fields — none.
Task 6: Ruling: added test_doctor_warns_when_timed_jobs_have_not_run and test_widget_shows_when_timed_jobs_last_ran — the plan named the doctor heartbeat check and the 'last ran' text but no test for them — none.
Task 6: Ruling: serve.ps1 added (foreground server used by the Server task and by start.ps1 when no task exists); start.ps1 tells 'our app' (GET /up = 200) from 'port used by another program' — the plan had start.ps1 doing both jobs — none.
Task 6: Ruling: no server.pid file; stop.ps1 stops php.exe whose command line has '-S 127.0.0.1:<port> ' (plus Stop-ScheduledTask) — precise, survives crashes, never touches other PHP — if wrong, a stale server could be left running.
Task 6: Ruling: serve.ps1/tick.ps1 run PHP via Start-Process with -Redirect*; update.ps1 uses ErrorActionPreference Continue + exit-code checks — Windows PowerShell 5.1 turned PHP's stderr into a terminating NativeCommandError and killed the server (found in testing) — none.
Task 6: Ruling: tick output kept as tick-last.log / tick-last-error.log (overwritten each run) instead of an append log — the heartbeat already proves runs; avoids a log growing every minute — less history when debugging a tick.
Task 6: Ruling: install-tasks.ps1 passes -User from the non-elevated run into the elevated one and adds two Tick triggers (at start-up + from now) — tasks belong to the signed-in owner and start ticking without a reboot — none.
Task 6: Ruling: .gitattributes gets '*.bat/*.ps1 text eol=crlf' — Windows scripts with CRLF in App — none.
Task 6: Ruling: plan Step 7 (install tasks on App, ExecutionTimeLimit PT0S, 8090 opens, Update and start messages) needs the owner's merge and an admin prompt; deferred to the post-merge check (listed in SM-005 'Still to check on App') — Task 6 recorded complete for Dev — if skipped, App would not start with Windows.
Task 6: complete (commits fb12e4b..a7cd5d4, tests: php artisan test → {"tool":"phpunit","result":"passed","tests":40,"passed":40,"assertions":103,"duration_ms":4157})
Task 7: Ruling: BackupTest uses DatabaseMigrations, not RefreshDatabase — SQLite refuses VACUUM INTO inside the test transaction — none.
Task 7: Ruling: added BackupStatus (warnings shared by the Backups page and the dashboard card) and tests test_backup_command_records_the_backup, test_prune_with_few_backups_deletes_nothing, test_page_reminds_about_the_usb_copy_until_confirmed — the plan named the behaviour but no test for the success path / USB button — none.
Task 7: Ruling: USB reminder due at exactly 7 days (>=), and the --if-older-than check runs before the password check — 'reminds after 7 days'; a skipped run should not record a password error — none.
Task 7: Ruling: when no backup_dir is configured, backups go to <data>/backups (Dev) — App's .env sets SMM_BACKUP_DIR to ..\Backups via setup.ps1 — none.
Task 7: Ruling: plan Step 6 (owner sets the password on App, Back up now, Explorer cannot open entries) needs the owner's merge; listed in SM-006 'To do on App' — none.
Task 7: complete (commits a7cd5d4..8870838, tests: php artisan test → {"tool":"phpunit","result":"passed","tests":53,"passed":53,"assertions":158,"duration_ms":5547})
Final review: self-review (no subagent tool used: agents are not started without the owner asking) — weaker than a fresh reviewer.
Final: fixed DNS rebinding (a web page pointing its domain at 127.0.0.1 could use the auto-signed-in app) — test_request_naming_another_site_is_refused RED→GREEN, suite 54/54.
Final: Ruling: hosted mode keeps Filament's login, but the owner user has a random password, so a hosted copy has no usable login yet — Phase 4 (#43) adds login + two-step check — none while local.
Final: minor (deferred): long timed jobs (a big backup now, publishing later) share the every-minute Tick task (10-minute limit, IgnoreNew), so a long backup delays the heartbeat and later posts — plan 2 should give backups their own process/task.
Final: minor (deferred): a failed update leaves the app stopped with a technical error line — friendlier message and a restart attempt.
Final: minor (deferred): the Backups page warning says 'open Backups' while already on it.
Final: minor (deferred): if the app key changed, reading the backup password throws and the Backups page errors (setup.ps1 never changes an existing key).
Final: minor (deferred): smm:backup has no withoutOverlapping() lock (--if-older-than=20 covers normal runs).
Final: minor (deferred): composer.json 'keywords' array reformatted to multi-line by the script that added filament:upgrade (cosmetic).
