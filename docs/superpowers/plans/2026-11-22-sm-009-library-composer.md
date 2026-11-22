# SM-009 Library, composer, approval, calendar: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The owner can find any photo or video in Markting Social Media inside the app, write one post with a Facebook and an Instagram version, approve it against the brand rules, and move it on a week or month calendar. Claude can search and label the library from a laptop session. Nothing is published yet: publishing is part 3 (SM-010).

**Architecture:**
- **Library:** `LibraryScanner` walks each brand source folder **read-only** and keeps `media_items` (facts, labels, missing flag) and `media_groups` (one per folder).
  - Facts come from `MediaFacts`: GD `getimagesize` for photos, getID3 for videos, plus a small `Mp4Atoms` check for "moov at the front".
  - Thumbnails are cached in `<data>/thumbs`, which the backup does not include.
- **Posts:** a `Post` has one `PostTarget` per network and an ordered list of `PostMedia`. Attaching a library file copies it into `<data>/media/posts/<post id>/`, and PNG or WebP becomes JPEG on the way.
- **Rules and approval:** `MediaRules` turns Meta's limits into block/warn `Problem`s. `Approval` owns every state change (submit, approve, withdraw, reopen on change, reschedule) and writes `activity_log`.
- **Screens:** Filament resources (Library, Folders, Posts) and a Calendar page that embeds FullCalendar 6.1.15, vendored into `public/vendor`.

**Decisions this plan makes where the spec leaves a choice** (the owner confirms them with the plan):
- **Media list:** a post has **one ordered media list**, and each network version uses what its type needs. A Facebook text or link post sends none; an Instagram Story sends one item. The spec's "media in order" per version is met by the shared order, and per-version lists would be extra work no post needs yet.
- **Formats:** PNG and WebP photos are saved as **JPEG** when attached, because Instagram takes only JPEG. This is a format change, not editing.
- **AI files:** a file marked AI-made must have "shows people" answered (yes or no) before its post can be approved. That answer decides the AI label.
- **Checklist:** the default approval checklist is the spec's three rules plus two from the Instagram plan's "What we must not post" (buyer documents and the EIA report; no promise to find buyers).
- **Video reader:** getID3 is the pure-PHP video reader. FullCalendar is embedded directly (one pinned file), with no Filament plugin, so a plugin's Filament 5 support can't break the calendar.

**Tech Stack:** Laravel 13, Filament 5, PHP 8.5 (Windows), SQLite, GD. New: **`james-heinrich/getid3` ^1.9** (pure-PHP video reader; there is no ffmpeg on the laptop; the owner approves this dependency with the plan), and **FullCalendar 6.1.15** standard bundle (MIT, one file, vendored).

**Spec:** `docs/superpowers/specs/2026-11-18-phase1-posting-design.md` (approved 19 Nov 2026), §7 "Part 2", §8 Data, plus the §4 anti-goals and §5 working rules.

## Global Constraints

- **Source folders are read-only.** Nothing writes, renames, moves or deletes anything in a source folder. Tests check that file lists and modified times are unchanged.
- **Media storage:**
  - Only files attached to a post are copied, to `<data>/media/posts/<post id>/<position>-<8 hex of hash>.<ext>`.
  - Thumbnails go to `<data>/thumbs/<item id>-<first 8 of hash>.jpg`, outside `media`, so they are not backed up.
- **Meta's limits** (Graph API v27.0 docs, read 22 Nov 2026: ig-user/media, content-publishing, page/photos, page/feed). Keep them in `config('smm.limits')`.
  - **Instagram photo:** JPEG only, ≤ 8 MB, aspect ratio 4:5 to 1.91:1 (0.8 to 1.91), width 320 to 1440 (scaled by Instagram outside that, so warn only).
  - **Reel:** MP4 or MOV, moov atom at the front, H.264 or HEVC (`avc1` `avc3` `hvc1` `hev1`), 23 to 60 fps, width ≤ 1920, ratio 0.01 to 10 (9:16 recommended), 3 s to 15 min, ≤ 300 MB.
  - **Story:** photo JPEG ≤ 8 MB, 9:16 recommended. Video as a Reel but 3 to 60 s, ≤ 100 MB, ratio 0.1 to 10.
  - **Carousel:** 2 to 20 items, photos and videos mixed. Every item is cropped to the first item's shape.
  - **Instagram caption:** ≤ 2,200 characters, ≤ 30 hashtags, ≤ 20 @mentions.
  - **Facebook photo:** jpeg, png, gif, bmp or tiff, ≤ 10 MB.
  - **Facebook video:** Meta's upload list names mp4. Meta states no size or length limit, so none is enforced.
- **Brand rules:**
  - More than 5 hashtags is a warning (Instagram plan: "up to 5 specific hashtags").
  - The default approval checklist (config `smm.approval_checklist`, editable per brand) has these five items:
    1. "No real people's faces (hands, voice-overs and text on screen are fine)"
    2. "AI-made people are labelled (the app adds Instagram's AI label and 'Visuals made with AI' on Facebook)"
    3. "Only approved figures and wording: since 2021, 3 shipments every 2 months, ready to ship to any country (no list of countries); export figures checked against APEDA"
    4. "No buyer names, labels or documents without the buyer's OK, and never the EIA test report"
    5. "Export services: no promise to find buyers"
