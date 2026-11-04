# Moodle interoperability lab

An isolated local Moodle + VideoQ + PostgreSQL/pgvector deployment with HTTPS and
an OpenAI-compatible deterministic fixture. Only `127.0.0.1:18443` is published.
The default mode uses lab-only accounts, generated videos and deterministic AI.
Optional live-provider tests explicitly use private local configuration; never
point this lab at production databases.

The actual Moodle login, OIDC authorization, JWT signature verification, key
retrieval, VideoQ sessions, database queries, vector retrieval, quotas and guest
question logs run normally. Only the embedding/model provider is a fixture.
This verifies integration wiring, not AI answer quality. The optional synchronization
lab below also runs media transfer, audio extraction, transcription checkpoints,
indexing, learner questions and physical cleanup through the normal worker.

## Automatic synchronization acceptance test

First set up the basic lab below, including migrations and the existing
`lti-lab-owner` account. Then add private S3 storage, SQS and the normal worker:

```bash
ds() {
  sudo -n docker compose -p videoq-lti-lab \
    --env-file /tmp/videoq-lti-lab/compose.env \
    -f lab/compose.yaml \
    -f lab/compose.sync.yaml "$@"
}
npm --prefix "$VIDEOQ_ROOT" run build:node --workspace @videoq/api
node lab/generate-video.mjs
ds build worker
ds up -d garage queue ai moodle proxy api worker
ds exec -T moodle php admin/cli/upgrade.php --non-interactive
ds exec -T api node dist/migrate-database.mjs
node lab/configure-sync.mjs
node lab/verify-sync.mjs
```

The default worker image is built from the current `Dockerfile.onprem`. An existing
compatible local image can be selected with `LTI_LAB_WORKER_IMAGE` in `compose.env`;
current worker sources are mounted read-only. All service credentials are public
lab fixtures. Only the HTTPS proxy is exposed, on loopback port 18443. The Moodle
container trusts the isolated Caddy CA and uses Moodle's outbound security checks
to pin Docker DNS for the `.localhost` hostnames. The host trust store is unchanged.

This overlay explicitly uses deterministic providers, independently of any
optional paid-provider configuration. `sync-lesson.mp4` and its replacement use
test-tone audio, not a spoken lesson. The Whisper fixture returns the original
slide text; FFmpeg extraction, S3 reads/writes, processing quota reservation, SQS
handoffs, resumable transcription, vector indexing, LTI authentication and RAG
retrieval all run normally. It verifies the integration, not speech recognition
or answer quality, and never inserts transcripts or vectors directly.

The acceptance test enables questions as a Moodle teacher, confirms that a learner
cannot edit settings, asks a question, checks the existing account's usage,
renames/hides/reopens/restricts/replaces/deletes the source, and verifies that old
sessions are refused and retired media/vectors are gone. Hidden lessons reuse
their processed copy on reopening. Availability restrictions also reach the
generated standalone LTI activity. Disable/reenable removes and recreates the
generated activities and remote binding automatically. Tests remove only their dedicated source
activity; run `configure-sync.mjs` before repeating to prepare a new one.

Artifacts in `/tmp/videoq-lti-lab` include `sync-verification.json`,
`sync-ready-teacher.png` and `sync-learner-question.png`. Credentials remain in
the private `sync-credential.json` file and are never included in reports.
`sync-verification-failure.json` is removed after a successful run.

Return to the original lab configuration with the base compose file's
`up -d api moodle proxy ai`; stop the optional worker/queue/storage services when
finished. The original manually bound lesson is separate from the test source.

## Reproduce

Prerequisites: Docker Compose, `npm ci` in this repository and a separate VideoQ checkout,
the Node.js version in `.nvmrc`, Playwright Chromium, FFmpeg, a Japanese font (or
`LTI_LAB_JAPANESE_FONT` pointing to one), Git, and about 3 GB of additional disk space. The
first run downloads the official Moodle PHP image. Run from the repository root.
Use `sudo` for Docker commands if required by your local Docker permissions.

```bash
git clone --depth 1 --branch MOODLE_501_STABLE https://github.com/moodle/moodle.git /tmp/videoq-lti-moodle-source
# Optional: pin the exact source used for the recorded verification.
git -C /tmp/videoq-lti-moodle-source fetch --depth 1 origin a682566412e1d429b95ac8ef4351a518f61b94c5
git -C /tmp/videoq-lti-moodle-source checkout --detach a682566412e1d429b95ac8ef4351a518f61b94c5
mkdir -p /tmp/videoq-lti-lab
```

