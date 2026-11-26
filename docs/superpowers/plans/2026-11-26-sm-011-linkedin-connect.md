# SM-011 LinkedIn Connect Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A "Connect LinkedIn" button that signs the owner in on LinkedIn, checks they administer the brand's company page, and saves the page as a connected account with its 60-day key; the daily check notices a dead key.

**Architecture:** Mirrors the Meta pieces (SM-008): `LinkedInCredentials` (settings), `LinkedInApi` (the only HTTP client to LinkedIn, plain `LinkedInError`s), `LinkedInConnector` (scopes, page check, saving), two routes (`start` inside the brand, `callback` outside it) in `LinkedInAuthController`, a Filament page `LinkedInSettings`, and a LinkedIn step in `smm:check-accounts`. The whole sign-in runs on `http://localhost:{port}` so the session cookie survives the round trip.

**Tech Stack:** Laravel 13, Filament 5, PHP 8.5, PHPUnit, `Http::fake` (stray requests are already prevented in `tests/TestCase.php`).

**Spec:** `docs/superpowers/specs/2026-11-26-sm-011-linkedin-connect-design.md`

**Working copy:** `D:\Pravin\Moggi\SocialMedia_Manager\Dev-LinkedIn` (worktree, branch `feat/sm-011-linkedin-connect` from `origin/main` 917ac31). Never touch `D:\Pravin\Moggi\SocialMedia_Manager\Dev` (unpushed SM-010) or `App`. Before Task 1: `composer install` in the worktree; run tests with `php vendor/bin/phpunit` (no `.data-dir` needed for tests).

## Global Constraints

- Scopes requested, exactly: `rw_organization_admin w_organization_social r_organization_social` (space-separated in the URL).
- Required to connect: `rw_organization_admin`, `w_organization_social`. Wanted (warning only): `r_organization_social`.
- Authorization URL `https://www.linkedin.com/oauth/v2/authorization`; token URL `https://www.linkedin.com/oauth/v2/accessToken` (form POST, `grant_type=authorization_code`).
- REST base `https://api.linkedin.com/rest`; headers on every REST call: `Authorization: Bearer <key>`, `LinkedIn-Version: 202611`, `X-Restli-Protocol-Version: 2.0.0`.
- Redirect URI: `http://localhost:{port}/linkedin/callback`, `{port}` = the port of the request that started the sign-in (8090 App, 8091 Dev). Registered in the portal for both ports.
- Client ID `77qh6eszwh69s9` (public) is the config default; the owner can change it on the page. Client Secret only via `Setting::putSecret('linkedin.client_secret', …)`.
- Brand page id: setting `linkedin.org_id.{brand_id}`; Moggies International = `145339181`, seeded by Task 1's migration-free default: config `smm.linkedin.default_org_id` = `145339181` used when the setting is empty AND the brand slug is `moggies-international`; any other brand with no setting takes the only administered page and stores it.
- State lives 10 minutes, single use.
- Never put the key, code, client secret or state in a log line, exception message, notification or page HTML.
- Store only page id, page name, role, scopes, connected_at: no member data (LinkedIn data rules).
- Expiry warning stays the existing `smm.token_expiry_warning_days` = 7.
- Wording style: plain owner-facing English like `MetaError::plain()`.

## Review Focus

1. **Session lost on the way back** (owner clicked Connect on `127.0.0.1`, LinkedIn returns to `localhost`): `start` must itself run on `localhost`, so the button links to `http://localhost:{port}/{brand}/linkedin/start`. Test in Task 3: the button URL host is `localhost` and keeps the port.
2. **Callback opened twice or late** (browser back/refresh, or the tab left open): second use and >10 min both refuse with "This sign-in link has expired. Press Connect LinkedIn again." and save nothing. Tests in Task 3.
3. **Reconnect that fails** (cancel, wrong account, missing scope) while a working key is saved: the saved account and key stay unchanged. Test in Task 2 and Task 3.
4. **LinkedIn returns the scope list comma-separated** (their token responses use `,`; the request uses spaces): the parser accepts both. Test in Task 1.
5. **Daily check while offline or LinkedIn 5xx/429**: nothing changes, no notification; only 401 (or the page gone from the admin list) marks Error. Tests in Task 5.

