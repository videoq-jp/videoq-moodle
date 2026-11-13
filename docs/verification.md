# PHP-only verification

The companion repository uses PHP and the scoped shell cron runner for executable
server tooling. Browser assets and fixtures run as JavaScript in native browsers;
there is no server-side JavaScript runtime, application build or Node/Python
package installation in this repository. PHP communicates with ChromeDriver or
GeckoDriver and uses cURL, OpenSSL, database extensions and ZipArchive directly.
VideoQ remains an independently deployed application with its own runtime.

## Single-plugin 0.25.4

The supported distribution is `local_videoq.zip` only. The old LTI helper is absent from fresh installs. The DB matrix upgrades the published 0.25.3 two-plugin version, preserves its records, removes the helper through Moodle, and reinstalls only the local plugin. It also verifies that setup reaches connection-code validation with no helper installed.

The separately installed Moodle test completed real school authorization, a fresh teacher-owned course connection, learner video playback and local AI answers, feedback restoration, and owner-only CSV access after removing the helper's code and database registration. Generated LTI activity links open the native viewer and are hidden from course navigation; unrelated activities and Moodle permissions are preserved. See [the evidence](evidence/single-plugin-20261113/README.md).

## Current native learning interface

The 0.25.4 plugin renders learning and teacher panels with Moodle templates.
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
0.22.1 native learning. Reproduce the matching historical source revision when
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

## 0.25 linked native course update (2026-11-13)

The teacher selects an existing owned VideoQ course for Moodle, matching Classroom.
Moodle source-upload controls and activity import observers are retired; old queued
export tasks cancel their destinations. Scheduled maintenance retains assertion and
transfer-record cleanup only. Deploy matching API/web and migrations 0063/0064.

Verified during development:
- Offline PHP contracts, plugin packaging, translations and PHP/JavaScript syntax.
- PostgreSQL connection ownership, fresh anchor checks, disconnect/revocation,
  current native playlist membership and signed Moodle playback requests.
- Production player tests with controlled Moodle AJAX: file/provider switching,
  citations, unknown-video refusal and late-response revocation.
- Native learning handlers rendered with pinned Moodle core message templates:
  streamed questions, safe answer text, feedback, Q&A, personal NDJSON history and
  failed-question draft preservation. Run in a DOM harness with API/media substitutes.
- ego-browser desktop/mobile layout checks using the production plugin template,
  styles and scripts; Moodle theme/message fragments and upstream data substituted.

These are development checks. The new plugin has not been installed on a real
Moodle instance for end-to-end acceptance, and no production deployment was made.

## 0.25.1 native feature parity

Removed learner new-conversation/private-history buttons from both integrations.
Internal conversation restoration remains automatic, matching native VideoQ.
Connected owner teachers use the native latest-100 history and full CSV path;
learner/co-teacher requests are refused by fresh server authorization. No extra
aggregate-report or transcript/tag/authoring panels are surfaced in the viewer.

Checks include the actual Moodle core message templates and plugin browser assets
through ego-browser, owner history/CSV and preserved chat/drafts, plus PostgreSQL
signed native requests, Google-context revocation and attribution/CSV tests.
Moodle sessions, upstream APIs/media and model responses in browser fixtures are
substitutes. Production deployment and installed Moodle/Classroom acceptance have
not been performed.

## 0.25.3 installed linked-course verification

An isolated installed Moodle 5.1.8+ / Boost environment was paired with the current
VideoQ working tree on 2026-11-13. ego-browser exercised administrator approval,
teacher-owned native course selection, learner playback of two actual files,
real local-model answers citing both videos, citation switching, feedback and
restoration. The owner downloaded real CSV including the Moodle-verified learner
name/email, question, answer and feedback; student and co-teacher history/CSV
requests were refused. The 390px check retained the media element when collapsing
it for input. Standard Moodle avatar rendering was retained.

The 0.25.3 update signs the server-derived learner profile, reuses the existing
answer-author snapshot, declares that transfer in Moodle privacy metadata, and
uses the regular SRT parser for owner-history citations. The connection-complete
instructions now describe teacher-owned existing-course linking.

Both plugins were CLI-upgraded to 2026111304, with PHP signature/package contracts
and generated ZIPs checked. This used previously processed public test materials;
new ASR/indexing, iPhone hardware, Google Classroom installation and production
load/deployment were not tested. A transient playback authorization failure under
parallel load required a retry. Detailed sanitized evidence is in the paired
VideoQ repository at `docs/evidence/moodle-linked-20261113/`.
