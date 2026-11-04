# Verification and tooling migration

## Runtime boundary

`videoq-moodle` has no JavaScript server executable, dependency manifest, package
tree or CI action. Moodle plugins run PHP and ship two browser scripts unchanged.
Selenium communicates with native Chrome/ChromeDriver; its manager is native Rust.
The optional AI mock uses Python's standard-library HTTP server. Installer builds
use only PHP and `ext-zip`, and are tested with network access disabled.

VideoQ is an external application. Its server, maintenance scheduler, storage,
queue, frontend deployment and processing workers belong to that project. This
repository neither imports its TypeScript source nor compiles or launches it.
The current trial preserves those external services and all existing media;
the Moodle Compose project owns only Moodle/PostgreSQL/Caddy/PHP synchronization
and the optional Python mock. Removing Node from this repository does not rewrite
the separately deployed VideoQ application or uninstall its runtime on the host.

## Current executable checks

| Command | Evidence produced |
|---|---|
| `php tests/packages.php` | Both Moodle installer ZIP roots, required assets, boundaries and checksums; offline PHP-only build |
| `php tests/tool-keys.php` | Private permissions, overwrite refusal, valid RSA components and a real sign/verify operation |
| `python -m unittest discover -s tests -p 'test_*.py' -v` | Repository dependency boundary; strict provider configuration; UTF-8/CRLF SSE parsing; mock AI HTTP schemas/tool calls/stream usage |
| `python lab/check_browser.py` | Both shipped browser scripts compile in native Chrome |
| `python lab/verify_chat.py` | Actual LTI launch, native composer, IME, held partial answer, safe formatting, theme inheritance, forged-message rejection, feedback/retry, timestamps, responsive widths, interrupted-stream retry |
| `python lab/verify_chat.py --live` | One real model stream with sources, incremental UI updates, persisted Good/Bad/reset and citation seek |
| `python lab/check_materials.py` | Temporary Moodle tool/course; signed service authentication, replay/audience/scope rejection, opted-in manifest, access identity, destination allowlist, idempotent export, stale-job cancellation and clearing signed destinations; transaction/cache restoration |
| `python lab/verify_background.py --media FILE` | Normal cron + external scheduler + normal worker, actual export/transcription/index, same-course membership, source deletion, physical S3 cleanup, original-video preservation and two-video citation/selector switching without resetting the conversation |
| `python lab/verify_connection.py` | Connection-code UI, wrong-issuer rejection, exchange retry recovery, cancel/disconnect UI, revocation and preservation of other connections/learning data |
| `python lab/verify_load.py --count 2 --live` | Multiple real Moodle learner logins and concurrent real questions without creating extra VideoQ accounts; temporary learners removed |
| `python lab/verify_backup.py` | Consistent PostgreSQL snapshots restored to scratch DBs, matching table counts and valid foreign keys; scratch DBs removed |
| `python lab/verify_media_backup.py` | Full Moodle filedir restore/content hashes and disposable S3 upload/download restore; selected source bytes equal VideoQ bytes |
| `docker exec -u www-data videoq-lti-lab-moodle-1 php /lab/check-materials-tls.php` | Rejection of untrusted TLS and successful key fetch with the readable public CA bundle |

Prefix Python commands with your venv's executable. These integration commands
are guarded for the isolated local trial. They require a separately managed
compatible VideoQ deployment; unit/package/browser-syntax checks do not. Real
AI commands may incur provider charges. No report contains provider secrets,
launch tokens or signing keys. Store snapshots and reports outside source control.

## Earlier tools

The old JavaScript test harness and direct-core TypeScript spike are removed,
including their lockfile, dependency tree, runtime loaders and Compose overlays.
Their source remains in Git history at `1418f90` for provenance, not execution.
This migration consolidates related checks rather than translating obsolete
fixed demo IDs or fake-vector database seeding into another language.

| Earlier scenario group | Current replacement/status |
|---|---|
| design, playback, streaming, companion | `verify_chat.py`, `check_browser.py` |
| course-wide and multi-video selection | `verify_background.py` / `verify_course.py` |
| materials chat and live stream | `verify_chat.py --live` |
| provider configuration and SSE reader | `tests/test_lab.py`, `videoq_lab/settings.py`, `videoq_lab/sse.py` |
| materials service and export contracts | `check_materials.py` and guarded PHP fixture |
| real background materials lifecycle | `verify_background.py` |
| connection/disconnect/retry | `verify_connection.py` |
| classroom question concurrency | `verify_load.py`, selectable 1–200 learners; use `--live` explicitly |
| logical DB/media backups | `verify_backup.py`, `verify_media_backup.py` |
| original generated lesson | `generate_video.py` and static JSON scene data |
| pure-pull prototype, compiled internal API adapter, fixture queue worker | Retired: the current integration uses the deployed HTTPS materials service and its normal worker |
| one-time static demo seeding and stale legacy fixture repair | Retired: use normal Moodle uploads and current synchronization; user-deleted demos are not recreated |
| 100-learner capacity, alternate themes, broad LMS interoperability | Deployment-specific acceptance; a two-learner smoke run does not prove these |

Historical JSON under `docs/evidence/` is labeled by its original scenario and
records an earlier run. It is not a new result, certification or capacity promise.
New local reports live in `$LTI_LAB_DIR/acceptance`; only sanitized summaries
should be copied into this repository.

## CI and releases

CI fetches the exact event revision with Git shell commands, runs the offline
PHP package/key contracts and PHP syntax checks, installs pinned Python tools,
runs unit/dependency checks, compiles browser assets in native Chrome, validates
Compose and builds ZIPs. It uses no JavaScript-based checkout/setup/artifact action.
A trusted version-tag job rebuilds from the verified source and publishes both
ZIPs plus `SHA256SUMS` with the GitHub CLI. Live integration tests run against the
isolated trial; they are not silently reported as CI tests.