---

### Task 1: Credentials, config, errors and the LinkedIn client

**Files:**
- Modify: `config/smm.php` (add `linkedin` block after `meta`)
- Create: `app/Domain/LinkedIn/LinkedInCredentials.php`, `app/Domain/LinkedIn/LinkedInError.php`, `app/Domain/LinkedIn/LinkedInApi.php`
- Test: `tests/Unit/LinkedInApiTest.php`, `tests/Feature/LinkedInCredentialsTest.php`

**Interfaces:**
- Produces:
  - config `smm.linkedin` = `['client_id' => '77qh6eszwh69s9', 'version' => '202611', 'auth_url' => 'https://www.linkedin.com/oauth/v2', 'rest_url' => 'https://api.linkedin.com/rest', 'default_org_id' => '145339181', 'state_minutes' => 10]`
  - `LinkedInCredentials::clientId(): string`, `clientSecret(): ?string`, `saveApp(string $clientId, string $secret): void` (trims whitespace like `MetaCredentials::clean`, stores `linkedin.client_secret_saved_at`), `savedAt(): ?CarbonImmutable`, `orgId(Brand $b): ?string`, `saveOrgId(Brand $b, string $id): void`
  - `LinkedInError extends RuntimeException`: `::offline()`, `::fromResponse(int $status, array $json)`; `status(): int`, `transient(): bool` (offline, 429, ≥500), `keyRefused(): bool` (401), `plain(): string`
  - `LinkedInApi::make(): self`; `authorizeUrl(string $redirectUri, string $state, array $scopes): string`; `exchangeCode(string $code, string $redirectUri): array{access_token: string, expires_in: int, scopes: list<string>}`; `adminOrganizations(string $key): list<string>` (org ids, from `organizationAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED`, parsing `organization` or `organizationTarget` URNs); `organizationName(string $id, string $key): ?string` (`GET /organizations/{id}` → `localizedName`, null on any LinkedInError)

- [ ] **Step 1: Write the failing tests**
  - `test_authorize_url_has_code_flow_params`: parse the URL; assert host `www.linkedin.com`, path `/oauth/v2/authorization`, `response_type=code`, `client_id=77qh6eszwh69s9`, `redirect_uri=http://localhost:8091/linkedin/callback`, `state` as given, `scope === 'rw_organization_admin w_organization_social r_organization_social'`.
  - `test_exchange_code_posts_form_and_parses_scopes`: fake token URL → `['access_token'=>'AQXkey','expires_in'=>5184000,'scope'=>'rw_organization_admin,w_organization_social']`; assert result scopes `['rw_organization_admin','w_organization_social']`; `Http::assertSent` form body has `grant_type=authorization_code`, `code`, `client_secret`, `redirect_uri`.
  - `test_scope_string_with_spaces_also_parses` (Review Focus 4).
  - `test_rest_calls_send_version_and_restli_headers`: fake `organizationAcls*` with two elements (`organization` and `organizationTarget` forms, ids `145339181`, `999`); assert ids `['145339181','999']` and headers `LinkedIn-Version: 202611`, `X-Restli-Protocol-Version: 2.0.0`, `Authorization: Bearer AQXkey`.
  - `test_errors_are_plain_and_never_contain_the_key`: 401 → `keyRefused()` true, `plain()` contains "Reconnect LinkedIn" and not `AQXkey`; 429 → transient, plain mentions "daily limit"; 500 → transient; `ConnectionException` → offline, plain "Could not reach LinkedIn (no connection)."; 426 (version gone) → plain mentions "update the app".
  - Credentials: `test_secret_is_encrypted_and_trimmed`: `saveApp(' 77qh6eszwh69s9 ', " sec ret\n")` → `clientSecret() === 'secret'`, raw `settings.value` row ≠ `'secret'`; `test_org_id_defaults_for_moggies_only`.
