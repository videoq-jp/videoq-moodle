# PHP-only verification

The companion repository uses PHP and the scoped shell cron runner for executable
server tooling. Browser assets and fixtures run as JavaScript in native browsers;
there is no server-side JavaScript runtime, application build or Node/Python
package installation in this repository. PHP communicates with ChromeDriver or
GeckoDriver and uses cURL, OpenSSL, database extensions and ZipArchive directly.
VideoQ remains an independently deployed application with its own runtime.

## Current native learning interface

The 0.22.0 companion renders learning and teacher panels with Moodle templates.
Ordinary requests use core/ajax; streaming uses a same-origin Moodle endpoint.
PHP signs the private VideoQ API requests. No presentation/authentication frame or
cross-window learning bridge is part of this transport.

| Check | What it proves |
| --- | --- |
| `php tests/packages.php` | Standard Moodle ZIP boundaries, required assets, reproducibility and checksums |
| `php tests/native-api.php` | Request/body binding, immutable PHP session windows, status/error bounds and releasing the session lock before upstream streaming; signer/cURL substitutes |
| `php tests/native-context.php` | Server-derived actor/course/activity scope and operation capabilities; controlled Moodle state |
| `php lab/check-browser.php` | Current browser assets compile in native Chrome through PHP WebDriver |
| `php lab/check-native-learning.php MOODLE_SOURCE` | Real pinned Moodle templates with controlled session/API/model data: chat, feedback, history, Q&A, material/tag drafts, reports, permission refusal and empty-course panels |
| `php lab/check-youtube-player.php` | File playback, current-grant YouTube behavior, local playback/citation events and malformed provider input; controlled media/provider SDK |
| `php tests/privacy.php MOODLE_SOURCE` | Real pinned Moodle Privacy API interfaces and metadata classes, without a database or external service |
| `php tests/privacy-requests.php` | Approved scope intersection, former users, Unicode/large export integrity and transport limits; controlled transport |
| `php lab/check-marketplace-matrix.php pg OUTPUT` | Actual PostgreSQL Moodle install/upgrade/uninstall/reinstall, permissions, source preservation, Privacy API and native AJAX registration/removal |
| `php lab/check-marketplace-matrix.php mysql OUTPUT` | The corresponding actual MySQL lifecycle |

The source-pinned companion CI runs these checks, the official Moodle coding
standard, the remaining PHP contracts, Compose validation and installer/AMOS
builds. The aggregate requires both actual database lifecycles. Browser contract
responses are substitutes; a green companion run does not establish actual
VideoQ signature interoperability, incremental network relay or AI/erasure.

The paired VideoQ repository owns the **Moodle installed acceptance** workflow.
Its pinned Moodle image includes this companion and runs against actual VideoQ,
storage, databases and local CPU inference. The native journey requires real
Moodle PHP-to-VideoQ authentication, File export/ASR/indexing, AI answers,
citations/media seeking, feedback, conversations, transcripts, teacher edits,
tags, Q&A, reports, same-session capability/source/enrolment changes and standard
core-approved subject export/deletion with model-memory comparison. The browser
must use Moodle endpoints without VideoQ frames, direct VideoQ/storage requests
or browser Authorization headers. Inspect the source identities, phase report,
privacy evidence and cleanup before accepting the result. Installer sources must
match the accepted companion runtime.

## Historical frame trials

Earlier personal-history/material-editor reports, iframe browser checks and the
100-learner trial in `docs/evidence/` describe their dated older transports. They
are not acceptance evidence for this native UI. The historical trial completed
100 questions and authenticated paced video ranges on one deployment; it is not
a production capacity or full-duration multi-device guarantee.

Older `lab/verify-chat.php`, browser-dependent background/restore checks and
`lab/verify-load.php` assume the retired frame protocol. Do not use them to verify
0.22.0 native learning. Reproduce the matching historical source revision when
investigating those records; use current native contracts and the paired actual
CPU/privacy workflow for this candidate. No original fixture, user content or
historical result is replaced by a new report.

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