- **AI label:** it applies when any attached file has `ai_made = true` and `shows_people = true`. The Facebook final caption then ends with `"\n\nVisuals made with AI"`, and the Instagram target carries the AI flag for part 3. Neither can be removed while the rule applies.
- **Approval:**
  - Changing the content (caption, a target's type, caption or link, or the media or its order) of an approved post sends it back to **Waiting for approval**.
  - Changing only the time keeps the approval.
  - Times are stored in UTC and shown in the brand's timezone (Asia/Kolkata).
- **No Meta or website calls in this part.** `Http::preventStrayRequests()` stays in `TestCase`. There is no publishing: post states end at Approved, and target states stay `waiting`.
- **Plain words:** user-facing text is plain English, with no API words.
- **Windows scripts:** PowerShell 5.1, ASCII only. Run PHP with `Start-Process` and `-RedirectStandardOutput` / `-RedirectStandardError`.
- **Git:** branch `feat/sm-009-library-composer` in `Dev`, one commit per task. Messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Write the message to a file and use `git commit -F <file>`, because PS 5.1 mangles quotes. No push until the owner says so.
- **Tests:** `php artisan test` in Dev. Headless Edge checks use a fresh profile, and the browser is closed and the profile deleted afterwards.

## Review Focus

1. **A file is renamed or moved in Markting Social Media after it was labelled or attached.**
   - The post keeps its own copy.
   - The library marks the old path missing.
   - When the same content appears at the new path, it keeps its labels (matched by hash).
   - → test in Task 3 (`test_moved_file_keeps_labels`) and Task 6 (`test_post_copy_survives_source_rename`).
2. **The D: drive or a source folder can't be reached during a scan.** No item from that folder is marked missing, and the report names the folder. → test in Task 3 (`test_unreachable_source_marks_nothing_missing`).
3. **A file that can't be read or is still being written** (for example a Filmora export in progress). It is skipped with a note and picked up by a later scan; the scan never fails. → test in Task 3 (`test_unreadable_file_is_skipped_and_retried`).
4. **A very large AI image** (for example 8000×6000 PNG) on an 8 GB laptop. No thumbnail is made above 40,000,000 pixels; the screen shows a placeholder, and no out-of-memory error stops the page. → test in Task 4 (`test_huge_image_gets_no_thumbnail`).
5. **A post near midnight IST.** 4 Dec 00:30 IST is 3 Dec 19:00 UTC: the calendar shows it on 4 Dec, and dragging it keeps brand wall-clock time. → test in Task 10 (`test_events_use_brand_wall_clock_near_midnight`).

---

### Task 1: Video and photo facts (getID3 + moov check)

**Files:**
- Modify: `composer.json`, `composer.lock` (`composer require james-heinrich/getid3:^1.9`)
- Create: `app/Domain/Library/MediaFacts.php`, `app/Domain/Library/Mp4Atoms.php`, `tests/Support/Mp4Fixture.php`
- Test: `tests/Unit/MediaFactsTest.php`

**Interfaces:**
- Produces:
  - `MediaFacts::read(string $path): array{mime: ?string, width: ?int, height: ?int, duration: ?float, codec: ?string, fps: ?float, faststart: ?bool, error: ?string}`.
    - Photos: `getimagesize`.
    - Videos (mp4, mov, webm): getID3 `analyze()`, reading `video.resolution_x/_y`, `playtime_seconds`, `video.frame_rate` and the fourcc from `video.fourcc` or `quicktime` stsd.
    - Never throws: a failure fills `error` in plain words.
  - `Mp4Atoms::moovFirst(string $path): ?bool`. It walks the top-level boxes only (8-byte header; size 1 means a 64-bit size follows; size 0 means "to end of file"). It returns `true` if `moov` comes before `mdat`, `false` if after, and `null` if neither is found or the file can't be read.
  - `Tests\Support\Mp4Fixture::make(string $path, int $width, int $height, float $seconds, int $fps = 30, string $codec = 'avc1', bool $moovFirst = true): void`. It writes a minimal ISO-BMFF file:
    - `ftyp`
    - `moov` → `mvhd`, `trak` → `tkhd`, `mdia` → `mdhd`, `hdlr` (`vide`), `minf` → `vmhd`, `dinf`, `stbl` → `stsd`, `stts`, `stsc`, `stsz`, `stco`
    - `mdat`

    If getID3 needs more boxes, add them to the fixture, not special cases to `MediaFacts`.

- [ ] **Step 1: Write the failing tests** in `MediaFactsTest`:
  - `test_jpeg_facts`: GD makes a 1080×1350 JPEG; `read()` gives width 1080, height 1350, mime `image/jpeg`, and duration null.
  - `test_mp4_facts`: `Mp4Fixture` 1080×1920, 12.0 s, 30 fps, `avc1`; `read()` gives width 1080, height 1920, duration within 0.1 of 12.0, fps within 0.5 of 30, codec `avc1`, faststart true.
  - `test_moov_after_mdat_is_reported`: fixture with `moovFirst: false` → faststart false.
  - `test_broken_video_gives_error_not_exception`: an `.mp4` containing `"not a video"` → `error` is not null, and width is null.
  - `test_names_with_spaces_and_hindi`: a JPEG at `.../a&b/फोटो !.jpg` reads correctly.
- [ ] **Step 2:** Run `php artisan test --filter=MediaFactsTest`. Expected: FAIL (classes missing).
- [ ] **Step 3:** `composer require james-heinrich/getid3:^1.9`, then implement `MediaFacts` and `Mp4Atoms` as above.
- [ ] **Step 4:** Run the filter again. Expected: PASS.
- [ ] **Step 5:** Commit `feat: read photo and video facts without ffmpeg (getID3, moov check)`.

### Task 2: Library tables, models, labels from names, activity log, brand settings

**Files:**
- Create:
  - Migrations: `database/migrations/2026_11_22_100000_create_media_tables.php` (`media_groups`, `media_items`), `2026_11_22_100100_create_activity_log_table.php`, `2026_11_22_100200_add_library_settings_to_brands.php` (`library_sources` JSON null, `approval_checklist` JSON null)
  - `app/Models/MediaGroup.php`, `app/Models/MediaItem.php`, `app/Models/Activity.php`
  - `app/Enums/MediaKind.php`, `app/Enums/ProductRange.php`, `app/Domain/Library/NameLabels.php`
- Modify: `app/Models/Brand.php`, `config/smm.php` (`approval_checklist`, `limits`, `thumbs_dir` = `<data>/thumbs`), `app/Filament/Pages/Tenancy/EditBrand.php`
- Test: `tests/Unit/NameLabelsTest.php`, `tests/Feature/LibraryModelTest.php`

**Interfaces:**
- **`media_groups`:** `brand_id`, `source_root`, `folder` (relative, forward slashes, `''` for the top folder), `name`. Unique (`brand_id`, `source_root`, `folder`).
- **`media_items`:**
  - `brand_id`, `media_group_id`, `source_root`, `relative_path`, `kind`, `product_range` (null), `mime`
  - `bytes`, `width`, `height`, `duration` (float), `codec`, `fps` (float), `faststart` (bool null)
  - `mtime` (int), `hash` (`xxh128`, null), `ai_made` (bool, default false), `shows_people` (bool, null = not answered), `description` (text null), `facts_error`, `missing_since` (datetime null)
  - Unique (`brand_id`, `source_root`, `relative_path`).
- **`activity_log`:** `brand_id`, `subject_type`, `subject_id`, `action`, `details` (JSON), `by`, `created_at`.
- **`MediaKind`:** `photo`, `ai_image`, `finished_video`, `clip`, `voice_over`, `music`, `script`.
  - Methods: `label()`, `isAttachable()` (photo, ai_image, finished_video, clip), `isVideo()`, `isImage()`.
  - It implements Filament `HasLabel` like `Network`.
- **`ProductRange`:** `raisins`, `frozen_vegetables`, `fruit_pulp`, `export_services`, `general`, with `label()` and `HasLabel`.
- **`MediaItem`:**
  - Casts: `kind`, `product_range`, `ai_made`, `shows_people`, `faststart`, `missing_since` (immutable_datetime).
  - Methods: `fullPath(): string` (`source_root` + `/` + `relative_path`), `isMissing(): bool`, `group(): BelongsTo`, `scopeAttachable(Builder $q)` (attachable kind and not missing). Task 6 adds `postMedia()`.
- **`Activity::record(Brand $brand, ?Model $subject, string $action, array $details = [], string $by = 'owner'): Activity`.**
- **`Brand`:**
  - Casts `library_sources` and `approval_checklist` as arrays.
  - Methods: `librarySources(): list<string>` (falls back to `[config('smm.library_source')]`), `approvalChecklist(): list<string>` (falls back to `config('smm.approval_checklist')`).
  - Relations: `mediaGroups()`, `mediaItems()`. Task 6 adds `posts()`.
- **`NameLabels::guess(string $relativePath, int $videosInFolder = 1): array{kind: MediaKind, product_range: ?ProductRange, ai_made: bool}`.** Rules, case-insensitive, on the whole relative path:
  - **AI words** `chatgpt|gemini|dall|midjourney|firefly|imagen|generated|\bai\b` → `ai_made` true.
  - **Range**, first match wins, in this order:
    1. `raisin|kishmish|kismis|sangli|malayar|manik` → raisins
    2. `pulp|mango|guava|papaya|pineapple|puree` → fruit_pulp
    3. `frozen|peas|sweet ?corn|okra|cauliflower|\bveg` → frozen_vegetables
    4. `export|service|iec|apeda|rcmc|\bnri\b|document|customs|exim|training` → export_services
    5. otherwise null.
  - **Kind:**
    - jpg, jpeg, png, webp → `ai_image` if AI words, else `photo`.
    - mp4, mov, webm → `finished_video` if the name matches `final|export|output|ready` or `$videosInFolder === 1`, else `clip`.
    - wav → `music` if `music|bgm|song`, else `voice_over`.
    - mp3 → `voice_over` if `voice|\bvo\b|narration|hindi`, else `music`.
    - docx, txt, md, rtf → `script`.
- **EditBrand:** adds `Repeater::make('library_sources')->simple(TextInput::make('path'))`. Each path must be an existing folder and must not be inside `app('smm.data_dir')`. It also adds `Repeater::make('approval_checklist')->simple(TextInput::make('item'))` (at least 1 item).

- [ ] **Step 1: Write the failing tests:**
  - `NameLabelsTest` uses data-provider cases taken from the owner's real names:
    - `CD AI/ChatGPT Image Jun 5, 2026.png` → ai_image, ai_made
    - `5/Sangli raisins final.mp4` → finished_video, raisins
    - `NRI target/voice hindi.mp3` → voice_over, export_services
    - `Music/track.mp3` → music
    - `10/frozen mango pulp.jpg` → photo, fruit_pulp
    - `GM/clip2.mp4` with 3 videos in its folder → clip
  - `LibraryModelTest`:
    - `test_brand_defaults_for_sources_and_checklist`: an empty brand → `librarySources()` gives the config path, and `approvalChecklist()` has 5 items.
    - `test_activity_record_saves_who_and_what`.
    - `test_attachable_scope_skips_missing_and_audio`.
    - `test_brand_settings_refuse_a_folder_inside_the_data_folder`: Livewire `EditBrand`.
- [ ] **Step 2:** Run both filters. Expected: FAIL.
- [ ] **Step 3:** Implement the migrations, enums, models, `NameLabels`, config, and the EditBrand fields.
- [ ] **Step 4:** Run the filters, then the full `php artisan test`. Expected: PASS (all earlier tests still green).
- [ ] **Step 5:** Commit `feat: library tables, labels from file names, activity log, brand library settings`.

### Task 3: Scanning the source folders (`smm:media:scan`)

**Files:**
- Create: `app/Domain/Library/LibraryScanner.php`, `app/Domain/Library/ScanReport.php`, `app/Console/Commands/MediaScanCommand.php`
- Modify: `app/Console/Commands/DailyCommand.php` (job `Library scan` → `smm:media:scan`, before `Backup`), `scripts/windows/start.ps1` (after the app answers: a hidden `Start-Process` of `php artisan smm:media:scan` with output to `<data>\logs\media-scan.log`)
- Test: `tests/Feature/LibraryScanTest.php`

**Interfaces:**
- Consumes: `MediaFacts::read`, `NameLabels::guess`, the models from Task 2.
- Produces:
  - `LibraryScanner::INDEXED = ['jpg','jpeg','png','webp','mp4','mov','webm','wav','mp3','docx','txt','md','rtf']`.
  - `LibraryScanner::scan(Brand $brand): ScanReport`.
  - `final readonly class ScanReport { int $added; int $changed; int $moved; int $missing; int $unchanged; array $unreadable /* relative paths */; array $unreachable /* source roots */; bool $skippedBusy }` with `summary(): string` (plain words).
  - Command `smm:media:scan {--brand=}`. It prints `summary()` per brand and always exits 0.
- **Algorithm:**
  1. Take an exclusive non-blocking `flock` on `<data>/run/media-scan.lock` (create `<data>/run` if needed). If it is busy, return a report with `skippedBusy` true.
  2. For each source root that `is_dir`, list files with `SourceFiles::list($root, INDEXED, 100000)`. A root that is not a folder goes into `unreachable`, and its items are left alone.
  3. For a known path: if size and mtime are unchanged, it is unchanged (clear `missing_since`). Otherwise re-hash, re-read the facts, and count it as changed.
  4. For a new path: hash it. If a missing item of this brand has the same hash, move that item to the new path, keeping its labels and description, and count it as moved. Otherwise create the item with `NameLabels` (passing the folder's video count) and `MediaFacts` (attachable kinds only) and count it as added.
  5. A file that can't be opened, or whose size changes between stat and hash, goes into `unreadable` and is not saved, so the next scan retries it.
  6. Items under a reachable root that were not seen get `missing_since = now()` if it is not set yet.
  7. Groups are created per folder, named after the last folder segment, or `Top folder` for `''`.

- [ ] **Step 1: Write the failing tests** (temp source folder built in `setUp`, like `RehearsalTest`):
  - `test_first_scan_adds_files_with_labels_and_facts`: 1 JPEG, 1 fixture MP4, 1 wav and 1 `.wfp`. Expected: 3 items (the wfp is ignored); the JPEG has width and height; groups exist.
  - `test_scan_never_changes_the_source_folder`: the list of paths, sizes and mtimes before equals after (the same pattern as `test_source_files_lists_postable_files_with_any_names`).
  - `test_second_scan_counts_unchanged`.
  - `test_moved_file_keeps_labels`: set a description, rename the file in the temp folder, rescan → moved 1, same item id, new path, description kept.
  - `test_deleted_file_is_marked_missing_not_deleted`.
  - `test_unreachable_source_marks_nothing_missing`: change the brand's sources to a folder that doesn't exist → missing 0, `unreachable` names it.
  - `test_unreadable_file_is_skipped_and_retried`: the test holds `flock($handle, LOCK_EX)` on a JPEG (Windows file locks are mandatory, so hashing it fails); scan → in `unreadable`, not in the db; release the lock and rescan → added.
  - `test_second_scan_while_one_runs_is_skipped`: hold the lock file → `skippedBusy`.
  - `test_daily_runs_the_library_scan`: `smm:daily` output contains the scan summary.
- [ ] **Step 2:** Run `--filter=LibraryScanTest`. Expected: FAIL.
- [ ] **Step 3:** Implement the scanner, report, command, daily job and `start.ps1` line.
- [ ] **Step 4:** Run the filter and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat: scan Markting Social Media read-only (new, changed, moved, missing)`.

### Task 4: Library screens, thumbnails, file preview, Test send from the library

**Files:**
- Create:
  - `app/Domain/Library/Thumbnails.php`, `app/Http/Controllers/LibraryFileController.php`
  - `app/Filament/Resources/MediaItems/MediaItemResource.php` + `Pages/ListMediaItems.php`
  - `app/Filament/Resources/MediaGroups/MediaGroupResource.php` + `Pages/ListMediaGroups.php`
  - `resources/views/filament/library/preview.blade.php`
- Modify: `app/Providers/Filament/AppPanelProvider.php` (`->authenticatedTenantRoutes(...)`), `app/Filament/Pages/TestSend.php`
- Test: `tests/Feature/LibraryScreensTest.php`

**Interfaces:**
- Consumes: `LibraryScanner::scan`. Task 5 adds the network badges column, and Task 6 adds the "used in posts" column.
- Produces:
  - **`Thumbnails::for(MediaItem $item): ?string`.** It returns the cached JPEG path, made with GD at a longest side of 320 and quality 80. It returns null for non-images, missing files, images above 40,000,000 pixels, or GD failures. It never throws.
  - **Routes** (inside the tenant, signed-in panel routes):
    - `GET {tenant}/library/thumb/{item}` → `library.thumb`
    - `GET {tenant}/library/file/{item}` → `library.file` (streams the source file with `response()->file()`, which handles byte ranges for `<video>`)
    - Both return 404 when the item belongs to another brand, is missing, or has no file.
  - **Library screen** ("Library", navigation sort 20):
    - Columns: thumbnail, file name, folder name, kind, range, AI-made, shows people (Yes, No or "Not answered"), size, W×H, length, missing badge.
    - Filters: kind, range, AI-made, shows people not answered, missing.
    - Search: path and description.
    - Each row has an **Edit labels** modal (kind, range, AI-made, shows people, description) and a **Preview** action (photo or `<video controls>` from `library.file`).
    - Header action **Look for new files**: runs `LibraryScanner::scan` synchronously with `set_time_limit(0)` and shows `summary()` as a notification.
    - Every label edit writes `Activity::record($brand, $item, 'labels changed', $changes)`.
  - **Folders screen** ("Folders", sort 21): group name (inline editable), folder path, file count. A rename is logged.
  - **TestSend:** the photo and video selects list attachable library items (photos and videos, not missing), keyed by item id, labelled by group name and file name. The action resolves `fullPath()`. The rehearsal call is unchanged.

- [ ] **Step 1: Write the failing tests:**
  - `test_library_lists_items_and_filters_unanswered_people`.
  - `test_edit_labels_saves_and_logs_activity`.
  - `test_look_for_new_files_reports_summary`.
  - `test_thumb_route_serves_own_brand_only`: another brand's item gives 404.
  - `test_file_route_streams_video_with_ranges`: a `Range: bytes=0-9` request gives a 206 status.
  - `test_huge_image_gets_no_thumbnail`: fake an item with width 8000 and height 6000 → `Thumbnails::for` returns null, and the list page still renders.
  - `test_folder_rename_is_logged`.
  - `test_test_send_offers_library_items`: replaces the folder-listing assertion in `RehearsalTest::test_test_send_page_offers_photos_and_videos_from_the_folder`, which is updated to seed library items.
- [ ] **Step 2:** Run `--filter=LibraryScreensTest`. Expected: FAIL.
- [ ] **Step 3:** Implement the above.
- [ ] **Step 4:** Run the filter and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat: Library and Folders screens with thumbnails and previews; Test send picks from the library`.

### Task 5: Network rules and caption counters

**Files:**
- Create: `app/Domain/Posts/MediaRules.php`, `app/Domain/Posts/Problem.php`, `app/Domain/Posts/CaptionStats.php`, `app/Enums/PostType.php`
- Modify: `MediaItemResource` (badges column), `config/smm.php` (`limits` with the Global Constraints values)
- Test: `tests/Unit/MediaRulesTest.php`, `tests/Unit/CaptionStatsTest.php`

**Interfaces:**
- **`PostType`:** `facebook_text`, `facebook_link`, `facebook_photos`, `facebook_video`, `instagram_photo`, `instagram_carousel`, `instagram_reel`, `instagram_story`.
  - Methods: `network(): Network`, `label()` (for example "Photo or photos", "Reel", "Story"), `static forNetwork(Network $n): array<string,string>`.
- **`final readonly class Problem { string $level /* 'block'|'warn' */; string $message; }`** with `static block(string)` and `static warn(string)`.
- **`CaptionStats::of(string $caption): array{chars:int, hashtags:int, mentions:int}`.**
  - It counts characters with `mb_strlen`, hashtags with `/(?<!\w)#[\p{L}\p{N}_]+/u`, and mentions with `/(?<!\w)@[\w.]+/u`.
  - `CaptionStats::hint(string $caption): string` returns, for example, `"312 / 2,200 characters · 4 hashtags · 0 mentions"`.
- **`MediaRules::check(PostType $type, array $files, string $finalCaption, ?string $link = null): list<Problem>`.** Each file is `array{name, mime, bytes, width, height, duration, codec, fps, faststart, exists: bool, isVideo: bool}`. It applies the Global Constraints limits per type:
  - **All types:**
    - A missing file → block "The copy of <name> is missing; attach it again".
    - Video facts that are unknown → warn "Could not read <name>'s details; Instagram may refuse it".
  - **facebook_text:**
    - An empty caption → block.
    - Any media → warn "Text posts send no photos or videos".
  - **facebook_link:**
    - The link must be `https://` → block otherwise.
    - Any media → warn as for text posts.
  - **facebook_photos:**
    - Needs 1 or more photos; any video → block.
    - Each photo is jpeg, png, gif, bmp or tiff and ≤ 10 MB.
  - **facebook_video:**
    - Exactly 1 video.
    - A webm video → warn "MP4 is the safest format for Facebook".
  - **instagram_photo:** exactly 1 photo, checked with the Instagram photo rules.
  - **instagram_carousel:**
    - 2 to 20 items.
    - Photos use the Instagram photo rules; videos use the Reel rules except duration.
    - A shape that differs from the first item → warn "Instagram crops every item to the first one's shape".
  - **instagram_reel:** exactly 1 video, checked with the Reel rules. A shape that is not about 9:16 (0.5 to 0.6) → warn.
  - **instagram_story:**
    - Exactly 1 photo or video, checked with the Story rules.
    - A non-empty caption → warn "Stories show no caption".
  - **Instagram caption (all Instagram types except story):** the 2,200 / 30 / 20 limits → block. More than 5 hashtags → warn.
  - **Message format:** messages name the file and the network, for example "Reel: 'Raisin grading.mp4' is 17 min long; Instagram takes 3 s to 15 min".
- **`MediaRules::badges(MediaItem $item): array<string,bool>`.**
  - Photos: `['Instagram photo' => …, 'Story' => …, 'Facebook photo' => …]`.
  - Videos: `['Reel' => …, 'Story' => …, 'Facebook video' => …]`.
  - True means no block. PNG and WebP count as JPEG-convertible (Task 6 converts them).

- [ ] **Step 1: Write the failing tests:**
  - `MediaRulesTest`, one test per rule, each with the boundary values:
    - ratios 0.8 and 1.91 pass, 0.79 and 1.92 block
    - 8 MB passes, 8 MB + 1 byte blocks
    - Reel durations 3 s and 900 s pass, 2.9 s and 900.1 s block
    - Story video 60 s passes, 60.1 s blocks
    - fps 23 and 60 pass, 22 and 61 block
    - width 1920 passes, 1921 blocks
    - carousel with 1 item blocks, 20 pass, 21 block
    - moov after mdat blocks
    - codec `mp4v` blocks
    - 6 hashtags warn, 31 block
    - an empty caption blocks a Facebook text post
    - a Story with a caption warns
    - a webm Facebook video warns
    - a missing copy blocks
  - `CaptionStatsTest`: `"Raisins #Sangli #export @moggies_international 🍇"` gives 2 hashtags, 1 mention, and `mb_strlen` characters; an email `a@b.com` is not a mention.
- [ ] **Step 2:** Run both filters. Expected: FAIL.
- [ ] **Step 3:** Implement the classes and the library badges column.
- [ ] **Step 4:** Run the filters and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat: Meta's photo, video and caption rules as block/warn checks`.

### Task 6: Posts, targets, attached media copies, final captions, AI label

**Files:**
- Create:
  - `database/migrations/2026_11_22_110000_create_posts_tables.php` (`posts`, `post_targets`, `post_media`)
  - `app/Models/Post.php`, `app/Models/PostTarget.php`, `app/Models/PostMedia.php`
  - `app/Enums/PostState.php`, `app/Enums/TargetState.php`
  - `app/Domain/Posts/PostMediaCopier.php`, `app/Domain/Posts/CaptionBuilder.php`, `app/Domain/Posts/AiLabel.php`
- Modify: `app/Models/Brand.php` (`posts(): HasMany`), `app/Models/MediaItem.php` (`postMedia(): HasMany`), `MediaItemResource` ("Used in posts" count column)
- Test: `tests/Feature/PostModelTest.php`

**Interfaces:**
- **`posts`:** `brand_id`, `caption` (text), `state` (default `draft`), `scheduled_at` (UTC, null), `approved_at`, `approved_by`, `checklist` (JSON null), `approved_fingerprint` (null), timestamps.
- **`post_targets`:**
  - `post_id` (cascade), `network`, `connected_account_id` (null, null on delete), `type`, `caption` (null = use the main caption), `link` (null)
  - `state` (default `waiting`), `external_id`, `container_id`, `permalink`, `attempts` (default 0), `last_error`, `published_at`
  - Unique (`post_id`, `network`). Part 3 fills the sending columns.
- **`post_media`:**
  - `post_id` (cascade), `media_item_id` (null, null on delete), `position`, `path` (relative to `<data>/media`)
  - `mime`, `bytes`, `width`, `height`, `duration`, `codec`, `fps`, `faststart`, `hash`
- **Enums:**
  - `PostState`: `draft`, `awaiting_approval`, `approved`. Methods: `label()` (Draft, Waiting for approval, Approved) and `color()` (`#94a3b8`, `#d97706`, `#16a34a`).
  - `TargetState`: `waiting`, `handed_to_facebook`, `queued`, `published`, `failed`, `missed`, each with `label()`.
- **`Post`:**
  - Relations: `brand()`, `targets(): HasMany`, `media(): HasMany` (ordered by `position`).
  - Methods: `target(Network $n): ?PostTarget`, `mediaDir(): string` (`<data>/media/posts/<id>`).
  - On `deleting`, the media files and the folder are removed.
- **`PostMedia`:**
  - `fullPath(): string`, `toRuleFile(): array` (the shape `MediaRules::check` takes).
  - Model events: on `saving`, when `media_item_id` is dirty or `path` is empty, call `PostMediaCopier::copy($this)`. On `deleted`, call `PostMediaCopier::remove($this)`.
- **`PostMediaCopier::copy(PostMedia $pm): void`.**
  - It copies `MediaItem::fullPath()` to the post folder. PNG and WebP are re-encoded with GD as JPEG (quality 90, transparent areas on white).
  - It fills `path` and the facts from the copy (`MediaFacts::read`), and `hash` (`xxh128`).
  - If the source is missing, it throws `RuntimeException("'<name>' is no longer in Markting Social Media")`.
  - `remove(PostMedia $pm): void` deletes the copy, and never touches the source.
- **`AiLabel`:**
  - `applies(Post $post): bool`: any `post->media->mediaItem` with `ai_made && shows_people === true`.
  - `unanswered(Post $post): list<MediaItem>`: `ai_made && shows_people === null`.
- **`CaptionBuilder`:**
  - Constant `AI_LINE = 'Visuals made with AI'`.
  - `for(PostTarget $t): string`: `trim($t->caption ?: $t->post->caption)`, plus `"\n\n".AI_LINE` for Facebook when `AiLabel::applies`. A Story gives `''`.

- [ ] **Step 1: Write the failing tests:**
  - `test_attaching_copies_into_the_post_folder_and_leaves_source_untouched`: the source mtime is unchanged.
  - `test_png_is_saved_as_jpeg_with_white_background`: a transparent PNG; the copy's mime is `image/jpeg` and the corner pixel is white.
  - `test_post_copy_survives_source_rename`: rename the source after attaching → the copy still exists and `MediaRules` reports no missing file.
  - `test_removing_media_deletes_only_the_copy`.
  - `test_deleting_post_removes_its_folder`.
  - `test_facebook_caption_gets_ai_line_only_for_ai_people`: three cases: ai+people, ai without people, not ai.
  - `test_story_final_caption_is_empty`.
  - `test_missing_source_cannot_be_attached`: a plain message.
- [ ] **Step 2:** Run `--filter=PostModelTest`. Expected: FAIL.
- [ ] **Step 3:** Implement the above.
- [ ] **Step 4:** Run the filter and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat: posts with a version per network, copied media and final captions`.

### Task 7: Approval rules and state changes

**Files:**
- Create: `app/Domain/Posts/Approval.php`, `app/Domain/Posts/ApprovalRefused.php`
- Test: `tests/Feature/ApprovalTest.php`

**Interfaces:**
- Consumes: `MediaRules::check`, `CaptionBuilder::for`, `AiLabel`, `Activity::record`, `Brand::approvalChecklist`.
- Produces:
  - **`Approval::problems(Post $post): list<Problem>`.** Each message is prefixed "Facebook: " or "Instagram: " where it applies. It blocks on:
    - no network chosen
    - a target whose network has no `connected` `ConnectedAccount` for the brand ("Connect Facebook on the Meta page first")
    - no time, or a time in the past
    - any `AiLabel::unanswered` file ("Answer 'shows people' for '<name>' in the Library")
    - plus each target's `MediaRules::check(type, files, CaptionBuilder::for(target), link)`.
  - **`submit(Post $post, string $by): void`:** draft → awaiting_approval, and logged.
  - **`approve(Post $post, list<string> $ticked, string $by): void`:**
    - It requires state draft or awaiting_approval, the ticked items equal to `brand->approvalChecklist()` (any order), and no blocking problems.
    - Otherwise it throws `ApprovalRefused` with a plain message.
    - It sets approved, `approved_at`, `approved_by`, `checklist` (`[{item, ticked:true}]`) and `approved_fingerprint`, and logs it.
  - **`withdraw(Post $post, string $by, string $reason): void`:** approved → awaiting_approval, clears the approval fields, logs. Part 3 adds the withdrawal of a Facebook hand-over here.
  - **`reopenIfChanged(Post $post, string $by): bool`:** if the post is approved and `fingerprint($post) !== approved_fingerprint`, it calls `withdraw($post, $by, 'the post changed')` and returns true.
  - **`reschedule(Post $post, CarbonImmutable $utc, string $by): void`:**
    - It changes only `scheduled_at` and keeps the approval and fingerprint.
    - A past time throws `ApprovalRefused('Pick a time in the future')`.
    - It logs from and to in the brand's time.
  - **`fingerprint(Post $post): string`:** sha256 of the JSON of caption, targets sorted by network (type, caption, link), and media by position (`media_item_id`, `hash`).

- [ ] **Step 1: Write the failing tests:**
  - `test_cannot_approve_with_blocking_problems`.
  - `test_cannot_approve_without_every_checklist_item`.
  - `test_approve_records_who_when_and_checklist`.
  - `test_unanswered_ai_people_blocks`.
  - `test_unconnected_network_blocks`.
  - `test_past_time_blocks`.
  - `test_caption_change_reopens_approval`.
  - `test_media_reorder_reopens_approval`: swap positions only.
  - `test_time_change_keeps_approval`.
  - `test_reschedule_to_past_is_refused`.
  - `test_every_change_is_in_the_activity_log`.
- [ ] **Step 2:** Run `--filter=ApprovalTest`. Expected: FAIL.
- [ ] **Step 3:** Implement the above.
- [ ] **Step 4:** Run the filter and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat: approval with the brand checklist; content changes need approval again`.

### Task 8: Composer screens (write, preview, approve)

**Files:**
- Create:
  - `app/Filament/Resources/Posts/PostResource.php`, `Pages/ListPosts.php`, `Pages/CreatePost.php`, `Pages/EditPost.php`, `Schemas/PostForm.php`
  - `resources/views/filament/posts/preview.blade.php`, `resources/views/filament/posts/history.blade.php`
- Test: `tests/Feature/ComposerTest.php`

**Interfaces:**
- Consumes: `Approval`, `CaptionBuilder`, `CaptionStats`, `MediaRules`, `AiLabel`, `PostType::forNetwork`, `MediaItem::scopeAttachable`.
- Produces:
  - **`PostForm::configure(Schema $schema): Schema`**, with fields:
    - `caption`: Textarea, 8 rows, `live(debounce: 500)`, hint `CaptionStats::hint`.
    - `scheduled_at`: DateTimePicker in the brand's timezone, `seconds(false)`.
    - `media`: Repeater with `relationship('media')`, `orderColumn('position')`, reorderable.
      - Its one field is `media_item_id`, a Select over attachable library items. It is searchable by path and description, and labelled "<group> / <file> (<kind>, W×H or length)".
      - Add label: "Add a photo or video".
    - Section **Facebook**: `fb_on` Toggle, `fb_type` Select from `PostType::forNetwork(Network::FacebookPage)`, `fb_caption` Textarea ("Leave empty to use the main caption"), `fb_link` TextInput (url, visible for `facebook_link`).
    - Section **Instagram**: `ig_on`, `ig_type`, `ig_caption`.
    - On edit only: `View::make('filament.posts.preview')` and `View::make('filament.posts.history')`.
  - **`PostForm::fillTargets(Post $post): array`** and **`PostForm::saveTargets(Post $post, array $data): void`.** They create, update or delete targets and set `connected_account_id` from the brand's connected account for that network.
  - **Preview:**
    - Per target: the final caption (`CaptionBuilder::for`), the AI notice where it applies ("Instagram's AI label will be added" / the Facebook line shown greyed), thumbnails in order, `CaptionStats::hint`, and the problems list (block in red, warn in amber).
    - A note: "Save to refresh the preview".
  - **EditPost:**
    - `afterSave()` runs `saveTargets`, then `Approval::reopenIfChanged($post, $user->name)`. If it reopens, a warning says "The post changed, so it needs your approval again".
    - Header actions:
      - **Send for approval** (draft)
      - **Approve** (draft or waiting). Its modal shows the blocking problems; the action is disabled while any exist. A `CheckboxList` of `approvalChecklist()` needs every item. Its submit calls `Approval::approve`.
      - **Withdraw approval** (approved)
      - **Delete** (with confirmation, logged)
  - **List:** time in the brand's timezone (sorted ascending), the first 60 characters of the caption, networks as badges, state badge (`PostState::color`), number of blocking problems. Filter: state. Navigation "Posts", sort 10.
  - **CreatePost:** after creating, `saveTargets`, then redirect to the edit page.

- [ ] **Step 1: Write the failing tests** (Livewire, same setup as `MetaConnectTest`):
  - `test_create_post_with_facebook_and_instagram_versions`: targets saved and accounts linked.
  - `test_media_repeater_copies_files_in_order`.
  - `test_preview_shows_final_caption_ai_line_and_problems`.
  - `test_approve_action_disabled_while_blocking_problems`.
  - `test_approve_requires_every_checklist_item`.
  - `test_editing_caption_of_approved_post_sends_it_back`.
  - `test_list_shows_brand_time_and_state`.
- [ ] **Step 2:** Run `--filter=ComposerTest`. Expected: FAIL.
- [ ] **Step 3:** Implement the above.
- [ ] **Step 4:** Run the filter and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat: composer with a version per network, previews, checks and approval`.

### Task 9: Calendar (week and month, drag to move)

**Files:**
- Create: `public/vendor/fullcalendar/6.1.15/index.global.min.js` (downloaded once from `https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js`, keeping its MIT header), `app/Filament/Pages/Calendar.php`, `resources/views/filament/pages/calendar.blade.php`
- Test: `tests/Feature/CalendarTest.php`

**Interfaces:**
- Consumes: `Approval::reschedule`, `PostState::color`.
- **Time handling:** FullCalendar runs with `timeZone: 'UTC'` and receives brand **wall-clock** times written as UTC ISO strings (`2026-12-04T00:30:00Z` means 00:30 IST). It therefore shows brand time in any browser, and returns the same form on drop.
- Produces:
  - **`Calendar::events(string $from, string $to): list<array{id:int, title:string, start:string, color:string, url:string, editable:bool}>`.**
    - `title` is the first line of the caption (40 characters) plus " · FB" and/or " · IG".
    - `start` is brand wall-clock with a `Z` suffix.
    - `url` is the post's edit page.
    - `editable` is false for times in the past.
  - **`Calendar::move(int $postId, string $wallClock): array{ok: bool, message: string}`.** It converts the brand wall-clock to UTC and calls `Approval::reschedule`. On `ApprovalRefused` it returns `ok` false with the message (the view then calls `info.revert()` and shows the message). Posts of another brand → `ok` false.
  - **View:**
    - Loads the vendored file with `asset()`.
    - Views: `timeGridWeek` (default) and `dayGridMonth`; first day Monday; `slotMinTime` 06:00.
    - `eventDrop` → `$wire.move(...)`.
    - A legend of state colours.
    - Navigation "Calendar", sort 11.

- [ ] **Step 1: Write the failing tests:**
  - `test_events_in_range_with_colours_and_links`.
  - `test_events_use_brand_wall_clock_near_midnight`: a post at 3 Dec 19:00 UTC → `start` is `2026-12-04T00:30:00Z`; `move(id, '2026-12-05T00:30:00')` stores 4 Dec 19:00 UTC.
  - `test_move_keeps_approval`.
  - `test_move_to_past_is_refused_with_message`.
  - `test_other_brand_post_cannot_be_moved`.
  - `test_calendar_page_loads_vendored_script`: the response contains `vendor/fullcalendar/6.1.15/index.global.min.js`, and the file exists.
- [ ] **Step 2:** Run `--filter=CalendarTest`. Expected: FAIL.
- [ ] **Step 3:** Download the script, then implement the page and view.
- [ ] **Step 4:** Run the filter and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat: calendar with week and month views and drag to move`.

### Task 10: Claude's library commands (`smm:media:find`, `smm:media:label`)

**Files:**
- Create: `app/Console/Commands/MediaFindCommand.php`, `app/Console/Commands/MediaLabelCommand.php`, `app/Domain/Library/LabelImport.php`
- Test: `tests/Feature/MediaCommandsTest.php`

**Interfaces:**
- **`smm:media:find {words*} {--kind=} {--range=} {--attachable} {--include-missing} {--limit=40} {--brand=moggies-international}`.**
  - Every word must match, case-insensitive, in the group name, relative path, description, kind or range.
  - It prints one block per file: full path, group, kind, range, AI-made, shows people, size in MB, W×H, length, codec, the `MediaRules::badges` that fail, the description, and "used in N posts".
  - Missing files are left out unless `--include-missing` is given.
- **`smm:media:label {file} {--brand=moggies-international}`** calls `LabelImport::apply(Brand $brand, array $data): array{groups:int, items:int, unknown:list<string>}`. The JSON shape:

  ```json
  {"source": "<optional source root, default the brand's first>",
   "groups": [{"folder": "5", "name": "Customer outside video, June 2026"}],
   "items": [{"path": "5/final.mp4", "kind": "finished_video", "product_range": "raisins", "ai_made": false, "shows_people": false, "description": "..."}]}
  ```

  - Only those fields can change, never posts, settings or files.
  - Unknown paths and invalid enum values are listed, not created.
  - Every change is logged with `by = 'Claude'`.
  - It is all or nothing: an invalid file changes nothing.

- [ ] **Step 1: Write the failing tests:**
  - `test_find_matches_all_words_and_prints_full_paths`.
  - `test_find_skips_missing_unless_asked`.
  - `test_label_import_changes_only_labels_and_logs_by_claude`: a post using the item is unchanged.
  - `test_label_import_reports_unknown_paths`.
  - `test_invalid_label_file_changes_nothing`.
- [ ] **Step 2:** Run `--filter=MediaCommandsTest`. Expected: FAIL.
- [ ] **Step 3:** Implement the above.
- [ ] **Step 4:** Run the filter and the full suite. Expected: PASS.
- [ ] **Step 5:** Commit `feat: smm:media:find and smm:media:label for Claude`.

### Task 11: Self-check, guide, log, real-folder and screen checks

**Files:**
- Modify: `app/Console/Commands/DoctorCommand.php` (`checkLibrary()`: OK "Library folder found: <path> (<n> files)", or WARN "Library folder not found: <path>"), `docs/HOW_TO_USE.html`, `docs/DEVELOPMENT_LOG.md`
- Test: `tests/Feature/DoctorCommandTest.php` (add `test_doctor_reports_library_folder`)

- [ ] **Step 1:** Write `test_doctor_reports_library_folder`, run it (FAIL), implement `checkLibrary()`, and run it again (PASS).
- [ ] **Step 2: HOW_TO_USE.html.** New sections in plain words:
  - "Library": look for new files; labels; "shows people"; never moves your files; add Markting Social Media to the weekly USB copy.
  - "Write a post": main caption, Facebook and Instagram versions, photos and videos in order, preview, red and amber checks.
  - "Approve": checklist; editing sends it back; moving the time does not.
  - "Calendar".
  - Update "Where we are" and the date.
- [ ] **Step 3: DEVELOPMENT_LOG.md.** Add an `SM-009` entry in the SM-008 format: tracker #20 to #23; design and plan links; "What changed", "Safety" (read-only folder, no publishing yet) and "Checks" lines; and the new dependency `james-heinrich/getid3` (owner approved with this plan) and FullCalendar 6.1.15 (vendored).
- [ ] **Step 4:** Run the full suite with `php artisan test`. Expected: all pass. Then run `vendor/bin/pint --test`. Expected: no changes.
- [ ] **Step 5: Real folder, read-only proof (Dev).**
  1. In PowerShell, save `Get-ChildItem -Recurse -File 'D:\Pravin\Moggi\Markting Social Media' | Select FullName,Length,LastWriteTimeUtc | Export-Csv <scratchpad>\before.csv`.
  2. Run `php artisan smm:media:scan` in Dev.
  3. Export `after.csv` the same way, and compare the two CSVs. Expected: identical.
  4. Run `php artisan smm:media:find raisin --attachable --limit=5`. Expected: real files with their facts.
  5. Note the first-scan duration in the log entry.
- [ ] **Step 6: Screens (Dev, port 8091; start with `scripts\windows\start.ps1`).**
  1. Use headless Edge (Playwright, fresh profile) at 1366 and 390 px.
  2. Check Library, Folders, Posts (create one draft with a Facebook text and an Instagram photo version), the Approve modal, and the Calendar in week and month view with one drag. Check there are no console errors and no sideways scrolling.
  3. Delete the test post.
  4. Close the browser and delete its profile.
- [ ] **Step 7:** Commit `docs: SM-009 log, How-to-use sections, self-check of the library folder`.
