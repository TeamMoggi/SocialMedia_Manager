# SM-011 — Connect the LinkedIn company page (design)

Tracker #19. Written and approved by the owner 26 Nov 2026.

## 1. Why

LinkedIn approved app 265142813 "Moggies Social Manager" for the Community Management API
(Development tier) on 25 Nov 2026 (#10). Before the app can post to LinkedIn or read its
statistics, it needs a working key for the Moggies International company page (145339181).
This step only connects; posting and statistics come later.

## 2. Goals

- The owner connects the LinkedIn page from the app with one button and one LinkedIn approval.
- The app confirms the signed-in person is an **Administrator** of the brand's company page and
  saves the page as a connected account (`linkedin_org`), with the key's expiry date.
- The key lasts 60 days and cannot be renewed by the app (no refresh keys for our tier), so the
  app warns before it runs out and reconnecting is the same button.
- The daily account check notices a key LinkedIn no longer accepts.
- Secrets (Client Secret, the key) are stored locked on the laptop and never shown or logged.

## 3. Non-goals

- Posting to LinkedIn (joins the publisher after SM-010).
- Statistics, followers, comments (#29, #30, #34).
- The personal LinkedIn profile (#40).
- Standard tier application (#41): it needs this step working first.

## 4. Anti-goals (a failure even if it "works")

- Asking LinkedIn for more than the app needs now.
- A Client Secret or key appearing on screen, in a log, in an error message or in chat.
- Silently connecting a page the person is not an Administrator of, or the wrong page.
- A failed reconnect that destroys a key that still works.

## 5. Constraints (from LinkedIn's documents and the portal, read 26 Nov 2026)

- Sign-in: OAuth 2.0 authorization code flow.
  `GET https://www.linkedin.com/oauth/v2/authorization` with `response_type=code`, `client_id`,
  `redirect_uri`, `state`, `scope` (space-separated). Code lives 30 minutes, use at once.
  `POST https://www.linkedin.com/oauth/v2/accessToken` (form) with `grant_type=authorization_code`,
  `code`, `client_id`, `client_secret`, `redirect_uri` → `access_token`, `expires_in` (5184000 = 60
  days), `scope`. Cancel → back to the redirect with `error=user_cancelled_login` or
  `user_cancelled_authorize`.
- Redirect address must match exactly. Saved in the portal 26 Nov:
  `http://localhost:8090/linkedin/callback` (App) and `http://localhost:8091/linkedin/callback` (Dev). LinkedIn accepted http://localhost.
- Requesting a different scope set invalidates the earlier keys: the owner then signs in again.
  Because the key is renewed every 60 days anyway, adding scopes later costs one extra sign-in.
- Pages the member administers: `GET https://api.linkedin.com/rest/organizationAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED`
  with headers `Authorization: Bearer`, `LinkedIn-Version: YYYYMM`, `X-Restli-Protocol-Version: 2.0.0`.
  Elements carry `organization` (`urn:li:organization:<id>`), `role`, `state`.
- Versions: monthly, each supported at least one year. Pin `202611` (latest on 26 Nov 2026) in
  config; supported until at least Nov 2027.
- Development tier: 500 calls per app per day. Connect + daily check use a handful.
- Data rules (see the 20 Nov notes): store only the page id, name and role, never member data.
- Scopes the portal lists for the app (26 Nov): r_member_postAnalytics, r_organization_followers,
  r_organization_social, rw_organization_admin, r_organization_social_feed, w_member_social,
  r_member_profileAnalytics, w_organization_social, r_basicprofile, w_organization_social_feed,
  w_member_social_feed, r_1st_connections_size.
- The app binds 127.0.0.1 only; `EnsureLocalRequest` already accepts the host `localhost`. Browser
  cookies differ between `127.0.0.1` and `localhost`, so the whole sign-in must run on `localhost`.
- App runs on 8090, Dev on 8091.

## 6. Approaches

- **A. Connect button with LinkedIn sign-in (chosen by the owner, 26 Nov).** The app sends the
  owner to LinkedIn and receives the key itself. One click every 60 days, no copying.
- B. Paste a key from LinkedIn's token generator tool. Less to build, but a long key copied by hand
  every 60 days. Rejected: error-prone for a key that expires.
- C. Relay the sign-in through a page on moggiesinternational.com. Only needed if LinkedIn had
  refused http://localhost; it accepted it, so dropped.

## 7. Design

### 7.1 Scopes (Claude's call: minimum now, add later)

Ask for: `rw_organization_admin` (find the page and the role; page statistics later),
`w_organization_social` (post as the page, next part), `r_organization_social` (read back the
page's own posts, needed to confirm a post went out).
**Required** to connect: `rw_organization_admin`, `w_organization_social`.
**Wanted** (warning if LinkedIn returns the key without it): `r_organization_social`.
Not asked: all member scopes (`w_member_social`, `r_basicprofile`, member analytics, connections),
`r_organization_followers` (mentions), comment scopes (`*_social_feed`, added with #34).

### 7.2 LinkedIn page in the app

A new page beside "Meta (Facebook & Instagram)": **LinkedIn**, path `/<brand>/linkedin`, same look.
Shows: Client ID (plain, it is public), whether a Client Secret is saved, the connected page
(name, id), the key's expiry date, the last check and any problem.

Actions:
- **Save app details**: Client ID + Client Secret (password field). Client Secret stored with the
  existing encrypted setting store, never shown back.
- **Connect LinkedIn** / **Reconnect LinkedIn** (after a first connection): starts the sign-in.
  Disabled with a reason until the app details are saved.
- **Check now**: runs the same check as the daily job.

### 7.3 Sign-in flow

1. Button sends the browser to `http://localhost:{port}/{brand}/linkedin/start` (same port as the
   current request), so the session is on `localhost` from here on.
2. `start` makes a random `state` (stored in the session with the brand id and time), and redirects
   to LinkedIn's authorization URL with the scopes in 7.1 and
   `redirect_uri = http://localhost:{port}/linkedin/callback`.
3. `callback` (no brand in the path, fixed by LinkedIn) checks: `state` present, matches, under 10
   minutes old, used once. Wrong or missing → plain error page, nothing saved.
   `error=user_cancelled_*` → back to the LinkedIn page with "You cancelled on LinkedIn. Nothing
   changed."
4. Exchanges the code for the key. Checks the returned `scope` against 7.1 (missing required →
   refuse, saved key unchanged; missing wanted → warning).
5. Lists the pages via organizationAcls (ADMINISTRATOR, APPROVED).
   - The brand's page id is known (Moggies International = 145339181, stored per brand as
     `linkedin.org_id`; if empty, a single administered page is taken and stored).
   - Brand page not in the list → refuse: "This LinkedIn account is not an Administrator of
     <page>. Sign in with the account that manages the page."
   - Page name read from the organization lookup (`/rest/organizations/{id}`, localizedName); if
     that call is refused, use the id as the name and warn.
6. Only now save: the key (encrypted, in the existing ConnectedAccount `access_token`),
   `token_expires_at = now + expires_in`, status Connected, `meta` = {via: oauth, connected_at,
   scopes, role}. `updateOrCreate` on (brand, linkedin_org, org id). A failed step never touches a
   key that already works.
7. Redirect to `http://127.0.0.1:{port}/{brand}/linkedin` with a green "Connected: <page>, key valid
   until <date>" or the red reason.

### 7.4 Daily check and expiry

- `smm:check-accounts` gets a LinkedIn step next to the Meta one: one organizationAcls call with
  the saved key. 401 → account Error "LinkedIn no longer accepts the key: press Reconnect LinkedIn"
  (notification once, `error_from=linkedin_key`); page no longer administered → Error with that
  reason; no connection or 5xx → nothing changes; works → clears only an error it set itself.
- Expiry warnings: the existing ones (`AccountHealth`, `token_expiry_warning_days` = 7, Expiring
  then Expired, one notification per change). Claude's call: keep 7 days for LinkedIn too; the
  tracker reminds two weeks ahead as well. No new mechanism.

### 7.5 Errors and wording

A `LinkedInError` with plain messages like `MetaError` (no connection; LinkedIn's own trouble;
key refused; daily limit reached, try tomorrow; version no longer supported, update the app).
Errors never contain the key, the code or the secret. The certificate fix from SM-008
(`Http::globalOptions` verify) already covers api.linkedin.com.

### 7.6 Testing

- Feature tests with `Http::fake` for: start (state, URL, scopes, localhost), callback success,
  cancel, wrong/old/reused state, missing required scope, not an administrator, other page only,
  token exchange refused, reconnect keeps the old key on failure, secrets absent from the page
  HTML and logs, daily check outcomes.
- Real check in Dev: needs a second redirect address in the portal,
  `http://localhost:8091/linkedin/callback` (owner adds it). The owner presses Connect in Dev and
  signs in; then the same in App after the merge. No post is made in this step.

### 7.7 Docs and tracker

DEVELOPMENT_LOG entry SM-011, HOW_TO_USE section "LinkedIn: connect and every 2 months",
tracker #19 note and How-to card, local copy, Tracker Summary.

## 8. Branch and order

New branch `feat/sm-011-linkedin-connect` from `origin/main`, in its own worktree, so the
unpushed SM-010 work in Dev is untouched. Both change `CheckAccountsCommand` and the navigation;
whichever merges second resolves a small conflict. Target: before the first LinkedIn post work.

## 9. Left to the builder

Filament route placement for `start` (tenant) and `callback` (non-tenant, still behind
EnsureLocalRequest and auto-login), the exact settings keys, the page's Blade layout (copy
meta-settings), the organization name lookup call shape, and whether a token introspection endpoint
exists (if it does and is documented, it may replace the organizationAcls call in the daily check).