- [ ] **Step 2: Run** `php vendor/bin/phpunit --filter "LinkedInApiTest|LinkedInCredentialsTest"` — expected FAIL (classes missing).
- [ ] **Step 3: Implement** the three classes and config. `LinkedInApi` uses `Http::timeout(30)->acceptJson()`; never puts the key in an exception. Exchange errors: LinkedIn's `error`/`error_description` → `LinkedInError` with plain "LinkedIn refused the sign-in: <description>".
- [ ] **Step 4: Run** the same filter — PASS.
- [ ] **Step 5: Commit** `feat: LinkedIn client, credentials and plain errors`

### Task 2: LinkedInConnector (scopes, page check, saving)

**Files:**
- Create: `app/Domain/LinkedIn/LinkedInConnector.php`, `app/Domain/LinkedIn/ConnectResult.php`
- Test: `tests/Feature/LinkedInConnectTest.php`

**Interfaces:**
- Consumes: Task 1 (`LinkedInApi`, `LinkedInCredentials`, `LinkedInError`).
- Produces:
  - `LinkedInConnector::SCOPES = ['rw_organization_admin','w_organization_social','r_organization_social']`, `REQUIRED = ['rw_organization_admin','w_organization_social']`, `WANTED = ['r_organization_social']`
  - `final readonly class ConnectResult { ?ConnectedAccount $account; list<string> $problems; list<string> $warnings; }`
  - `connect(Brand $brand, string $code, string $redirectUri): ConnectResult`
  - `check(Brand $brand): ?string` — `null` = works; a problem string = key refused or page no longer administered; throws `LinkedInError` (transient) when nothing can be known.

- [ ] **Step 1: Write the failing tests** (`Http::fake` token + `organizationAcls*` + `organizations/145339181*` → `localizedName: 'Moggies International'`):
  - `test_connect_saves_the_page_with_key_and_expiry`: account `network=linkedin_org`, `external_id='145339181'`, `name='Moggies International'`, `access_token='AQXkey'` (decrypted), `token_expires_at` = now+5184000 s (freeze time), `status=Connected`, `meta` has `via='oauth'`, `scopes`, `role='ADMINISTRATOR'`, `connected_at`; no member fields.
  - `test_missing_required_scope_refuses_and_saves_nothing`: problems mention `w_organization_social`; no account row.
  - `test_missing_wanted_scope_connects_with_warning`.
  - `test_not_administrator_of_brand_page_refuses`: acls return only `999` → problem "is not an Administrator of the LinkedIn page 145339181"; nothing saved.
  - `test_brand_without_org_id_takes_single_page_and_stores_it` and `test_brand_without_org_id_and_two_pages_refuses_listing_ids`.
  - `test_failed_reconnect_keeps_working_key` (Review Focus 3): existing account with `access_token='OLDkey'`; reconnect with missing scope → account unchanged, `OLDkey` still decrypts.
  - `test_name_lookup_refused_uses_id_and_warns`.
  - `test_check_returns_null_when_page_still_administered`, `test_check_reports_refused_key`, `test_check_reports_page_gone`, `test_check_throws_transient_when_offline`.
- [ ] **Step 2: Run** `php vendor/bin/phpunit --filter LinkedInConnectTest` — FAIL.
- [ ] **Step 3: Implement.** Save only after every check passed: `ConnectedAccount::updateOrCreate(['brand_id','network'=>LinkedInOrganization,'external_id'], [...])`, clearing `last_error` and any `meta.error_from`. `check()` reads the key from the brand's `linkedin_org` account.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: LinkedIn connector checks scopes and the brand page before saving`

### Task 3: Sign-in routes (start and callback)

**Files:**
- Create: `app/Http/Controllers/LinkedInAuthController.php`
- Modify: `app/Providers/Filament/AppPanelProvider.php` (add `start` to `authenticatedTenantRoutes`, `callback` to `->authenticatedRoutes(...)`)
- Test: `tests/Feature/LinkedInSignInTest.php`

**Interfaces:**
- Consumes: Task 2 `LinkedInConnector::connect`, `ConnectResult`; Task 1 `LinkedInApi::authorizeUrl`.
- Produces: route names `linkedin.start` (`GET {tenant}/linkedin/start`) and `linkedin.callback` (`GET linkedin/callback`); session key `linkedin.oauth` = `['state','brand_id','port','at']`; static `LinkedInAuthController::startUrl(Brand $brand, int $port): string` = `http://localhost:{port}/{slug}/linkedin/start`; flash key `linkedin.result` = `['ok'=>bool,'title'=>string,'body'=>?string]` read by Task 4's page.

