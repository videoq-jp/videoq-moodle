# Connect Moodle and VideoQ

## Requirements

- Moodle 5.1 with HTTPS, normal cron and ad-hoc task execution. The local trial
  uses Moodle 5.1.8+ and Boost; other themes/versions need deployment testing.
- A compatible independently deployed VideoQ server with LTI 1.3,
  `/api/lms/pairing/*`, the LTI materials service, signed S3 uploads, processing
  workers and a maintenance scheduler. An older unmodified VideoQ release does
  not gain backend features by installing a Moodle plugin.
- An administrator on Moodle and the owning account on VideoQ.

Moodle runs PHP and serves the browser JavaScript directly. No Node.js runtime,
package manager or JavaScript compilation step is needed for these plugins,
their package build, this repository's lab or CI.

## Install and connect

1. Download `local_videoq.zip`, `ltisource_videoq.zip` and `SHA256SUMS` from the
   [releases](https://github.com/yukiharada1228/videoq-moodle/releases). Verify
   checksums with `sha256sum -c SHA256SUMS` in the download directory.
2. Install `local_videoq.zip` through Moodle's plugin installer, then install
   `ltisource_videoq.zip`. Complete the Moodle database upgrade. Each archive
   contains Moodle's expected `videoq/` root. For a manual installation, put the
   folders under `public/local/videoq` and `public/mod/lti/source/videoq` on
   Moodle 5.1, then run Moodle's normal CLI upgrade.
3. In the owning VideoQ account, open **Settings → Connect Moodle**, enter the
   exact Moodle HTTPS base URL, and generate a connection code.
4. As Moodle administrator, open **Site administration → Plugins → Local plugins
   → Connect to VideoQ** (or `/local/videoq/connect.php`), paste the code, and
   connect. Use the code before its ten-minute expiry. A materials-capable
   VideoQ server issues a `VIDEOQ2.` code and configures the LTI registration,
   public key discovery and materials endpoints automatically.
5. Add a video through Moodle's ordinary **File** activity. Enable **VideoQ
   questions** in its settings. Wait for background processing to finish.
6. Open the course-wide VideoQ assistant. Moodle plays the selected video and
   renders the chat using its native messaging templates. VideoQ generates
   streamed answers and supplies citations. Clicking a citation selects its
   permitted video and seeks to the cited time.

Do not paste AI-provider keys, private signing keys or server configuration into
the connection-code field. The [materials-service guide](materials-service.md)
describes operator configuration and migration from legacy `VIDEOQ1.` connections.
Normal setup requires no separate synchronization credential or connector server.

## Responsibilities and access

Moodle stores and plays the original File activity. Enabled videos are exported
to VideoQ storage for transcription, indexing and course-wide retrieval. Source
revisions bind updates to the correct material; deletion tombstones remove the
corresponding VideoQ copy. Reconciliation is asynchronous, so updates can take
more than one scheduler cycle. A failed/empty upstream response does not authorize
mass deletion.

LTI authenticates the learner's launch. Before questions, VideoQ checks Moodle's
currently permitted materials, including visibility and enrollment. A hidden or
inaccessible source must not leak through another learner's citations. Learners
do not need separate VideoQ accounts. Course-level grouping preserves questions
across its enabled videos; enabling an additional File must not replace the
course's previous material list.

The chat uses Moodle's standard `core_message` templates, theme styling and
composer. The hidden LTI frame handles authentication and API calls. It checks
exact origin, source window, per-launch channel and allowed video IDs. Display
messages exclude private tokens and render model HTML as text. Good/Bad ratings
are stored by VideoQ; no Moodle human-message conversation or bot account is
created. JavaScript remains necessary in the browser for streaming, feedback,
video selection and timestamp seeking.

## Disconnect

In the owning VideoQ account, open its connection settings, select the Moodle
connection and choose **Disconnect**. Confirm the displayed connection. Subsequent
launches, questions and synchronization are denied. Existing learning data is
retained. Removing local Moodle settings alone does not revoke server access.
Generate a new code to reconnect through the supported workflow.

## Operations and troubleshooting

Keep Moodle cron/ad-hoc tasks, the VideoQ processing worker and materials scheduler
running. Configure PHP/web upload and request limits, storage capacity and Moodle
File limits for your expected video sizes. Use publicly trusted HTTPS or install
your organization's CA through Moodle's standard trust configuration. Keep peer
and hostname verification enabled. Do not expose signed upload URLs in logs.

If processing stalls, inspect Moodle's VideoQ status, scoped task logs and the
external VideoQ worker/queue health. Check AI credentials on the VideoQ service;
they do not belong in Moodle. For a failed LTI login, check public HTTPS URLs,
client/deployment matching, clock synchronization and key discovery. Clear
Moodle caches after changing tool/plugin configuration through supported APIs.

The [lab](../lab/README.md) provides reproducible PHP/Python/native-browser checks.
[Coverage notes](verification.md) distinguish current checks from historical
acceptance evidence. A local passing run does not certify LTI interoperability or
prove capacity for 100 concurrent learners; test the actual target deployment.