Create `/tmp/videoq-lti-lab/compose.env` with the following, replacing
`VIDEOQ_ROOT` with the VideoQ application checkout and `MOODLE_INTEGRATION_ROOT`
with this integration checkout. Also export `VIDEOQ_ROOT` in your shell for build commands:

```dotenv
MOODLE_SOURCE=/tmp/videoq-lti-moodle-source
VIDEOQ_ROOT=/absolute/path/to/videoq
MOODLE_INTEGRATION_ROOT=/absolute/path/to/videoq-moodle
LTI_LAB_DIR=/tmp/videoq-lti-lab
```

Export `VIDEOQ_ROOT=/absolute/path/to/videoq` in your shell, then define a convenience function:

```bash
dc() {
  docker compose -p videoq-lti-lab \
    --env-file /tmp/videoq-lti-lab/compose.env \
    -f lab/compose.yaml "$@"
}
```

Build VideoQ and install both databases. The Moodle installation takes roughly a
minute; wait for each command to finish before the next one.

```bash
npm --prefix "$VIDEOQ_ROOT" run build:node --workspace @videoq/api
node lab/generate-video.mjs
dc up -d db moodle proxy ai
dc exec -T moodle php admin/cli/install_database.php --agree-license \
  --adminuser=admin --adminpass='Lti-lab-only-123!' \
  --adminemail=admin@example.invalid --fullname='VideoQ LTI Lab' --shortname=VideoQLab
dc exec -T moodle php admin/cli/upgrade.php --non-interactive
dc run --rm --no-deps api node dist/migrate-database.mjs
dc exec -T moodle php /lab/seed.php > /tmp/videoq-lti-lab/moodle-fixture.json
dc run --rm --no-deps api node /fixtures/seed-videoq.mjs
node lab/configure.mjs
dc up -d api
node lab/verify.mjs
node lab/verify-companion.mjs
dc exec -T moodle php /lab/check-plugin.php
```

`seed.php` is idempotent. Run `seed-videoq.mjs` once per fresh lab database; it
refuses an existing fixture rather than adding duplicate courses. It also refuses
any database URL other than the isolated Compose test URL. Restarting the API
loads changes to `/tmp/videoq-lti-lab/lti-config.json`.

The verifier uses Chromium's localhost domain resolution and ignores this lab's
self-signed HTTPS certificate. The API trusts the Caddy lab CA through `NODE_EXTRA_CA_CERTS`; it does
not disable certificate verification. No `/etc/hosts` or system CA changes are
required. Modern Chromium browsers resolve both localhost names to loopback.

## Try it through SSH port forwarding

With the lab running on the remote host, execute this on your own computer,
replacing `YOUR_SSH_HOST` with the SSH destination you normally use:

```bash
ssh -N -o ExitOnForwardFailure=yes -L 18443:127.0.0.1:18443 YOUR_SSH_HOST
```

Keep that terminal open. First visit
`https://videoq.localhost:18443/health` and accept the local lab certificate
exception if prompted. Then visit
`https://moodle.localhost:18443/local/videoq/view.php?lesson=lms-videoq`, accept its certificate
exception too, and sign in as `learner`. Both sites must be accepted because the
question tool is embedded from a separate origin. Alternatively, import and
trust the lab CA in your browser. This affects only the local demonstration;
production needs a normally trusted HTTPS certificate.

The companion screen places a standard Moodle video File activity next to the
VideoQ question frame. It uses the `local_videoq` plugin under
`plugins/local/videoq`; the ordinary course link is named
「動画を見ながら質問する」. The standalone question activities remain available.
The video is an original 24-second silent lesson. The AI provider gives a fixed
demonstration answer, so use it to try the interface and launch flow, not to
evaluate answer quality. With the optional `videoId` binding, playback position
can be attached to questions and cited scenes can seek the Moodle player.

The test users are `learner` and `teacher`, password `Lti-lab-only-123!`. These
credentials and all credentials in `api.env` are public test fixtures and must
never be used outside this loopback-only lab. The seeded Moodle tool is a
launch-only LTI 1.3 tool: name/email sharing, Deep Linking and grade return are off.
There is no tool public-key endpoint or platform-service call in this test.

