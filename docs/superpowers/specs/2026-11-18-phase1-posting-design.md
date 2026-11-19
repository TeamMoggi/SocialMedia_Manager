# Phase 1 posting: design (plan 2)

**Date:** 18 Nov 2026 · **Status:** approved by the owner, 19 Nov 2026
**Covers tracker tasks:** #11 (Hostinger folder), #18 (connect Facebook + Instagram), #20 (media library), #21 (composer), #22 (approval), #23 (calendar), #24 (publisher), and the start of #26 (pilot).
**Amends `docs/PROJECT_PLAN.html` (v1.0):**
- The first milestone moves from 20 Dec to **6 Dec 2026**.
- The Hostinger "photo relay" carries **photos and videos**.
- The media library reads the owner's **Markting Social Media** folder instead of holding its own copies.

---

## 1. Intent and why

Moggies International's Metricool trial ends around 29 Nov 2026. The owner wants their own app (SocialMedia Manager, foundation live since 16 Nov) to take over posting to the **Facebook Page "Moggies International"** and **Instagram `moggies_international`**:

- Every post is written once, approved against the brand rules, and published on time by itself.
- The app runs on the owner's laptop.
- Meta Business Suite covers the gap between the trial's end and this build going live.

The owner also wants **one place for all Moggies photos and videos that Claude can search and pick from in future work**, such as posts, the website and the Instagram plan. The owner's words: "storage for photos, videos where you can pick for your task in future".

The owner already works in `D:\Pravin\Moggi\Markting Social Media` and said "you can use it". About 873 MB of material is spread over folders like these:

- **Numbered folders** ("5", "10", …), each holding one video project. A typical project has:
  - AI-made images (ChatGPT, Gemini)
  - Hindi voice-overs (.wav)
  - music (.mp3)
  - scripts (.docx, .txt, .md)
  - a Filmora project file (.wfp)
  - the finished .mp4
- **Named folders:** "GM", "NRI target", "Second", "Third", "Via Sohar", "Music", "CD AI", …

## 2. Goals (all ready by Sun 6 Dec 2026)