- [ ] **Step 1: Write the failing tests** (real GETs with `withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])`, URLs on `http://localhost:8091`):
  - `test_start_redirects_to_linkedin_with_state_in_session`: 302 to authorize URL; `redirect_uri=http://localhost:8091/linkedin/callback`; session state equals URL state; 40+ random chars.
  - `test_start_without_client_secret_goes_back_with_reason`.
  - `test_start_url_uses_localhost_and_port` (Review Focus 1): `startUrl($brand, 8091)` → `http://localhost:8091/moggies-international/linkedin/start`.
  - `test_callback_success_connects_and_redirects_to_page_on_127`: after start, callback `?code=c&state=<s>` → 302 to `http://127.0.0.1:8091/moggies-international/linkedin`; account saved; flashed `ok=true`, title starts "Connected: Moggies International".
  - `test_callback_cancel_saves_nothing`: `?error=user_cancelled_authorize&state=<s>` → flash "You cancelled on LinkedIn. Nothing changed."
  - `test_callback_with_wrong_or_missing_state_refuses` → 302 (not an error page) with flash "This sign-in link has expired. Press Connect LinkedIn again.", nothing saved, no token request sent (`Http::assertNothingSent`). Target: the brand's LinkedIn page on `127.0.0.1` when the session still names the brand and port; otherwise `http://127.0.0.1:{callback request port}/` (the app picks the brand).
  - `test_callback_used_twice_refuses_second` and `test_callback_after_ten_minutes_refuses` (Review Focus 2, `travel(11)->minutes()`).
  - `test_callback_response_never_contains_code_or_key`: response body/headers/flash have no `AQXkey`, `c`ode value or client secret.
- [ ] **Step 2: Run** `php vendor/bin/phpunit --filter LinkedInSignInTest` — FAIL.
- [ ] **Step 3: Implement.** `start` pulls a fresh state (`Str::random(48)`), stores it, redirects away (`redirect()->away`). `callback` pulls (`session()->pull`) the stored state first, then validates (`hash_equals`, age), then handles `error` or `code`. Port comes from the session, not the callback request.
- [ ] **Step 4: Run** — PASS. Also run `tests/Feature/LocalAccessTest.php` — still PASS.
- [ ] **Step 5: Commit** `feat: LinkedIn sign-in start and return on localhost`

### Task 4: The LinkedIn page

**Files:**
- Create: `app/Filament/Pages/LinkedInSettings.php`, `resources/views/filament/pages/linkedin-settings.blade.php` (copy the structure of `meta-settings.blade.php`)
- Test: `tests/Feature/LinkedInSettingsPageTest.php`

**Interfaces:**
- Consumes: Task 1 credentials, Task 2 `check`, Task 3 `startUrl` and flash `linkedin.result`.
- Produces: page slug `linkedin`, navigation label `LinkedIn`, `navigationSort` 81.

- [ ] **Step 1: Write the failing tests** (Livewire, as `MetaConnectTest` sets up tenant):
  - `test_save_app_details_stores_secret_and_never_shows_it`: call action `saveApp` with `client_id`, `client_secret='topsecret'`; rendered HTML lacks `topsecret`, shows "Saved on".
  - `test_connect_action_points_to_localhost_start`: `connect` action URL = `startUrl($brand, config('smm.port'))`; hidden (or disabled with "Save the Client Secret first.") when no secret.
  - `test_page_shows_connected_page_and_expiry`: an account with `token_expires_at` 25 Jan 2027 → shows "Moggies International", "145339181", "25 Jan 2027"; never the key.
  - `test_label_changes_to_reconnect_once_connected`.
  - `test_check_now_reports_result`: fakes acls → success notification "LinkedIn key works"; 401 → danger "LinkedIn no longer accepts the key…".
  - `test_flashed_result_is_shown_as_notification`.