Artifacts are written under `/tmp/videoq-lti-lab`:

- `verification.json`: checks performed for each role, without authentication tokens.
- `learner-embedded.png`, `teacher-embedded.png`: actual Moodle embedded launch.
- `learner-mobile.png`, `teacher-mobile.png`: narrow-viewport launch.
- `learner-companion-desktop.png`, `teacher-companion-desktop.png`: video beside questions.
- `learner-companion-mobile.png`, `teacher-companion-mobile.png`: stacked mobile view.
- `companion-verification.json`: playback, layout and access checks.
- `failure.png`: last failed run, if one occurred; it may be stale after success.

Stop the lab when finished; Docker volumes retain its databases and test CA:

```bash
dc stop
```

## Recorded verification (2026-11-03)

Moodle **5.1.8+ (Build: 20261030)**, commit
`a682566412e1d429b95ac8ef4351a518f61b94c5`, official
`moodlehq/moodle-php-apache:8.3`, PHP 8.3, PostgreSQL/pgvector 17,
Node.js 22.23.4, and Playwright Chromium.

For both learner and editing-teacher accounts:

| Check | Result |
| --- | --- |
| Actual Moodle login and embedded LTI 1.3 launch | Pass |
| VideoQ questions and follow-up questions | Pass |
| Retrieval and source title/timestamp display | Pass |
| Replay of consumed login proof | Rejected (401) |
| Request selecting a different VideoQ course | Rejected (400) |
| Unbound Moodle resource link | Rejected |
| New-window launch and question | Pass |
| Question view has no horizontal overflow at 390 px | Pass |

All **47 Drizzle migrations**, including `0046_lti_credentials`, were applied to a
fresh real PostgreSQL database. Questions were persisted as guest-origin logs.
This result covers this Moodle version and these launch modes; Canvas, manaba,
WebClass, LMS mobile apps and blocked-storage browsers have not been verified by
this lab. Playback integration is verified only for the included Moodle companion.

The companion plugin was additionally verified for both roles: video playback
continued while a question was answered, desktop columns and 390 px mobile
stacking worked, anonymous and unenrolled users were refused, and hiding either
the File or LTI activity denied learner access to the companion. Its JSON settings
passed 11 configuration validation checks.

The image tags can change. Container image IDs used for this run (linux/amd64):

| Image | Image ID |
| --- | --- |
| Moodle PHP 8.3 | `1db7b6ed013fcb5268095b5e28c410ba9117d38d9b6a324c100c48fcbe69745e` |
| PostgreSQL/pgvector 17 | `ac08538c6f8b9904c33c8224c5e5706dbe760aca29db1d096972b4052c22a75d` |
| Node 22 | `4b150b48b4116e2a22f2c39cc27025fbf4f153e2078fd3b14d159fdb5c7279da` |
| Caddy | `e70b164da81ef698b3ad19af799ea6086ce6d9e48ed4079eb9fe26f6fbf14ee5` |

## Use real OpenRouter answers

The default lab stays deterministic until explicitly switched. For an actual
AI trial, edit `/tmp/videoq-lti-lab/openrouter.env` on the server (outside the
repository), with file permissions `600`:

```dotenv
OPENROUTER_API_KEY=YOUR_KEY
# Optional:
LTI_LAB_CHAT_MODEL=openai/gpt-4.1-mini
```

