# PHP-only verification

All executable tooling maintained here is PHP or the small scoped shell cron
runner. The two plugin scripts and browser test fixtures are JavaScript executed
inside the browser, with no server-side JavaScript runtime or build step. There are no
Node/Python manifests, package installations, Selenium bindings or hidden runtime
GitHub actions. PHP communicates with native ChromeDriver or GeckoDriver over HTTP and uses
cURL, OpenSSL, pgsql and ZipArchive directly.

VideoQ remains an independently deployed application. Its implementation and
runtime dependencies belong to that project; this repository neither imports
its source nor starts its API, frontend, scheduler or processing workers.

| Check | What it proves |
|---|---|
| `php tests/packages.php` | Offline standard Moodle ZIPs, required assets, safe boundaries, checksums |
| `php tests/tool-keys.php` | Private file permissions, overwrite refusal, RSA key components and actual sign/verify |
| `php tests/lab.php` | SSE UTF-8/CRLF byte boundaries, ordered segments/citations, incomplete/error rejection; real PHP mock HTTP transcription/embedding/tool-call/stream schemas |
| `php tests/dependencies.php` | No additional language source/dependency trees, hidden runtime actions or application services owned by the lab |
| `php lab/check-browser.php` | Static plugin and fixture scripts compile in native Chrome through PHP WebDriver |
| `php lab/verify-chat.php` | Real LTI launch, native Moodle composer, IME, partial stream, safe rich text, theme inheritance, forged-message rejection, feedback retry, citation seek, responsive widths, interrupted-stream retry |
| `php lab/verify-chat.php --live` | Real model streaming/sources, incremental native display, persisted Good/Bad/reset and timestamp seek |
| `php lab/check-materials.php` | Disposable PHP service-auth/export/access contracts including replay, scope/audience, hidden source and unenrolled-account rejection; transactional/cache restoration |
| `php lab/verify-connection.php` | Code UI, issuer binding, exchange retry, cancellation/disconnect/revocation, preserved connections/data |
| `php lab/verify-background.php FILE` | Normal scheduler/cron/worker export, transcription/indexing, same-course membership, two-video citation/selector switching without losing chat, replacement processing in the same course, source deletion and physical media cleanup |
| `php lab/verify-course-restore.php` | Actual Moodle `.mbz` backup/restore, disabled-source opt-in and destination links, preserved source and existing videos |
| `php lab/verify-live-restore.php` | Actual same-site course copy and fresh archive extraction/restore; independent indexing, real scoped AI answers, private history separation, current-site connection, isolated deletion and physical media cleanup |
| `php lab/verify-load.php --count 100 --live` | 100 distinct real Moodle/LTI logins, simultaneous real questions and authenticated video ranges, native browser playback observer; test questions/users removed, existing logs preserved |
| `php lab/verify-load.php --count 100 --media-only --full-video` | Full-file authenticated transfers and content hashes, one native decoder, three scheduled real questions, first text/completion timings and host-memory guard; incomplete transfers fail |
| `php lab/verify-media-delivery.php` | Core X-Sendfile authorization, matching media bytes/MIME, anonymous/unenrolled/direct-path/forged-header rejection |
| `php lab/verify-backup.php` | Consistent database snapshots restored to scratch DBs, matching counts/foreign keys; scratch DBs removed |
| `php lab/verify-media-backup.php` | Moodle filedir restore/content hashes and disposable S3 upload/download restore; actual Moodle/VideoQ media bytes match |
| `php lab/verify-readiness.php` | Bounded DB outage/readiness recovery without restarting the API |
| Web-user `lab/check-materials-tls.php` | Untrusted TLS rejected and readable public CA accepted |

`BROWSER=firefox php lab/verify-chat.php` selects native Firefox/GeckoDriver.
Browser receipts record the actual user agent and Moodle stylesheet.

These integration checks require the isolated trial and an independently deployed
compatible VideoQ service. The offline PHP checks need neither that service nor
native browser binaries. Real inference requires valid provider settings on
VideoQ and may incur charges. The [100-learner trial](evidence/capacity-100.json)
completed all 100 questions (median 17.8 s, p95 23.4 s; first-text p95 22.0 s).
All 100 authenticated 4 MiB video ranges succeeded, paced at a receive limit of
256 KiB/s per learner. The playback observer had no waiting events, errors or
dropped frames. This is one burst on the current deployment, not a full-duration
video test on 100 physical devices or a production capacity/latency guarantee.

The previous JavaScript harness was replaced in v0.8.0, then the intermediate
Python harness was replaced with PHP. Earlier source remains in Git history;
`docs/evidence/` contains dated historical runs, not current certification. New
reports are stored outside the checkout. No old result is relabeled as a PHP run.

CI fetches the exact revision using Git, runs offline PHP contracts and PHP syntax,
downloads a paired native Chrome/ChromeDriver through PHP, compiles browser assets,
validates Compose and builds installers. The trusted tag job rebuilds from verified
source and publishes ZIPs/checksums using the PHP GitHub API publisher. Live integration tests are
executed against the trial and are not misrepresented as hosted CI checks.

## Release recovery

The PHP publisher re-reads server state after transient API failures. It resumes
drafts, verifies uploaded asset hashes, and treats an already published release
with matching assets as success. It never deletes a release or replaces an
existing asset; mismatched published assets fail explicitly. This covers a server
error returned after GitHub already accepted an upload or publication request.

Run the workflow manually from `main` with `release_tag` set to an existing
version (for example `v0.9.0`) to verify or resume its publication. The workflow
uses the verified publisher and rebuilds installers from that exact tag. Previous
failed Actions runs remain historical records; recovery has its own run URL.

`php tests/release.php` covers ambiguous creation/upload/publication responses,
idempotent publication, mismatched assets, and authorization failures offline.

The [integration review](integration-review.md) records current findings, corrections
and deployment limits, including the distinction between whole-lab recovery and
Moodle course backup/restore. Live integration checks are serialized by a PHP lock;
`php tests/live-lock.php` verifies concurrent checks are rejected and locks released.
