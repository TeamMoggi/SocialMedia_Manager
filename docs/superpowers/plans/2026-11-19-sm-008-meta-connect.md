# SM-008 Connect, Hostinger folder, Test send: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The app holds a permanent Meta key and uses it to connect the Facebook Page and Instagram. It has a locked Hostinger folder for Instagram's downloads and a "Test send" that proves every step to both networks without publishing. Then, from App only, it makes one real, visible Facebook test post and deletes it within minutes.

**Architecture:**
- **Meta calls:** every Graph API call goes through one client, `MetaGraph`. It adds the API version and `appsecret_proof`, and turns Meta's errors into plain messages. In dry-run it refuses any publishing call before a request is sent, so every later publish path inherits the Dev guard.
- **Accounts:** `MetaConnector` checks the key (`debug_token`), then reads `me/accounts` and saves two `ConnectedAccount` rows.
- **Hostinger folder:** `RelayFolder` wraps a Laravel FTP disk plus the folder's public address.
- **Test send:** `Rehearsal` drives both networks up to, but never including, publishing.
- **Screens:** Filament pages hold the settings. Secrets go through `Setting::putSecret` and are never shown back.

**Tech Stack:** Laravel 13, Filament 5, PHP 8.5 (Windows), SQLite, Laravel HTTP client (`Http::fake` in tests), `league/flysystem-ftp` ^3.0 (new; Laravel's `ftp` driver), Meta Graph API **v27.0** (newest on 19 Nov 2026, introduced 4 Nov 2026), Windows PowerShell 5.1 for the wake-up trial.

**Spec:** `docs/superpowers/specs/2026-11-18-phase1-posting-design.md`, approved by the owner 19 Nov 2026. This plan covers "Part 1" of its §7, plus the §5 working rules and the part-1 lines of §9/§10.

## Global Constraints

- **Versions and settings:**
  - Graph base `https://graph.facebook.com/v27.0/`. Video uploads go to `https://graph-video.facebook.com/v27.0/`. Keep both in `config('smm.meta')`, together with `app_id` = `2181731786035384`.
- **Secrets and Dev safety:**
  - The App Secret, the Meta key and the FTP password are stored only with `Setting::putSecret`.
  - They are shown back only as "Saved on <date>", and never written to logs, exceptions, notifications, `toArray()` or command output. Error messages must not include request URLs, because those carry `access_token`.
  - Dev is always dry-run: `PublishGuard::effectiveMode(config('smm.publish_mode'), base_path())`. App's `.env` is `live`; DevData's is `dry-run`.
  - "Test send" may run in Dev and App. It **never** calls `media_publish` or posts to `/feed`, in any mode.
  - Nothing in the app reads or writes `D:\Pravin\Moggi\Markting Social Media` except read-only listing and reading files (spec §4).
- **Hostinger folder:**
  - Uploaded names are random: `bin2hex(random_bytes(16))` + extension. Only `jpg`, `jpeg`, `png`, `webp`, `mp4`, `mov` and `txt` may be uploaded.
  - Every file the app uploads is deleted by the same operation, on success and on failure.
- **Plain words:** user-facing text is plain English for the owner, with no API jargon. Times show in the brand's timezone (Asia/Kolkata).
- **Meta permissions:**
  - Required (missing ones block connecting): `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `instagram_basic`, `instagram_content_publish`.
  - Wanted (missing ones are a warning): `read_insights`, `instagram_manage_insights`, `pages_manage_engagement`, `instagram_manage_comments`, `pages_read_user_content`, `business_management`.
  - Anything beyond required + wanted + `public_profile` is reported as "more access than needed" (a warning).
- **Windows scripts:** Windows PowerShell 5.1, plain ASCII. Run PHP via `Start-Process` with redirection, never with native stderr redirection.
- **Commits and records:**
  - Work on `feat/sm-008-meta-connect` in `Dev`. Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
  - Tests: `php artisan test` in Dev (use `php vendor/bin/phpunit` if `.data-dir` is missing).
- **Browsers:** headless Edge checks use a fresh temporary profile and are closed, with the profile deleted, afterwards.

## Review Focus

1. **A pasted key or secret with spaces or line breaks.** Copying from Meta's dialog often adds them. They are trimmed before saving. → test in Task 2.
2. **The laptop is offline at the 09:00 daily check.** The Meta accounts keep their status, and no "make a new key" warning is sent. → test in Task 3.
3. **Hostinger's cache still serves a file after it was deleted.** The FTP listing decides "gone". A website copy that is still visible is a note, not a failure. → test in Task 4.
4. **A Test send fails half-way** (container ERROR, timeout, or a Facebook read fails after an upload). Files already uploaded to the Hostinger folder are still deleted, and so are Facebook's unpublished uploads. → test in Task 5.
5. **Owner file names with spaces, "!", "&" or non-English letters** (for example `Moggies International your trusted Partener !.wav`, `9 Product ownsr`). They are listed and uploaded correctly, because the upload uses a random name. → test in Task 5.

---

### Task 1: Meta client with the Dev publishing guard

**Files:**
- Create: `app/Domain/Meta/MetaGraph.php`, `app/Domain/Meta/MetaError.php`, `app/Domain/Meta/PublishBlocked.php`
- Modify: `config/smm.php` (add `'meta' => ['graph_version' => 'v27.0', 'graph_url' => 'https://graph.facebook.com', 'video_url' => 'https://graph-video.facebook.com', 'app_id' => '2181731786035384']`), `app/Providers/AppServiceProvider.php` (bind `MetaGraph::class` to `fn () => MetaGraph::make()`)
- Test: `tests/Unit/MetaGraphTest.php` (extends `Tests\TestCase`, uses `Http::fake`)

**Interfaces:**
- Produces:
  - `MetaGraph::__construct(?string $appSecret, string $mode)`, where `$mode` is `'live'` or `'dry-run'`. `MetaGraph::make(): self` reads `Setting::getSecret('meta.app_secret')` and `PublishGuard::effectiveMode(config('smm.publish_mode'), base_path())`. `mode(): string`.
  - Methods:
    - `get(string $path, array $query, string $token): array`
    - `post(string $path, array $params, string $token): array`
    - `upload(string $path, array $params, string $filePath, string $token, bool $video = false): array` (multipart field `source`; uses the video host when `$video`)
    - `delete(string $path, string $token): array`
  - `static isPublishing(string $method, string $path, array $params): bool`. It is true for:
    - POST to a path ending `/feed` or `/media_publish`
    - POST to `/photos` or `/videos` unless `published` is `false`, `'false'`, `0` or `'0'`
  - `MetaError extends RuntimeException`: `code(): int`, `subcode(): ?int`, `transient(): bool`, `plain(): string`.
  - `PublishBlocked extends RuntimeException`. Its message: "Dev is a practice copy: it never publishes. Real posts go out only from the App copy."

- [ ] **Step 1: Write the failing tests**
  - `test_adds_version_token_and_appsecret_proof`: `get('me/accounts', ['fields' => 'id'], 'tok')` with secret `'sec'`.
    - The URL starts with `https://graph.facebook.com/v27.0/me/accounts`.
    - The query has `access_token=tok`, and `appsecret_proof === hash_hmac('sha256', 'tok', 'sec')`.
  - `test_dry_run_blocks_publishing_before_sending`, data provider with these cases:
    - `['POST', '1510742765451658/feed', []]`
    - `['POST', '17841470732347612/media_publish', ['creation_id' => '1']]`
    - `['POST', '1/photos', []]`
    - `['POST', '1/videos', ['published' => 'true']]`

    Each one throws `PublishBlocked`, and `Http::assertNothingSent()`.
  - `test_dry_run_allows_unpublished_uploads_and_containers`: these calls are sent in dry-run:
    - `post('1/photos', ['published' => false])`
    - `post('17841470732347612/media', ['image_url' => 'https://x/a.jpg'])`
  - `test_live_mode_allows_publishing`: `post('1/feed', ['message' => 'hi'], 'tok')` is sent when the mode is `'live'`.
  - `test_meta_error_is_plain_and_never_contains_the_token`: a 400 response with body `{"error":{"message":"Invalid OAuth access token.","code":190,"fbtrace_id":"A1"}}` gives a `MetaError`:
    - `code() === 190`
    - `plain()` contains `make a new key`
    - neither `getMessage()` nor `plain()` contains `tok-secret-123`
  - `test_connection_failure_is_transient`: the fake throws `ConnectionException`, which gives a `MetaError` with `transient() === true` and `plain()` containing `no internet connection`.
  - `test_upload_sends_file_as_multipart_to_video_host`: uses a temp file, and `upload('1/videos', ['published' => false], $tmp, 'tok', true)` hits `https://graph-video.facebook.com/v27.0/1/videos`.

- [ ] **Step 2: Run** `php artisan test --filter=MetaGraphTest`. Expected: FAIL (class not found).

- [ ] **Step 3: Implement** `MetaGraph`, `MetaError` and `PublishBlocked`.
  - `plain()` mapping:

    | Meta code | Plain message |
    |---|---|
    | 190 | "The Meta key no longer works: make a new key (How to use → Meta key) and save it on the Meta page." |
    | 10, 200–299 | "Meta refused: the key is missing a permission. Check the Meta page." |
    | 4, 17, 32, 613 | "Meta's hourly limit was reached: try again in an hour." (transient) |
    | 1, 2 | "Meta had a temporary problem: try again in a few minutes." (transient) |
    | other | Meta's own message |
  - Transient is also true when the body has `"is_transient": true`.
  - Use `Http::timeout(30)` for normal calls and `timeout(600)` for uploads.

- [ ] **Step 4: Run** `php artisan test --filter=MetaGraphTest`. Expected: PASS. Then run `php artisan test`: all green.

- [ ] **Step 5: Commit** `feat: Meta client that never publishes from Dev (SM-008)`.

### Task 2: Meta page: App Secret, permanent key, connect Page + Instagram

**Files:**
- Create:
  - `app/Domain/Meta/MetaCredentials.php`
  - `app/Domain/Meta/KeyCheck.php` (readonly value object)
  - `app/Domain/Meta/MetaConnector.php`
  - `app/Filament/Pages/MetaSettings.php`
  - `resources/views/filament/pages/meta-settings.blade.php`
- Modify: `app/Filament/Resources/ConnectedAccounts/ConnectedAccountResource.php`. Its empty-state text becomes "Save the Meta key on the Meta page to connect the Facebook Page and Instagram."
- Test: `tests/Feature/MetaConnectTest.php` (seeded DB; brand `moggies-international`; Filament setup as in `BackupsPageTest`)

**Interfaces:**
- Consumes: `MetaGraph` (resolved from the container), `Setting`, `ConnectedAccount`, `Network`, `AccountStatus`.
- Produces:
  - `MetaCredentials`:
    - Setting keys: `meta.app_id`; secrets `meta.app_secret` and `meta.key.{brandId}`; dates `meta.app_secret_saved_at` and `meta.key_saved_at.{brandId}`.
    - Methods: `appId(): string`, `appSecret(): ?string`, `saveAppSecret(string $appId, string $secret): void`, `key(Brand $b): ?string`, `saveKey(Brand $b, string $key): void`, `savedAt(string $what, ?Brand $b = null): ?CarbonImmutable`.
    - Every save does `trim()` and strips all whitespace inside the key.
  - `KeyCheck`:
    - Fields: `bool $valid`, `?CarbonImmutable $expiresAt` (null = never), `array $scopes`, `array $missing`, `array $missingWanted`, `array $extra`, `?string $problem`, `bool $unreachable`, `CarbonImmutable $checkedAt`.
    - `toArray()` / `fromArray()`, saved as Setting `meta.key_check.{brandId}`.
  - `MetaConnector`:
    - Constants `REQUIRED`, `WANTED`.
    - `checkKey(Brand $b): KeyCheck`: `GET debug_token?input_token=<key>` with token `"{appId}|{appSecret}"`.
      - `expires_at` 0 → null.
      - A transient `MetaError` (no connection) gives `unreachable: true` with no problem.
      - `data.app_id` ≠ `appId()` → problem "This key was made for a different Meta app. Make it for 'Moggies Social Manager'."
      - `is_valid` false → problem "Meta says this key is not valid. Make a new key."
    - `connect(Brand $b): array`: returns `['page' => ?ConnectedAccount, 'instagram' => ?ConnectedAccount, 'problems' => list<string>, 'warnings' => list<string>]`.
      - Calls `checkKey` first and stops on a problem or on missing required scopes ("The key is missing: … Make a new key and tick these.").
      - Then `GET me/accounts?fields=id,name,access_token,instagram_business_account{id,username}`. The key must see exactly one Page; with 0 or more than 1, stop with a plain problem naming the Pages it sees.
      - Upsert by (brand, network, external_id):
        - Facebook Page: `external_id` = Page id, `name` = Page name, `access_token` = Page token.
        - Instagram: `external_id` = IG id, `name` = `'@'.username`, `access_token` = Page token, `meta` = `['page_id' => …, 'username' => …]`.
        - Both: `token_expires_at` = key expiry (null), status Connected, `last_error` null, `meta.via` = `'system_user_key'`.
      - If the Page has no Instagram, add the warning "Meta shows no Instagram account linked to this Page for the key: in business settings give the system user the Instagram account."
  - `MetaSettings` page (navigation "Meta (Facebook & Instagram)", sort 80). Header actions:
    - `saveAppSecret`: App ID TextInput, default `MetaCredentials::appId()`; App Secret, password, required.
    - `saveKey`: key, password, required. If no App Secret is saved, show the error "Save the App Secret first." Otherwise save, `connect()`, then notify with the result (success, or danger listing the problems).
    - `checkNow`: runs `connect()` again.

    The view shows:
    - App ID
    - "App Secret: saved on <date> / not saved yet"
    - "Key: saved on <date>"
    - the last check: valid, "never expires" or the date, missing, extra
    - the connected Page and Instagram with their ids
    - a short "How to make the key" list (same steps as HOW_TO_USE)

- [ ] **Step 1: Write the failing tests.** Fakes: `debug_token` → `{"data":{"app_id":"2181731786035384","is_valid":true,"expires_at":0,"scopes":[…required+wanted…]}}`; `me/accounts` → one Page `1510742765451658` "Moggies International", token `page-tok`, IG `{id:"17841470732347612",username:"moggies_international"}`.
  - `test_saving_the_key_connects_page_and_instagram`:
    - Calling `saveAppSecret` and then `saveKey` with `"  EAAkey\n123 "` gives two accounts with the ids, names and tokens above.
    - `MetaCredentials::key($brand) === 'EAAkey123'`.
    - The raw `settings.value` for the key doesn't contain `EAAkey123`.
  - `test_new_key_updates_accounts_in_place_and_clears_error`: an existing Page account in Error with an old token, after `saveKey` → still 2 rows, the token is `page-tok`, status Connected, `last_error` null.
  - `test_missing_required_permission_blocks_and_names_it`: the scopes lack `instagram_content_publish` → no accounts, and the notification body contains `instagram_content_publish`.
  - `test_extra_permissions_warn_but_connect`: the scopes include `ads_management` → accounts saved, and the warning contains `ads_management`.
  - `test_key_for_another_app_is_refused` and `test_invalid_key_is_explained`: plain problem texts as above.
  - `test_page_without_instagram_connects_page_and_warns`.
  - `test_key_needs_the_app_secret_first`: no HTTP calls are sent.
  - `test_page_never_shows_secrets`: after saving, `Livewire::test(MetaSettings::class)` → `assertDontSee('EAAkey123')`, `assertDontSee('app-secret-value')`, `assertSee('Saved on')`.

- [ ] **Step 2: Run** `php artisan test --filter=MetaConnectTest`. Expected: FAIL.

- [ ] **Step 3: Implement** the files above.

- [ ] **Step 4: Run** `php artisan test`. Expected: all PASS.

- [ ] **Step 5: Commit** `feat: Meta page saves the permanent key and connects the Page and Instagram (SM-008)`.

### Task 3: Daily check of the Meta key

**Files:**
- Modify: `app/Console/Commands/CheckAccountsCommand.php`
- Test: `tests/Feature/MetaKeyDailyCheckTest.php`

**Interfaces:**
- Consumes: `MetaConnector::checkKey`, `MetaCredentials::key`.
- Produces: after the existing loop, for each brand with a saved key:
  - **Unreachable:** if the check is `unreachable`, print `Meta key: could not check (no connection).` and change nothing.
  - **Problem:** if it has a problem, the brand's `facebook_page` and `instagram` accounts go to `AccountStatus::Error` with `last_error` = the problem text. On the change to Error only, send the database notification "Meta key stopped working" to the brand's users.
  - **Valid:** if it is valid, each account's `token_expires_at` = `expiresAt`, so `AccountHealth` warns before an expiring key.

- [ ] **Step 1: Write the failing tests.**
  - `test_invalid_key_marks_meta_accounts_error_and_notifies_once`: run the command twice → 1 notification.
  - `test_offline_check_changes_nothing`: the fake throws `ConnectionException` → status stays Connected, no notification.
  - `test_no_key_means_no_meta_call`: `Http::assertNothingSent()`.
- [ ] **Step 2: Run** `php artisan test --filter=MetaKeyDailyCheckTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php artisan test`. Expected: all PASS.
- [ ] **Step 5: Commit** `feat: daily check of the Meta key (SM-008)`.

### Task 4: Hostinger folder: settings and "Test the folder"

**Files:**
- Run: `composer require league/flysystem-ftp:^3.0`
- Create:
  - `scripts/hostinger/relay.htaccess`. Its exact content:
    - `Options -Indexes -ExecCGI`
    - `<IfModule mod_headers.c>Header set X-Robots-Tag "noindex, nofollow"</IfModule>` (on separate lines)
    - `<FilesMatch "\.(php\d?|phtml|phar|cgi|pl|py|sh|htm|html|js|svg)$">Require all denied</FilesMatch>`
  - `app/Domain/Relay/RelayFolder.php`
  - `app/Filament/Pages/RelaySettings.php`
  - `resources/views/filament/pages/relay-settings.blade.php`
- Test: `tests/Feature/RelayFolderTest.php`

**Interfaces:**
- Produces:
  - Constructor: `RelayFolder::__construct(Filesystem $disk, string $publicUrl)`. `$publicUrl` must be `https://…/` with a trailing slash.
  - `RelayFolder::fromSettings(): ?self` builds `Storage::build(['driver' => 'ftp', 'host', 'port', 'username', 'password', 'root' => '/', 'ssl' => (bool) relay.ftps, 'passive' => true, 'timeout' => 30])` from these Setting keys:
    - `relay.host`, `relay.port` (21), `relay.username`, `relay.public_url`, `relay.ftps` (true)
    - secret `relay.password`
    - `relay.saved_at`
  - `put(string $localPath): array{name: string, url: string}`. It throws `InvalidArgumentException` for other extensions and streams the file.
  - `delete(string $name): void`, `exists(string $name): bool`, `ensureHtaccess(): void` (uploads the repo file if `.htaccess` is missing).
  - `test(): array{ok: bool, at: string, steps: list<array{label: string, ok: bool, note: ?string}>}`, also saved as Setting `relay.last_test`. The steps:
    1. "Folder lock in place" (`ensureHtaccess`)
    2. "Upload a test file" (random 32-hex `.txt`, random content)
    3. "Download it from the website": HTTP 200, the same body, and an `X-Robots-Tag` containing `noindex`. Without the header: fail with "the folder's lock file is not working".
    4. "The folder can't be listed": GET of the public URL ≠ 200, or the body doesn't contain the file name
    5. "Delete it"
    6. "It's gone": `exists()` false over FTP. If the website still answers 200: the note "the website's cache may show it for a few minutes", and the step still passes.

    The test file is deleted in a `finally` block.
  - `RelaySettings` page ("Hostinger folder", sort 81). Actions:
    - `saveRelay`: host, port, username, password (password field; blank keeps the saved one; required on first save), public address (url, must start with `https://`, gets a trailing slash), encrypted FTP toggle (default on)
    - `testRelay`

    The view shows the settings (never the password) and the last test's steps.
  - `AppServiceProvider` binds `RelayFolder::class` to `fn () => RelayFolder::fromSettings()`, which is **null until the folder is set up**. All code gets the folder with `app(RelayFolder::class)` and handles null. Tests bind a fake: `app()->instance(RelayFolder::class, new RelayFolder(Storage::fake('relay'), 'https://moggiesinternational.com/mr-test/'))`.

- [ ] **Step 1: Write the failing tests.**
  - `test_put_uses_random_name_and_public_url`: the name matches `/^[0-9a-f]{32}\.jpg$/`, and the url = base + name.
  - `test_put_refuses_other_file_types`: `.php` throws, and the disk is empty.
  - `test_folder_test_passes_and_leaves_only_the_lock_file`: an `Http::fake` callback serves the fake disk's files with `X-Robots-Tag: noindex, nofollow` and 403 for the base URL → `ok`, and `disk->allFiles() === ['.htaccess']`.
  - `test_folder_test_fails_without_noindex_header`.
  - `test_folder_test_fails_when_listing_shows_files`.
  - `test_cached_copy_is_a_note_not_a_failure` (Review Focus 3): HTTP keeps answering 200 after the delete → `ok` true, and the last step's note mentions the cache.
  - `test_failed_download_still_deletes_the_test_file`: HTTP 500 → not ok, and only `.htaccess` remains.
  - `test_relay_password_kept_when_left_blank_and_never_shown`.
- [ ] **Step 2: Run** `php artisan test --filter=RelayFolderTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php artisan test`. Expected: all PASS.
- [ ] **Step 5: Commit** `feat: Hostinger folder settings and folder test (SM-008)`.

### Task 5: Test send (rehearsal) to Instagram and Facebook

**Files:**
- Create:
  - `app/Domain/Library/SourceFiles.php`
  - `app/Domain/Meta/Rehearsal.php`
  - `app/Console/Commands/RehearseCommand.php` (`smm:meta:rehearse {--photo=} {--video=}`)
  - `app/Filament/Pages/TestSend.php`
  - `resources/views/filament/pages/test-send.blade.php`
- Modify: `config/smm.php` (add `'library_source' => env('SMM_LIBRARY_SOURCE', 'D:\\Pravin\\Moggi\\Markting Social Media')`)
- Test: `tests/Feature/RehearsalTest.php`

**Interfaces:**
- Consumes: `MetaGraph`, `RelayFolder`, the brand's `ConnectedAccount`s (Task 2).
- Produces:
  - `SourceFiles::list(string $root, array $extensions = ['jpg', 'jpeg', 'mp4', 'mov'], int $limit = 2000): array<string, string>` maps the relative path (forward slashes) to the full path, sorted by path, read-only (`RecursiveDirectoryIterator`; it never writes or touches files).
  - `Rehearsal::__construct(MetaGraph $graph, ?RelayFolder $relay, ?Closure $sleep = null)`. Callers pass `app(MetaGraph::class)` and `app(RelayFolder::class)`.
  - `run(Brand $b, string $photoPath, string $videoPath): array{ok: bool, at: string, copy: string, steps: list<array{label: string, ok: bool, note: ?string}>}`, also saved as Setting `rehearsal.last` (`copy` = `basename(base_path())`). Steps in order:
    - **Instagram photo:** relay `put` → `POST {ig}/media {image_url}` → poll `GET {container}?fields=status_code` every 3 s, up to 60 s, until `FINISHED`; `ERROR`/`EXPIRED` or a timeout fails the step → relay `delete` (always).
    - **Instagram video:** the same with `{media_type: 'REELS', video_url}`, polling every 10 s up to 600 s.
    - **Facebook photo:** `upload {page}/photos {published: false}` → `GET {id}?fields=id` → `DELETE {id}` (the delete is attempted whenever the upload returned an id).
    - **Facebook video:** `upload {page}/videos {published: false}` (video host) → `GET {id}?fields=id,status` → `DELETE {id}`.

    The photo must be `.jpg`/`.jpeg` and the video `.mp4`/`.mov`. If the accounts or the Hostinger folder are missing, the result is one failed step: "Connect Meta first" or "Set up the Hostinger folder first". The run **never** calls `media_publish` or `/feed`, in any mode. Instagram containers that are never published expire by themselves after 24 hours, so they need no delete.
  - `TestSend` page ("Test send", sort 82):
    - two searchable Selects from `SourceFiles::list(config('smm.library_source'))`, split into photos and videos
    - the action `run` ("Run test send: nothing is published; takes 1–3 minutes"), run synchronously with `set_time_limit(900)`
    - the view shows `rehearsal.last` as a list of passed (✓) and failed (✗) steps with notes
  - The command prints the steps and exits 1 if any step failed.

- [ ] **Step 1: Write the failing tests.** Use a fake relay disk, `Http::fake`, and a no-op sleep.
  - `test_rehearsal_runs_every_step_and_never_publishes`:
    - The IG container answers `IN_PROGRESS` then `FINISHED`. The run is `ok`, with 4 groups of steps.
    - `Http::assertNotSent` for any URL containing `media_publish` or `/feed`.
    - The Facebook uploads carried `published=false`, and both `DELETE` calls were sent.
    - The relay disk is empty.
  - `test_rehearsal_never_publishes_even_in_live_mode`: the same with `new MetaGraph('sec', 'live')`.
  - `test_container_error_fails_the_step_and_cleans_up` (Review Focus 4): the video container is `ERROR` → not ok, the step note shows Meta's `status` text, and the relay disk is empty.
  - `test_facebook_read_failure_still_deletes_the_upload` (Review Focus 4): `GET {id}` returns 500 → the `DELETE {id}` was still sent.
  - `test_missing_setup_is_explained`: no accounts → one failed step "Connect Meta first", and no HTTP calls.
  - `test_source_files_lists_postable_files_with_any_names` (Review Focus 5): a temp tree with `9 Product ownsr/Moggies International your trusted Partener !.mp4`, `a&b/फोटो.jpg`, `x.wav` and `y.wfp` gives exactly the two postable files, keyed by relative path, with their file modified times unchanged.
- [ ] **Step 2: Run** `php artisan test --filter=RehearsalTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php artisan test`. Expected: all PASS.
- [ ] **Step 5: Commit** `feat: Test send proves Instagram and Facebook without publishing (SM-008)`.

### Task 6: Visible Facebook test post (App only)

**Files:**
- Create: `app/Console/Commands/MetaTestPostCommand.php`
- Modify: `app/Console/Commands/DailyCommand.php` (call `smm:meta:test-post --cleanup` first)
- Test: `tests/Feature/MetaTestPostTest.php`

**Interfaces:**
- Signature: `smm:meta:test-post {--delete} {--cleanup}`. Uses `MetaGraph` from the container and the brand's Page account (the first brand that has one).
- **Create:**
  - If `$graph->mode() !== 'live'`, print `Only the App copy posts for real; Dev is a practice copy.` and exit 1.
  - If Setting `meta.test_post` exists, refuse with "Delete the earlier test post first (--delete)".
  - `POST {page}/feed {message: "Test from SocialMedia Manager, <d M Y H:i> IST (ref <6 hex>). It will be deleted in a few minutes."}` → `GET {id}?fields=permalink_url,is_published`.
  - Save `meta.test_post = {id, permalink, ref, created_at}`, then print the permalink and ref.
- **`--delete`:** `DELETE {id}`, then `GET {id}`, which must fail. Clear the setting and print `Test post deleted.`
- **`--cleanup`:** the same as `--delete` when a test post is saved; silent and exit 0 when none is.

- [ ] **Step 1: Write the failing tests.**
  - `test_dev_refuses_the_test_post`: dry-run → exit 1, and `Http::assertNothingSent()`.
  - `test_live_creates_then_deletes`: bind `new MetaGraph('sec', 'live')`.
    - Create sends `/feed` once, and the setting is saved.
    - `--delete` sends `DELETE`, and the setting is cleared.
  - `test_daily_cleans_up_a_leftover_test_post`.
- [ ] **Step 2: Run** `php artisan test --filter=MetaTestPostTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php artisan test`. Expected: all PASS.
- [ ] **Step 5: Commit** `feat: one-off visible Facebook test post from App (SM-008)`.

### Task 7: Wake-up trial (no admin prompt per post)

**Files:**
- Create: `scripts/windows/wake-trial.ps1` (`param([switch]$Sleep, [int]$Minutes = 3)`, not elevated, dot-sources `common.ps1`)
- Create: `docs/superpowers/plans/2026-11-19-sm-008-decisions.md` (build notes; the trial result goes here)

**Behaviour.** The script writes `<data>\run\wake-trial.json` and prints a plain summary. It checks:
1. **Wake timers:** reads `powercfg /query SCHEME_CURRENT SUB_SLEEP RTCWAKE` and records the AC and DC values (0 off, 1 on, 2 important only).
2. **Wake task in the app's folder:** `Register-ScheduledTask -TaskPath '\SocialMediaManager\' -TaskName 'WakeTrial'` with an Interactive principal (the current user), `-WakeToRun`, and a one-time trigger in `$Minutes` minutes. The action writes the time to `<data>\run\wake-trial-ran.txt`. Records success, or the exact error.
3. **Root folder:** if 2 was refused, the same in the root folder as `SocialMediaManager-WakeTrial`.
4. **Rewriting the Daily task:** tries to rewrite `\SocialMediaManager\Daily` with its **current** triggers unchanged (`Set-ScheduledTask` with the same trigger objects). Records allowed or denied. Nothing about Daily changes.
5. **Sleep test:** with `-Sleep`, puts the laptop to sleep (`System.Windows.Forms.Application::SetSuspendState('Suspend', $false, $false)`). After it resumes, waits up to 5 minutes for `wake-trial-ran.txt` and records "woke and ran at HH:mm", or "did not run".
6. **Cleanup:** removes the trial task(s) and `wake-trial-ran.txt` in a `finally` block.

- [ ] **Step 1:** Write the script. Run the PowerShell 5.1 parser over it (`[System.Management.Automation.Language.Parser]::ParseFile`): expected 0 errors. Check it is ASCII: no byte above 127.
- [ ] **Step 2:** Run it in Dev without `-Sleep`. Expected: a JSON file with results for checks 1–4, and no trial task left (`Get-ScheduledTask` shows only `Daily`).
- [ ] **Step 3: With the owner's OK,** run it with `-Sleep` (lid open, on charger, then once on battery if the DC wake value isn't 1). Record the results and the chosen part-3 mechanism in the decisions file. The order of preference:
  1. a per-post Interactive wake task in `\SocialMediaManager\`
  2. a standing task whose triggers the app rewrites
  3. a fallback reported to the owner
- [ ] **Step 4: Commit** `chore: wake-up trial script and its result (SM-008)`.

### Task 8: Bring-up with the owner, records, pull request

**Files:**
- Modify:
  - `docs/PROJECT_PLAN.html`: version v1.1, with an "Amended 19 Nov 2026" note linking the spec.
    - First milestone 20 Dec → **6 Dec 2026** (first real post Fri 4 Dec).
    - Phase 1 "23 Nov – 6 Dec 2026 (posts on the websites to 20 Dec)".
    - "photo relay" → "photo and video relay" (lines ~155, 274, 324, 334, 356, 393, 431, 436, 446, 486, 496, 513, 533).
    - #20 "reads the Markting Social Media folder in place".
    - #18 "permanent Meta key".
  - `docs/HOW_TO_USE.html`: new sections.
    - **Meta key:** what it is; how to make it in Business settings → System users → Moggies SMM App → Generate token (app "Moggies Social Manager", expiry **Never**, only the permissions in Global Constraints); paste it on the Meta page; never in chat; what "Problem: reconnect" means.
    - **Hostinger folder:** what it is; never put other files in it.
    - **Test send.**
  - `docs/DEVELOPMENT_LOG.md`: an SM-008 entry.
  - `CLAUDE.md`: the current plan's path.
  - The spec: status "approved 19 Nov 2026". In §5, the Meta app line "Keep it unpublished" is replaced with "It goes Live in part 1 (see 'Development mode hides posts')".

**Steps:**
- [ ] **Step 1: Meta in Dev (owner, with Claude's steps).**
  - Save the App Secret on Dev's Meta page (http://127.0.0.1:8091).
  - Generate key **#1 (for Dev)** and save it.
  - Expected: the check shows "valid, never expires", no missing permissions, and the Page `1510742765451658` plus `@moggies_international` connected. This also confirms the 18 Nov access fixes.
- [ ] **Step 2: Hostinger folder (owner and Claude, about 10 minutes).**
  - In hPanel → FTP Accounts, create an account whose folder is `public_html/<random name Claude gives>`.
  - Type its details on Dev's Hostinger page.
  - Claude presses **Test the folder**. Expected: all 6 steps ✓.
- [ ] **Step 3: Rehearsal in Dev.** Run `php artisan smm:meta:rehearse --photo=<a .jpg from Markting Social Media> --video=<a finished .mp4>`. Expected: every step ✓, exit 0. Afterwards, check on the Page and Instagram (signed in) that nothing new is visible.
- [ ] **Step 4: Run the full suite** with `php artisan test`: all green. Commit the docs (`docs: SM-008 log, guide, plan amendments`), push, and give the owner the PR link: `https://github.com/TeamMoggi/SocialMedia_Manager/pull/new/feat/sm-008-meta-connect`.
- [ ] **Step 5: After the merge, update App.** Run pull, `composer install --no-dev`, migrate and `optimize:clear`; then `smm:doctor` passes.
- [ ] **Step 6: Meta goes Live (owner).** In the Meta app dashboard: privacy policy https://moggiesinternational.com/privacy-policy/, category, then App Mode → Live. Whatever else Meta asks for is handled then; website wording goes through the website routine.
  - The owner saves the App Secret, a new key **#2 (for App)** and the Hostinger password in App.
  - Claude runs Test the folder and `smm:meta:rehearse` **in App**. Expected: all ✓. This is the check for the known risk of "Authorization Error" on Live apps.
- [ ] **Step 7: Visible test post in App.**
  1. Run `smm:meta:test-post` in App.
  2. Claude opens the printed permalink in headless Edge with a fresh temporary profile (signed out). Expected: the page HTML contains the post's `ref`. The owner may also look in an InPrivate window.
  3. Run `smm:meta:test-post --delete` within 10 minutes. Expected: "Test post deleted."
  4. Close Edge and delete its profile.
- [ ] **Step 8: Records.**
  - Tracker #11 and #18 done (live), with notes.
  - Tracker How-to panel: a card "Meta key, Hostinger folder, Test send".
  - Refresh the local tracker copy and rebuild the Tracker Summary.
  - Fill in the SM-008 log entry's "Where", and commit the docs on a follow-up branch if App is already merged.
