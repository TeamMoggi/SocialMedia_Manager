# SocialMedia_Manager — notes for agents

Moggies International's own social media app (Instagram, Facebook Page, LinkedIn company page): Laravel 13 + Filament 5, local first on the owner's laptop.

- Spec: `docs/PROJECT_PLAN.html` (v1.0, approved 14 Nov 2026). Current build plan: `docs/superpowers/plans/`.
- Tracker: https://claude.ai/artifact/Vq8Q6jpWRxQ645PNxv7PDp (local copy `..\Tracker`). Log: `docs/DEVELOPMENT_LOG.md` (SM-0xx).
- Folders: `App` = owner's running copy (`main`), `Dev` = git worktree for changes (dry-run posting), data in `..\Data` / `..\DevData` via the git-ignored `.data-dir` file. Data, `.env`, `*.sqlite`, media and backups never go into Git.
- PHP 8.5 and Composer are already installed (Composer: `D:\Tools\ComposerSetup`). Do not install other toolchains (no Node build, no Laravel Boost).
- Routine: branch per change → code commit → `docs:` commit (log + guide) → push → owner merges the pull request (`https://github.com/TeamMoggi/SocialMedia_Manager/pull/new/<branch>`). Commit messages end with the Co-Authored-By line.
- Run `php artisan smm:doctor` to check a copy; `php artisan test` for the suite.