The trial uses [GPT-4.1 Mini](https://openrouter.ai/openai/gpt-4.1-mini) for answers
and [Text Embedding 3 Small](https://openrouter.ai/openai/text-embedding-3-small)
with 1536 dimensions for document and question embeddings. OpenRouter API usage
is billed to the configured key. Do not paste the key into chat, commit it, or
put it in the public `api.env` test fixture.

```bash
chmod 600 /tmp/videoq-lti-lab/openrouter.env
node lab/enable-openrouter.mjs
node lab/verify-openrouter.mjs
```

The switch stops only the API, generates real embeddings for all three original
video slides, and commits those vectors, the SRT text and a provider/fingerprint
record together in the isolated database's `lti_lab_ai_state` table. This table
belongs only to the lab, not the production VideoQ schema. Startup reads that
record and refuses a mismatched configuration. A failure before the database
commit leaves the previous mode and index intact; the API is restarted in a
`finally` block. Mock and real embedding spaces are never intentionally mixed.
The source video and indexed slide text share `lesson-scenes.mjs`.

The switch gives the fixture owner 30 additional answers, retaining its existing
usage count. The three-question acceptance check consumes three of those answers.
Restarting the API does not add an allowance; explicitly re-running the switch
reindexes and grants another 30 answers. The test key stays only in the private
file and API memory. The Moodle container mounts only the lesson video from the
lab data directory, so it does not receive this key file.

After switching, refresh the companion page to clear the previous fixed-answer
conversation. Ask, for example, 「LMSは何を管理しますか？」 or
「VideoQでは何ができますか？」. Answers use the actual model and retrieved slide
content. With the companion bridge connected, playback context is attached automatically and source-click
seeking is available, without extra position controls or question suggestions.

`verify-openrouter.mjs` sends three different questions, checks non-fixed answers,
relevance keywords, source video scope and the actual 0/8/16-second slide labels.
It writes `openrouter-verification.json` and `openrouter-companion.png`, without
credentials. The earlier deterministic verifiers are intended to run before
switching; use this paid-provider check after switching.

Configuration checks that do not call a paid provider:

```bash
node --test lab/openrouter-config.test.mjs
```

### Recorded OpenRouter acceptance (2026-11-03)

The isolated trial was switched to real OpenRouter inference using the private
key file. All three scene embeddings were rebuilt. Three questions passed the
live browser check: LMS responsibilities, VideoQ features and the effect of LTI
integration. Answers differed by question and cited the actual demo video's
0/8/16-second scenes. The fixture owner had 27 trial answers remaining immediately
after verification. No API key is included in the recorded artifacts.

## Current playback and citation seeking

Each configured resource may include `videoId`, the matching VideoQ video in
`courseId`. `configure.mjs` adds this field for the lab. It must identify the same
video shown by the Moodle File activity; the operator owns that association.
Changing the registration revokes existing tool sessions, so refresh the lesson.

```bash
node lab/verify-playback.mjs
# Paid-provider check: one question, not three.
node lab/verify-playback.mjs --live
```

The default check intercepts only the question response and consumes no paid
answers. It verifies origin/window/channel checks, invalid video/time rejection,
automatic playback context, citation filtering and seeking with playback, stale position updates and mobile player visibility. Both decimal
dot and SRT decimal comma timestamps are supported. The live check was verified
on 2026-11-03: at 10 seconds, 「今の場面について教えてください。」 returned a
VideoQ explanation, and clicking its cited 8-second source sought the Moodle
player to 8 seconds while preserving its paused state.

Artifacts: `playback-check.json` / `playback-check.png` for the no-cost check and
`playback-live.json` / `playback-live.png` for the actual-model check. Current
position is a learner-controlled question hint, not a viewing/completion proof.
No LTI or API credential is sent through the postMessage bridge.

## Moodle-aligned UI verification

The companion now uses Moodle's native Video.js player and course header, while
the chat inherits the connected theme palette and font stack. The chat message
list scrolls independently, its composer remains visible, and reference chips
retain citation seeking. There is one visible lesson heading.

```bash
node lab/verify-design.mjs
```

The check makes no paid AI calls. It verifies safe rich text, a simple input without question
suggestions or position controls, Japanese IME input, reversible theme inheritance, rejection of a
forged theme message, long conversation scrolling, playback and layouts at
390/768/1280/1440 pixels. Results go to `design-verification.json`; screenshots
include `design-desktop-empty.png`, `design-desktop-chat.png` and
`design-mobile-chat.png`.

The local demo's Moodle UI and test-user preferences were switched to Japanese.
To reproduce that optional change when the container's outbound download policy
blocks the language importer's request, fetch the official pack on the host:

```bash
curl --fail --location https://download.moodle.org/download.php/langpack/5.1/ja.zip \
  -o /tmp/videoq-lti-lab/ja.zip
dc cp /tmp/videoq-lti-lab/ja.zip moodle:/tmp/videoq-ja.zip
dc exec -T -u www-data moodle php /lab/localize.php /tmp/videoq-ja.zip
dc exec -T moodle php admin/cli/purge_caches.php
```

The helper validates archive paths and changes only the isolated lab and its two
trial user preferences. The lesson layout and LTI functionality do not depend on
installing a Japanese language pack.

### Multi-video course trial

The companion now needs both `local_videoq` and `ltisource_videoq`; Compose mounts
both. After recreating Moodle with the updated Compose file, run its plugin
upgrade. The existing demo URL remains the course's entry point.

The course trial adds an original second lesson and a hidden negative-test lesson:

```bash
node lab/generate-course-videos.mjs
dc cp /tmp/videoq-lti-lab/course-review.mp4 moodle:/tmp/course-review.mp4
dc cp /tmp/videoq-lti-lab/course-hidden.mp4 moodle:/tmp/course-hidden.mp4
dc exec -T moodle php /lab/prepare-course.php > /tmp/videoq-lti-lab/course-moodle.json
dc exec -T api node /fixtures/index-course.mjs
dc restart api
node lab/verify-course.mjs
dc exec -T moodle php /lab/check-course-access.php
```

`index-course.mjs` uses the configured OpenRouter connection for one document
embedding batch; it leaves the existing answer allowance unchanged. It is only
for this isolated demo, where Moodle serves the media and VideoQ receives the
matching original transcripts. Production synchronization still transfers and
processes the actual videos using the existing worker.

`verify-course.mjs` launches through real Moodle/LTI, checks the allowed catalog,
uses a fake answer for free player verification, checks cross-video citation
seeking and selection while preserving the conversation, and verifies a real
403 for playback context referring to a hidden video. Add `--live` to make one
real AI question spanning the first and second lessons. This writes
`course-live.json`; regular results are in `course-check.json`, with desktop and
mobile screenshots. Permission tests temporarily hide activities, apply a future
availability date and suspend the learner/enrolment, restoring every change in
`finally`. Never run these fixtures against another Moodle installation.

### Native Moodle messaging UI

The companion uses Moodle's existing `core_message` message and composer
Mustache templates, the site's message styles and `core/auto_rows`. Its visible
chat is rendered on the Moodle origin; the LTI iframe is an off-screen transport
that retains authentication and API credentials. AI generation and evidence
retrieval still run in VideoQ. This does not create a human-message conversation
or notifications in Moodle.

`verify-design.mjs` now tests the native UI, standard send/loading icons and
textarea sizing, theme inheritance, safe text rendering, IME input, long-chat
scrolling, request retry and failed initial connection. `verify-course.mjs` and
`verify-playback.mjs` submit through this native UI and retain their course/seek
checks. Generic LTI question-view browser tests also verify the renderer protocol,
forged-command rejection, request-ID replay rejection, and that no credentials
cross the frame boundary.

## Shared course entry and real-provider acceptance

The current real-provider demo uses one school installation and one VideoQ course
for the chapter videos and uploaded practice video. The course page contains one
`AIアシスタント（講座全体に質問）` entry in General; chapter sections contain
ordinary Moodle video File activities. The common URL is
`/local/videoq/view.php?course=2`, independent of any particular video. It selects
a permitted, processed source at launch, and shows an empty state if none exists.
Legacy lesson bookmarks follow the source's migrated synchronization binding.

Keep all three Compose files active for this configuration:

```bash
dl() {
  sudo -n docker compose -p videoq-lti-lab \
    --env-file /tmp/videoq-lti-lab/compose.env \
    -f lab/compose.yaml \
    -f lab/compose.sync.yaml \
    -f lab/compose.live-sync.yaml "$@"
}
dl up -d videoq-sync videoq-maintenance
```

`videoq-sync` executes only the Moodle VideoQ scheduled task every 30 seconds.
`videoq-maintenance` drains VideoQ recovery/storage tasks every 30 seconds. Both
have heartbeat health checks. They do not replace production Moodle cron,
monitoring or backups. Running the base Compose configuration alone switches
away from real storage/provider synchronization.

The live worker loads its OpenRouter provider JSON from the private
`/tmp/videoq-lti-lab/worker-provider.json`, readable only by its worker user.
The API reads the existing private `openrouter.env`. Do not put these credentials
in version control, reports, command arguments or chat.

Recheck the organized course and its permission handling without paid inference:

```bash
node lab/verify-organization.mjs
node lab/verify-course-entry.mjs
LTI_BROWSER=firefox node lab/verify-course-entry.mjs
LTI_BROWSER=webkit node lab/verify-course-entry.mjs
node lab/verify-course.mjs
node lab/verify-streaming.mjs
node lab/verify-design.mjs
node lab/verify-playback.mjs
```

`verify-course-wide-live.mjs` makes one real AI request and asserts that all three
actual, automatically transcribed videos are cited. `verify-classroom-load.mjs`
authenticates 100 temporary Moodle learners through the real browser/LTI flow,
closes the browser contexts, and starts their real SSE questions together. It
measures answer serving, not 100 simultaneous browser video decoders. The default
run requires sufficient existing lab answer quota. Explicitly allowing
`LTI_LAB_ALLOW_QUOTA_TOPUP=1` raises only the isolated `lti-lab-owner` fixture's
allowance to cover that test, leaving production accounts untouched. Real calls
consume the configured provider's credits. Test learners are removed afterward.
`verify-classroom-media.mjs` separately checks 100 simultaneous authenticated
Moodle Range reads against the uploaded media and compares every payload hash.
It does not measure a school's Internet bandwidth or continuous video playback.

The long-video acceptance fixture lasts 30 minutes, uses real speech processing
in six durable chunks, and includes a distinct closing checkpoint. The verifier
asserts the closing cue near the end, asks a real question about it, and deletes
only its disposable source, checking database/vector/object cleanup. The
scheduled-sync acceptance separately proves that teacher enablement, transfer,
processing, publication and deletion finish without manual cron or maintenance
commands. These runs use original generated test speech, not student data.

Detailed JSON evidence lives under `/tmp/videoq-lti-lab/acceptance/`. Database
backups remain private in its restricted `backups` directory; do not publish them.

## View the original VideoQ frontend

The optional `compose.web.yaml` overlay serves the unmodified VideoQ web app at
`https://videoq.localhost:18443`, using the same owner account and database as the
Moodle sync. API/LTI/health requests keep routing to the API; all other paths use
the frontend. The existing SSH forwarding on port 18443 is sufficient.

```bash
node lab/prepare-web-library.mjs
VITE_API_URL=/api VITE_DEPLOYMENT_EDITION=onprem \
  npm --prefix "$VIDEOQ_ROOT" exec --workspace @videoq/web -- vite build \
  --outDir /tmp/videoq-lti-lab/web-dist --emptyOutDir
sudo -n docker compose -p videoq-lti-lab \
  --env-file /tmp/videoq-lti-lab/compose.env \
  -f lab/compose.yaml \
  -f lab/compose.sync.yaml \
  -f lab/compose.live-sync.yaml \
  -f lab/compose.web.yaml up -d --no-deps web proxy
```

The prepared trial owner uses username `videoq_lab` and the public lab-only
password `Lti-lab-only-123!`. Its internal ID remains `lti-lab-owner`, so school
ownership and LTI bindings do not change. The original fixture username contained
hyphens, which the real username sign-in validator rejects; the login-compatible
trial username uses an underscore. No pre-existing credential is replaced.

Open `/videos/courses/7` to view the synced Moodle lab course, or
`/videos/courses` for the owner's course list. `verify-videoq-web.mjs` checks the
real sign-in, course display and decoded media playback. Use all four overlays
when recreating the proxy to retain the original frontend route.


`prepare-web-library.mjs` uploads the original demo media into the isolated bucket
and normalizes the earliest fixture to the supported `uploaded` source type.
The initial LTI-only fixtures did not need VideoQ-hosted media, but the original
library and detail pages do. An unsupported fixture source type otherwise fails
the entire strict library response. `verify-videoq-library.mjs` checks the library
response, actual card navigation and decoded playback of all currently displayed trial videos.


After migrating the initial manual demos to automatic sync, use
`retire-legacy-fixtures.mjs` to remove only those obsolete duplicates. It uses the
normal owner-authenticated delete API and refuses to delete any active LMS
resource. The retirement marker prevents media preparation from recreating them.
Run `LTI_VERIFY_LIBRARY=1 node lab/verify-moodle-source-deletion.mjs`
to exercise actual source deletion and automatic removal from the open library;
its disposable course and media are cleaned up afterward.