1. **Connect** the Facebook Page and its linked Instagram account from the app (#18).
2. **Hostinger folder** (#11): a hidden, locked folder on moggiesinternational.com. Instagram downloads photos and videos from it. Each file is deleted right after use.
3. **Media library** (#20):
   - It reads Markting Social Media where it is.
   - Each file gets labels and a description.
   - It checks each file against each network's rules.
   - Claude can search it from a laptop session.
4. **Composer** (#21): one post with a version per network, previews and counters. The post types are:
   - Facebook: text, link, photo(s), video
   - Instagram: single photo, carousel, Reel, Story (plain photo or video)
5. **Approval** (#22) with the brand-rules checklist. Nothing publishes without it.
6. **Calendar** (#23): week and month views; drag a post to change its time.
7. **Publisher** (#24):
   - Facebook posts use Facebook's own scheduling.
   - Instagram posts go through the Hostinger folder at the set time.
   - Up to 3 retries over about 15 minutes.
   - A Missed list for posts the laptop couldn't send in time.
   - Windows pop-ups.
   - One-time wake-ups so the app doesn't have to stay running.
8. **Rehearsal ("Test send")**: sends a real photo and a real video through every step to Instagram and Facebook, stops just before publishing, and reports each step. Nothing appears on the accounts.

## 3. Non-goals (not in this build)

- LinkedIn (#19, waits on LinkedIn's approval #10).
- Analytics and the Metricool import (#27, #28).
- Comments inbox (#34), caption templates (#36), AI caption helper (#37), Claude connector (#38).
- Instagram music, stickers, collab posts, product tags. Facebook Reels and Stories.
- Editing photos or videos in the app. The owner keeps editing in Filmora or other tools.
- Hosted mode or login (Phase 4).

## 4. Anti-goals (failure even if it "works")

- A post goes live **without the owner's approval**.
- A post goes out **late without asking**. If the laptop was off at post time, the post waits on the Missed list.
- **Anything becomes public from the Dev copy.** Dev is always dry-run; a rehearsal never publishes.
- The app **changes, moves, renames or deletes anything** in Markting Social Media.
- An **administrator prompt for each post**, or an always-running process that slows the laptop.
- The App Secret, tokens or the FTP password appearing in chat, GitHub, logs or backups outside the encrypted zip.
- Files **left behind** in the Hostinger folder, or the folder being listable or indexed.
- Unofficial tools or APIs.

## 5. Constraints (facts the builder must work within)

### App today (main = 3ad11fd)
- **Stack:** Laravel 13, Filament 5, SQLite, PHP 8.5 on Windows 11 Home, 8 GB RAM.
- **Runs on demand (SM-007):**
  - The desktop shortcut runs `start.ps1`.
  - `smm:serve` stops the app after 30 minutes without use.
  - One Windows task, `\SocialMediaManager\Daily`, runs `smm:daily` at 09:00. It is S4U, StartWhenAvailable, never wakes the laptop, and has a 1-hour limit.
  - Registering an S4U task needs an administrator prompt (`install-tasks.ps1`).
- **Copies and data:**
  - App lives in `App` (port 8090, the owner's copy) and `Dev` (port 8091, dry-run, data in `DevData`).
  - `PublishGuard` allows live posting only from the folder named `App`.
  - The data folder is found by `DataDir` (`.data-dir`).
  - **Every nightly backup zips the database, `.env` and the whole `Data\media` folder** (AES-256, 14 kept). So the library must not copy the 873 MB folder into `media`.
- **Existing pieces:**
  - `ConnectedAccount`: encrypted tokens, `meta` JSON, unique (brand, network, external_id).
  - `Network` enum: `facebook_page`, `instagram`, `linkedin_org`.
  - `AccountStatus` and `AccountHealth`.
  - `smm:check-accounts`, which runs daily.
  - `Setting::putSecret` / `getSecret`, encrypted with the app key.
  - `EnsureLocalRequest`, which accepts the hosts 127.0.0.1, localhost and ::1.
- **Brand timezone:** Asia/Kolkata. Show all times in it.
- **PHP extensions present:** curl, fileinfo, ftp, gd, intl, mbstring, openssl, pdo_sqlite, sodium, zip.
- **No ffmpeg** on the laptop.

### Meta (set up 16 Nov 2026)
- **App:** "Moggies Social Manager", App ID `2181731786035384`, **Development mode**. Only people with a role on the app can use it, so no App Review is needed. It goes Live in part 1 (see "Development mode hides posts" below).
- **Use cases:** "Manage everything on your Page" and "Manage messaging & content on Instagram", set up with **Facebook Login**, which Meta now labels "legacy".
- **Permissions ready:**
  - business_management, pages_show_list, public_profile
  - pages_manage_posts, pages_manage_engagement, pages_read_engagement, pages_read_user_content, read_insights
  - instagram_basic, instagram_content_publish, instagram_manage_insights, instagram_manage_comments
- **Sign-in redirect:** not used. The app uses a permanent system-user key (§7). The list of valid redirect addresses stays empty.
- **Page:** "Moggies International", id **1510742765451658**, created 16 Nov.
  - The owner's Facebook profile "Moggies In" is its admin and the app's admin.
  - Find the Page through `me/accounts`. The id 1503120716208596 in older notes is a personal profile and **must not** be used.
- **Instagram:** a Business account linked to that Page.
- **Instagram publishing:**
  - `image_url` and `video_url` must be **public addresses**, which is why the Hostinger folder exists.
  - There is no native scheduling: the app must publish at the time itself.
  - Containers expire after 24 hours.
  - Video containers must be checked until their status is FINISHED.
  - The limit is 100 API posts per account per 24 hours.
- **Facebook publishing:**
  - Page access token.
  - Photos and videos upload straight from the laptop (multipart), with no Hostinger folder.
  - Posts can carry a scheduled publish time inside a window Meta sets.
- **The App Secret never passes through chat.** The owner types it into the app.
- **Development mode hides posts.** Meta's app-modes page says "Any data generated while an app is in Development mode, such as test posts, can only be seen by role users".
  - The app must be switched to **Live** before the first real post.
  - For our own Page and Instagram account, Standard Access is enough. It is approved automatically, with no App Review.
  - A Live app needs a privacy policy address. One already exists at https://moggiesinternational.com/privacy-policy/.
  - In Live mode, `http://localhost` sign-in addresses are no longer exempt from Meta's HTTPS rule.
- **Instagram's own AI label:** set `is_ai_generated=true` on the media container (Meta changelog, 22 Jun 2026). For a carousel, set it on the parent container only. Third-party sources say the label can't be added after publishing.
- **Known risk:** a Meta developer-community thread reports Instagram publishing "Authorization Error"s on Live apps from 23 May 2026. The rehearsal on a Live app must show whether this affects us.

### Hostinger
- moggiesinternational.com is a WordPress site on Hostinger (account `u946790164`).
- The folder sits under `public_html` with an FTP account locked to that folder only.
- **Only that folder may be touched.**

### Working rules
- Work in `Dev` on a branch. The owner approves each commit and merges each pull request.
- After merging, Claude updates `App`: pull, composer, migrate.
- Each part updates:
  - `docs/HOW_TO_USE.html`
  - `docs/DEVELOPMENT_LOG.md` (SM-008, SM-009, SM-010)
  - the tracker artifact (https://claude.ai/artifact/Vq8Q6jpWRxQ645PNxv7PDp)
  - `docs/PROJECT_PLAN.html` (the amendments above, in part 1)
- Headless browser checks: close the browsers and their temporary profiles afterwards.

## 6. Approaches considered

**Delivery: three parts or one build?**
- **A. Three parts, each merged and live when ready (chosen; Claude's call, and the owner raised no objection).** The riskiest pieces are proven in week 1: Meta sign-in, the Hostinger folder, videos, and the wake-ups. A slip in the last part doesn't hold back writing and approving posts. The cost is three merges for the owner.
- **B. One build, one merge around 3 Dec.** Fewer merges, but real accounts would be touched for the first time three days before the deadline.
- *Dropped:* a quick "basic posting first" version. The owner wants everything together by 6 Dec.

**Where the library lives**
- **On the laptop, inside the app, reading Markting Social Media (chosen by the owner):** "usually I use Markting Social Media folder… you can use it".
- *Dropped:* a shared Google Drive folder. New material is made on the laptop, not on phones.
- *Dropped:* a library on Hostinger. It's the website's server, would need its own lock, and would use the site's space. Phase 4 can move the library along with the app.

**Read the folder in place or copy it in? (Claude's call)**
- **In place, read-only (chosen).** The owner keeps working in the folder as before, and new files show up automatically. Nightly backups don't grow by 873 MB.
- Only a file actually attached to a post is copied into `Data\media\posts\<post id>\`. A later edit or move of the original can't change a scheduled post, and the posted version is kept in history and backups.

**How the app gets access to Meta (the owner's choice, 18 Nov)**

Public Facebook posts need the Meta app in **Live** mode, and in Live mode Meta no longer exempts `http://localhost` sign-in addresses from its HTTPS rule.
- **A permanent system-user key (chosen).** It takes about 20 minutes of one-time setup in Meta's business settings, and the owner pastes the key into the app. There's no reconnecting every couple of months and nothing new on the website, and it's the direction Meta recommends. The key is only made once the app can store it.
- *Dropped:* the Facebook Login button. It needs a small HTTPS hand-back page on moggiesinternational.com, and the owner would reconnect about every two months.

**How Instagram gets videos (owner's decision)**
- **Through the Hostinger folder, the same as photos (chosen):** one route for every Instagram file.
- *Dropped:* Meta's resumable upload. It works for videos without a public address, but only with Facebook Login, and it is a second code path.

## 7. The design

### Part 1: Connect, Hostinger folder, rehearsal (SM-008, branch `feat/sm-008-meta-connect`)

**Meta app settings (new screen).**
- App ID, prefilled with 2181731786035384.
- App Secret, typed in by the owner and stored with `Setting::putSecret`. It is never shown back, only "saved on <date>".

**Meta access: a permanent key (the owner's choice, 18 Nov).** There is no sign-in button and no Meta redirect, so the app needs no `localhost` callback and no host switch.

- **Owner, once, with Claude's step-by-step help (from 18 Nov).** In the business portfolio "Moggies Social Manager" (business_id 2276376376293123, which owns the Meta app):
  1. Add the Page and the Instagram account.
  2. Create a system user.
  3. Give that system user:
     - the Page with **full control** (changed 19 Nov 2026: the Test send showed Meta lets only admin-level access upload Page videos, error 200 "sufficient administrative permission"; the owner chose full control over Reels-only)
     - Instagram, limited to content, community activity and insights (no ads, no messages, no full control)
     - the Meta app
- **Owner, during part 1, once the app's Meta screen exists:**
  1. Switch the Meta app to **Live**. The privacy policy is https://moggiesinternational.com/privacy-policy/. Anything else Meta asks for at that moment, such as a data-deletion address or an icon, is handled then. If the privacy page needs new wording, it goes through the website's own branch-and-merge routine.
  2. Generate the system user's key, with **no expiry** and only the permissions listed in §5.
  3. Paste the key and the App Secret into the app. Both are stored with `Setting::putSecret` and shown back only as "saved on <date>". The key is never written anywhere else.
- **What the app does with the key:**
  1. It reads `me/accounts` to get the Page and its Page token.
  2. It reads the Page's `instagram_business_account` to find Instagram.
  3. It saves **two ConnectedAccounts**: the Facebook Page, holding the Page token, and Instagram, holding the IG user id and using the Page token.
  4. Pasting a new key updates them in place.
- **Daily check:** `smm:check-accounts` checks the key with Meta's debug endpoint, using an app token built from the App ID and App Secret. If the key stops working, the accounts show Error with a plain "make a new key" message.
- **Visible test post (the owner agreed, 18 Nov).** After the switch to Live, the app makes one real **Facebook text post**.
  1. It checks that a signed-out browser can see the post.
  2. It deletes the post within minutes.
  3. It reports the result.

  This must run from App, because of `PublishGuard`. The builder chooses whether it's a one-off command or a button.

**Hostinger folder (done with the owner, about 10 minutes, during part 1).**
- In hPanel, the owner creates an FTP account whose home is one new folder under `public_html` with a random-looking name.
- Claude writes that folder's `.htaccess`:
  - no directory listing
  - `X-Robots-Tag: noindex, nofollow`
  - no script execution
- An app settings screen holds the host, user, password (stored as a secret), folder and public base address.
- The **"Test the folder"** button:
  1. uploads a tiny random-named file
  2. fetches it over HTTPS from the public address
  3. deletes it
  4. confirms it is gone
- The upload uses Laravel's FTP storage. Use encrypted FTP if Hostinger supports it.

**Rehearsal ("Test send").** The owner picks one photo and one video from Markting Social Media. In part 1 a simple file picker is enough; part 2 replaces it with the library.
- **Instagram:**
  1. Upload to the folder with a random name.
  2. Create the container (IMAGE, or REELS for the video).
  3. Poll until FINISHED.
  4. **Stop. Never call publish.**
  5. Delete the files from the folder.
- **Facebook:**
  1. Upload the photo and video with `published=false`.
  2. Read them back.
  3. Delete them.
- Each step is shown as passed or failed in plain words.
- Allowed in Dev and App, because nothing becomes public. Real publishing stays App-only.
- **Dev is connected to the real accounts too (Claude's call).**
  - The owner enters the Meta and Hostinger secrets in both copies.
  - Claude runs "Test the folder" and rehearsals in Dev, so part 1 is proven against Meta and Hostinger before its pull request.
  - DevData then holds a real token. `PublishGuard` (Dev is always dry-run) is what stops it from posting, so Dev's tests must cover that guard on every publish path.

**Wake-up trial.** Before part 3 is planned, find out what this laptop allows **without an administrator prompt for each post**:
1. Can the app, running as the owner, create or update a one-time task that wakes the laptop from sleep and runs while the owner is signed out or the screen is locked?
2. If not, can a standing task, installed once with one admin prompt, have its trigger times rewritten by the app? Its task folder could grant the owner write access.

Record the result in the build notes; part 3 uses it.

**Plan amendments:** update `PROJECT_PLAN.html` with the three amendments at the top of this document.

### Part 2: Library, composer, approval, calendar (SM-009)

**Library.**
- **Source folders:** a brand setting, defaulting to `D:\Pravin\Moggi\Markting Social Media`. The owner can add more later, such as Products or Photos. The Website folder's backups are left out.
- **Strictly read-only access** to source folders.
- **Scans:**
  - when the app opens, without blocking the screen
  - in `smm:daily`
  - from a "Look for new files" button
- A file is new or changed when its path, size or modified time changes. A file that disappears is marked missing, not deleted. Its labels return if the same content shows up again elsewhere (a content hash for new or changed files only).
- **Indexed files:**
  - photos (jpg, jpeg, png, webp) and videos (mp4, mov, webm), which can be attached to posts
  - voice-overs and music (wav, mp3) and scripts (docx, txt, md, rtf), listed so Claude can find a video project's parts, but **not** attachable to posts
  - other files (zip, wfp) ignored
- **Groups:** each folder is a group, named after the folder at first. The owner or Claude can give it a proper name, such as "5" becoming "Customer outside video, June 2026".
- **Labels per file:**
  - product range
  - kind (finished video, clip, photo, AI image, voice-over, music, script)
  - **AI-made** yes/no
  - **shows people** yes/no. This is Claude's call, because the brand rule is about AI-made *people*, not every AI image.
  - a short description
  - "used in posts", filled in automatically
- First labels come from folder and file names; descriptions can be filled in over time.
- **Facts read per file:** width, height, size, duration and format. Images use GD. Videos have no ffmpeg, so the builder chooses a pure-PHP reader.
- **Thumbnails:** photos get a GD thumbnail cached outside `media`, so it isn't backed up. Videos use the browser's own preview.
- **Checks per network** are shown as badges, with the limits taken from Meta's current documentation at build time: aspect ratios, size, duration, Story shape.
- **Claude's access:** `php artisan smm:media:find <words>` searches groups, labels and descriptions. It prints matching files with their full paths and facts, so Claude can pick files in a laptop session. The Claude connector (#38) comes later.
- **Claude's labelling pass (the owner agreed, 18 Nov).** Claude may write labels into **App's** database. A second command (for example `smm:media:label <file.json>`) imports a labels file that Claude prepares.
  - It changes only group names, labels and descriptions, and never touches posts, settings or source files.
  - Every change goes into the activity log as "by Claude".
  - Photos are labelled by looking at them; videos from their project's scripts and file names.
  - The pass starts after part 2 is merged and carries on over time. It isn't part of the 6 Dec target.

**Composer.**
- A post has a main caption, a version per chosen network (type, caption override, media in order, link for Facebook), and a time in the brand's timezone.
- Attaching a library file copies it into `Data\media\posts\<post id>\`.
- **Previews:** an approximate preview per network, plus counters (caption length, hashtags, mentions) and the media checks. Hard failures block approval; soft ones warn.
- **Separate parts per network:** each network's version is its own record (a "post target"). Facebook and Instagram are sent, can fail and can be retried separately.

**Approval.**
- States: Draft → Waiting for approval → Approved → Handed to Facebook or Queued → Published, Failed, or Missed.
- The checklist is the brand rules from the plan:
  - no real people's faces
  - AI-made people labelled
  - only approved figures and wording, for example "since 2021", "3 shipments every 2 months", "ready to ship to any country" with no country list
- The checklist items are a brand setting.
- **AI label (the owner agreed, 18 Nov).** The rule applies when any attached file is marked **AI-made and shows people**:
  - The Instagram container gets `is_ai_generated=true`, which is Instagram's own label.
  - The Facebook caption automatically ends with the line **"Visuals made with AI"**.
  - The preview shows both. The owner can't remove them while the rule applies.
  - AI-made files without people get no label.
- The owner ticks every item, then presses Approve. Who approved and when goes in the activity log.
- **Editing the content or media of an approved post** sends it back to "Waiting for approval", and any Facebook hand-over is withdrawn.
- **Changing only the time** (for example, a calendar drag) keeps the approval. This is Claude's call.

**Calendar.**
- Week and month views, with posts coloured by state.
- Drag to change the time. For a post already handed to Facebook, this updates Facebook's scheduled time.
- FullCalendar, as the plan says. If no Filament 5 plugin works, embed FullCalendar directly with a pinned version.

### Part 3: Publisher (SM-010)

**Facebook.**
- When a post is approved and its time is inside Meta's scheduling window, hand it to Facebook with a scheduled publish time. Upload files straight from `Data\media\posts`.
- If the time is beyond the window, `smm:daily` hands it over once it falls inside.
- If the time is too soon for the window, publish it at the time, the same way as Instagram.
- Store Facebook's ids. Rescheduling updates; unapproving deletes.

**Instagram.**
- At *post time minus a lead time* (longer for videos, which need processing):
  1. Upload the file(s) to the Hostinger folder.
  2. Create the container(s) and poll until FINISHED. Carousels need child containers first. Stories use `STORIES`; Reels use `REELS`.
- At the post time:
  1. Publish.
  2. Confirm the post exists and save its link.
  3. Delete the folder files.
- Files are also cleaned out of the folder after a failure or a Missed post.

**Shared by both networks.**
- **Retries:** for temporary errors, up to 3 retries over about 15 minutes. Permanent errors (permissions, invalid media) fail at once with a plain message.
- **Missed list:** if the app wasn't running at the post time and the retry window has passed, the post goes to **Missed**. It is never sent late without asking. The owner chooses "Post now" or a new time.
- **Wake-ups:** each queued post gets a Windows wake-up at its start time, using the mechanism the part-1 trial chose.
  - There's no admin prompt per post.
  - The laptop must be on or asleep. If it's off, the post goes to Missed.
- **Process:** publishing runs as its own command (`smm:publish-due`), never inside a web request or behind a backup. The wake-up and opening the app both run it. This closes the deferred item about long jobs sharing one process.
- **Pop-ups:** Windows notifications for published, failed and missed posts (when the owner is signed in), plus in-app notices.
- **Dev:** dry-run simulates each step and logs it. It never calls a publish endpoint.
- **Activity log:** every approval, hand-over, attempt and result.

**Pilot (#26) start:**
- The first real approved post goes to both networks on **Fri 4 Dec**, with the owner at the laptop.
- After that, two weeks of real scheduling.
- Meta Business Suite stays as the fallback until the pilot passes, around 18 Dec.

## 8. Data (the builder refines the details)

- `media_groups`: brand, source folder, relative folder, display name.
- `media_items`:
  - brand, group, relative path, kind, mime
  - bytes, width, height, duration, modified time, content hash
  - labels, description, ai_made, missing_since
- `posts`: brand, main caption, state, scheduled_at (UTC), approved_at, approved_by, checklist ticks.
- `post_targets`:
  - post, connected account, post type, caption override, state
  - external ids (Facebook post or video id; Instagram container and media ids), permalink
  - attempts, last_error, published_at
- `post_media`: post, media item, position, copied file path.
- `activity_log`: brand, subject, action, details, at.

## 9. Dates

| When | What | Who |
|---|---|---|
| Wed 18 – Thu 19 Nov | This design approved, then the build plan | Owner, Claude |
| by Mon 23 Nov | Part 1 pull request; the Hostinger folder set up together; rehearsal passes on App | Claude, owner (folder, merge, App Secret) |
| by Sat 28 Nov | Part 2 pull request: the owner can write and approve December posts | Claude, owner (merge) |
| by Thu 3 Dec | Part 3 pull request: automatic posting | Claude, owner (merge) |
| Fri 4 Dec | First real approved post on Facebook and Instagram | Owner at the laptop |
| Sun 6 Dec | Everything ready | |

- The owner merges each pull request within about a day.
- If part 3 is at risk, Claude says so by Tue 1 Dec.

## 10. Owner's tasks in this build

- In Meta's business settings, with Claude: add the Page and Instagram to the portfolio, create the system user, and give it the Page, Instagram and the app (about 20 minutes, from 18 Nov).
- In part 1: switch the Meta app to Live, generate the key, and paste the key and the App Secret into the app (both copies). Never paste them into chat.
- Set up the Hostinger FTP account and folder with Claude (about 10 minutes, part 1).
- Merge each of the three pull requests.
- Be at the laptop for the first real post on Fri 4 Dec.
- Add Markting Social Media to the weekly USB copy. The app's backup doesn't include it; only files used in posts are backed up.

## 11. Left to the builder

- Meta's current limits and specs: media sizes, ratios and durations; the scheduling window; carousel size; token and data-access expiry. Read them from Meta's documentation at build time.
- The pure-PHP video reader, and thumbnail details.
- The wake-up mechanism, from the part-1 trial, within the rule of no admin prompt per post.
- FullCalendar integration with Filament 5.
- Encrypted FTP or plain FTP, depending on Hostinger.
- **Backup growth.** Post media (videos especially) goes into every nightly zip. Measure it after part 3 and propose a fix if 14 copies grow too large, such as keeping media out of the daily zip and backing it up once.
- Screen layout and naming within Filament's conventions.