- [ ] **Step 2: Run** `php vendor/bin/phpunit --filter LinkedInSettingsPageTest` — FAIL.
- [ ] **Step 3: Implement.** Actions: `saveApp` (modal, Client ID default from credentials, Client Secret password, required), `connect` (`->url(fn () => LinkedInAuthController::startUrl($brand, (int) config('smm.port')))`, label "Connect LinkedIn" / "Reconnect LinkedIn"), `checkNow`. Modal text: "From linkedin.com/developers → Moggies Social Manager → Auth. Never paste the Client Secret into a chat."
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: LinkedIn page with app details, Connect and Check now`

### Task 5: Daily check

**Files:**
- Modify: `app/Console/Commands/CheckAccountsCommand.php` (add `checkLinkedInKeys(LinkedInConnector $linkedin)` called after `checkMetaKeys`)
- Test: `tests/Feature/LinkedInKeyDailyCheckTest.php`

**Interfaces:**
- Consumes: Task 2 `LinkedInConnector::check`.
- Produces: `meta.error_from = 'linkedin_key'` on accounts it marks Error.

- [ ] **Step 1: Write the failing tests:**
  - `test_refused_key_marks_error_and_notifies_once` (run command twice; one database notification titled "LinkedIn key stopped working").
  - `test_page_gone_marks_error_with_reason`.
  - `test_working_key_clears_only_its_own_error` (an error with `error_from='other'` stays).
  - `test_offline_or_rate_limited_changes_nothing` (Review Focus 5: `ConnectionException`, 429, 503 each).
  - `test_expiry_warning_still_comes_from_account_health` (account expiring in 5 days → Expiring + existing notification).
  - `test_brand_without_linkedin_account_makes_no_request` (`Http::assertNothingSent`).
- [ ] **Step 2: Run** `php vendor/bin/phpunit --filter LinkedInKeyDailyCheckTest` — FAIL.
- [ ] **Step 3: Implement** in the shape of `checkMetaKeys`; console line "LinkedIn key: works." / "LinkedIn key: <problem>" / "LinkedIn key: could not check now (…). Nothing changed."
- [ ] **Step 4: Run** the filter and then the whole suite `php vendor/bin/phpunit` — all PASS (main had 252+ tests).
- [ ] **Step 5: Commit** `feat: daily check notices a LinkedIn key LinkedIn no longer accepts`

### Task 6: Docs and the real sign-in in the test copy

**Files:**
- Modify: `docs/DEVELOPMENT_LOG.md` (new `## SM-011 — Connect the LinkedIn company page (26 Nov 2026)` at the top, same layout as SM-008), `docs/HOW_TO_USE.html` (section "LinkedIn: connect, and every 2 months" + date), `docs/superpowers/plans/2026-11-26-sm-011-decisions.md` (calls made while building)

- [ ] **Step 1:** Write the docs. HOW_TO_USE steps: open the app → LinkedIn → Save app details (Client ID + Secret from the Auth tab) → Connect LinkedIn → Allow on LinkedIn (signed in as the page's Administrator) → green "Connected"; reconnect when the app warns (7 days before); never paste the secret in chat.
- [ ] **Step 2: Commit** `docs: SM-011 log, how-to-use and decisions`
- [ ] **Step 3: Real check (owner present).** Stop the Dev app if running (`D:\...\Dev\scripts\windows\stop.ps1`). Set up the worktree with its own data: `.\scripts\windows\setup.ps1 -DataDir D:\Pravin\Moggi\SocialMedia_Manager\DevLinkedInData -PublishMode dry-run -Port 8091`, then `start.ps1 -Open`. Owner: LinkedIn page → Save app details → Connect LinkedIn → Allow. Expected: "Connected: Moggies International, key valid until <today+60d>". Then `php artisan smm:check-accounts` prints "LinkedIn key: works." Record outcome in the log (no secrets). Stop the worktree app afterwards.
- [ ] **Step 4:** Push the branch and give the owner the PR compare link (gh is not installed) — only on the owner's OK.

## After merge (owner's OK at each step)

Update App (`update.ps1` or pull + `composer install --no-dev` + `migrate` + `optimize:clear`), owner connects LinkedIn in App on 8090, `smm:check-accounts` in App, tracker #19 done (local + live), How-to card, local copy, Tracker Summary, memory. Remove the `DevLinkedInData` folder and the worktree once merged.
