---
title: Connect an LMS with LTI 1.3
description: Connect Moodle to an existing VideoQ account and automatically synchronize video lessons.
---

# Connect an LMS with LTI 1.3

For the new LTI-authenticated materials mode without a synchronization API key,
use the [materials-service setup and migration guide](materials-service.md).
It requires a compatible VideoQ server and its tool signing-key configuration.
The following sections retain the legacy setup for existing deployments.

## Legacy setup: connect Moodle with a one-time code

For a new Moodle installation, the administrator can avoid manual LTI identifiers,
connection JSON and operator registration commands:

1. Install **both** `local_videoq` and `ltisource_videoq` in Moodle and finish its
   plugin upgrade. Build the standard installer archives with
   `php scripts/package-plugins.php OUTPUT_DIRECTORY`; install
   `local_videoq.zip` and then `ltisource_videoq.zip` through Moodle's plugin installer.
2. Sign in to the school's existing VideoQ account. Open **Settings → Connect
   Moodle**, enter the exact HTTPS Moodle base URL (including a subdirectory, if
   present), and generate a connection code.
3. Follow **Open Moodle connection settings**, sign in as the Moodle administrator,
   paste the code, and click **Connect**.

The plugin creates an embedded launch-only LTI 1.3 external tool, exchanges its
actual client/deployment identifiers, saves the synchronization credential, and
checks the connection. The VideoQ account and Moodle URL are fixed when the code
is issued. Codes expire after ten minutes and bind only one connection; issuing a
new code invalidates that account's previous unused code. Only digests are stored.
A retry with the same code recovers a committed registration after an HTTP response
is lost. A fresh code can replace an expired unregistered attempt. Unregistered
pending setup can be cancelled in Moodle; registered connections are never
silently overwritten or rebound. Credentials never enter URLs or browser storage.

The operator must first apply all current migrations and set API `LTI_CONFIG` to
`{"toolOrigin":"https://api.videoq.example","databaseRegistrations":true,"registrations":[]}`.
Keep Moodle cron and the VideoQ worker, queue, storage and recovery scheduler
running. This setup flow does not install infrastructure or add LTI Advantage
services. Existing quotas and the Moodle permissions described below still apply.

Teachers then add a video File activity and enable **VideoQ questions**. Learners
use Moodle login and the course-wide assistant. Manual provisioning remains
available for existing installations:

## Automatic Moodle synchronization

The Moodle companion can synchronize video File activities to **one existing
VideoQ account owned by the school**. Teachers and learners do not need individual
VideoQ accounts. Existing account billing, answer/transcription quotas, storage
and monthly upload allowances apply; there is no separate organization model.

Initial setup is performed once by the Moodle administrator and VideoQ operator:

1. Apply all current migrations (including `0048_lms_courses` and
   `0049_group_lms_courses`) and prepare the school's active
   VideoQ account using the existing account/billing/administration workflows.
2. Register a Moodle LTI 1.3 external tool using the login, launch and redirect
   URLs in the manual-mode instructions below. Obtain its actual issuer, client
   ID and deployment ID.
3. Configure the API's `LTI_CONFIG`:

   ```json
   { "toolOrigin": "https://api.videoq.example", "databaseRegistrations": true, "registrations": [] }
   ```

4. Create an operator connection file:

   ```json
   {
     "id": "school-moodle",
     "ownerUserId": "EXISTING_VIDEOQ_ACCOUNT_ID",
     "name": "School Moodle",
     "issuer": "https://moodle.example",
     "clientId": "CLIENT_ID_FROM_MOODLE",
     "deploymentId": "DEPLOYMENT_ID_FROM_MOODLE",
     "authorizationUrl": "https://moodle.example/mod/lti/auth.php",
     "jwksUrl": "https://moodle.example/mod/lti/certs.php"
   }
   ```

5. With the API database configured in `DATABASE_URL`, register the connection:

   ```bash
   npm --prefix "$VIDEOQ_ROOT" run build:node --workspace @videoq/api
   cd "$VIDEOQ_ROOT/apps/api"
   node dist/provision-lms.mjs register connection.json /private/moodle-credential.json
   ```

   The output file must not exist. It receives the synchronization key with
   permissions `0600`; the key is not printed. Do not register the same
   platform/client in both static `registrations` and the database.
6. Install/upgrade the Moodle companion. In its administrator settings, enter
   the API origin, synchronization key and Moodle LTI tool type ID (deployment
   ID). Run Moodle cron every minute, and keep the normal VideoQ worker, queue,
   storage and recovery scheduler running.

Teachers then open **VideoQ questions** from a video File activity's settings
navigation and enable the feature. The plugin creates the LTI question activity
and a combined video/question link. Background synchronization uploads the video,
binds the VideoQ course, and requests transcription/indexing. The same Moodle page
shows processing state and provides retry/disable actions. No manual VideoQ
uploads or per-lesson server configuration changes are required.

Replacements, renames, visibility/availability changes and deletion are reconciled
when the synchronization task runs, normally on the next minute. Changed resource
generations revoke old sessions. A replacement cannot answer questions until its
new video has completed processing. Quota failures leave the old binding revoked;
after capacity is available, synchronization retries. Physical file cleanup uses
the durable VideoQ maintenance outbox. Moodle still enforces per-learner activity
access at launch; this is not roster or enrolment synchronization.

Hiding a lesson retains its processed video but removes the LTI grant; reopening
does not consume additional transfer or processing allowance. Disabling the
feature or deleting the source removes the copy and the generated Moodle activities.
Reenabling recreates them automatically. The generated question activity
and combined link inherit source visibility and availability restrictions, so
they cannot bypass a restricted source. Manage these settings on the source File
activity.

Supported sources are individual video File activities up to 5 GiB. Moodle sends
videos directly to signed S3/R2 URLs when configured; it never sends the connection
key to object storage. The plugin may spool a temporary file and needs enough
temporary disk space. Normal TLS and Moodle outbound-HTTP restrictions apply.

To rotate the key, provide `{"ownerUserId":"...","installationId":"..."}` to
`node dist/provision-lms.mjs rotate INPUT.json NEW_PRIVATE_OUTPUT.json`, then update
the Moodle setting. The old key is immediately invalid. A suspended/deactivated
VideoQ account or disabled connection cannot synchronize or launch questions.

The [synchronization acceptance lab](https://github.com/yukiharada1228/videoq-moodle/tree/main/lab)
checks the complete Moodle → storage → worker → question flow and replacement /
deletion with deterministic inference providers on Moodle 5.1.8+.

## Manual LTI mode

VideoQ includes an initial **LTI 1.3 resource-link question tool**. An LMS launches
a question-only HTML view; the existing VideoQ RAG pipeline answers from a
manually bound VideoQ course. Each course can contain one video for a single LMS
video lesson, or several videos for a broader lesson.

This initial implementation has been verified against a local **Moodle 5.1.8+**
deployment for embedded and new-window launches, with learner and teacher roles.
It is **not LTI-certified**; Canvas, manaba and WebClass remain unverified. See the
[reproducible Moodle lab and recorded checks](https://github.com/yukiharada1228/videoq-moodle/tree/main/lab).
Verify your own LMS version and deployment before rollout.

## Supported scope

- OIDC third-party login initiation, followed by an RS256-signed
  `LtiResourceLinkRequest`.
- Exact issuer/client/deployment/context/resource-link bindings controlled by the
  VideoQ operator. Unbound resources and unrecognized roles are refused.
- A question-only, responsive view that can appear inside an LMS frame or in a
  separate window. Cookies and a second VideoQ login are not required.
- Existing retrieval, source labels, course-owner quotas and guest question logs.
- Short-lived, browser-bound login state (5 minutes) and course-scoped sessions
  (1 hour). Credential expiry is enforced when read; daily maintenance deletes
  expired rows.

Manual LTI mode does not include Deep Linking, dynamic registration, LTI 1.1, roster provisioning,
grade return, automatic video/transcript import, generic player position synchronization,
or automatic placement beside an existing LMS video player. A separate
[Moodle companion plugin](https://github.com/yukiharada1228/videoq-moodle/tree/main/plugins/local/videoq)
provides a manually configured side-by-side view for Moodle 5.1 File and LTI
activities. With an optional `videoId` on the resource binding, it also supports
current-position hints and citation seeking through a restricted player bridge.
It does not modify existing activity pages.

LMS identity and roles are checked at launch. A hash of issuer/client/deployment/
subject is retained in the short-lived session and used for throttling. Names and
email addresses are not imported, and no local learner account is created.
Questions use the existing guest-origin logging model: the course owner can see
questions, but they are **not attributed to individual LMS learners**. Conversation
context exists in the question view while it is open; it cannot retrieve another
learner's conversation or history. Reloading requires launching from the LMS again.

## Configure VideoQ

The commands in this section run in the **VideoQ application** checkout, not
this Moodle repository. Set `VIDEOQ_ROOT` to that checkout when building from here.

1. Apply the generated `0046_lti_credentials` migration **before deploying the API**:

   ```bash
   npm run db:migrate
   ```

2. Create a VideoQ course containing only the videos that learners of this LMS
   resource are allowed to ask about. Upload/process those videos through the
   normal workflow. A public share slug is **not required**.

3. Supply `LTI_CONFIG` to the API as a JSON string. For Cloudflare, set it with
   `wrangler secret put LTI_CONFIG --env production` from `apps/api`; for the Node
   runtime, set the environment variable. Treat it as operator-controlled
   authorization configuration and keep it out of frontend bundles.

   ```json
   {
     "toolOrigin": "https://api.videoq.example",
     "registrations": [
       {
         "id": "moodle-test",
         "issuer": "https://moodle.example",
         "clientId": "CLIENT_ID_FROM_MOODLE",
         "authorizationUrl": "https://moodle.example/mod/lti/auth.php",
         "jwksUrl": "https://moodle.example/mod/lti/certs.php",
         "frameOrigins": ["https://moodle.example"],
         "resources": [
           {
             "deploymentId": "DEPLOYMENT_ID_FROM_MOODLE",
             "contextId": "CONTEXT_ID_FROM_MOODLE",
             "resourceLinkId": "RESOURCE_LINK_ID_FROM_MOODLE",
             "courseId": 123,
             "videoId": 456
           }
         ]
       }
     ]
   }
   ```

   Use values issued by your LMS rather than assuming IDs or endpoint paths.
   `toolOrigin` is the externally accessible **API origin**, without a trailing
   slash. All configured URLs require HTTPS, including development deployments;
   use an HTTPS test deployment or proxy. The existing Docker development script
   does not forward `LTI_CONFIG` automatically.

   `frameOrigins` contains exact origins for all LMS ancestors that frame the
   tool, without wildcards or paths. This permission applies only to the LTI HTML
   view; the main VideoQ app remains outside this framing exception.

4. Leave `LTI_CONFIG` unset to disable LTI. Editing a registration invalidates its
   existing sessions. Removing a resource binding revokes access on the next
   question. The course owner's current active/ban state is checked when the
   course is loaded, and existing answer quotas still apply.

## Register a Moodle test tool

In Moodle's administrator-managed external-tool configuration, select LTI 1.3
and enter:

| Setting | VideoQ value |
| --- | --- |
| Tool / target URL | `https://api.videoq.example/api/lti/launch` |
| Initiate login URL | `https://api.videoq.example/api/lti/login` |
| Redirect URI | `https://api.videoq.example/api/lti/launch` |
| Display | Embedded or new window, depending on site policy |

Copy the LMS issuer/platform ID, client ID, deployment ID, authorization endpoint
and public key-set URL into the VideoQ registration. Create an external-tool
activity, then obtain its context ID and resource-link ID using the LMS's admin
or development tools and add the exact binding to `resources`. These are LTI
identifiers; do not assume that course titles or visible course numbers are the
same values. If inspecting a test launch, inspect it locally; do not paste ID
tokens into public JWT debugging sites.

This first version verifies platform signatures but does not call LTI Advantage
services or issue tool-signed JWTs. It has no tool JWKS endpoint. If your LMS
requires a tool key configuration even for launch-only tools, that registration
flow needs additional implementation before claiming support; do not insert
placeholder keys into a production registration.

See the [Moodle LTI external-tool documentation](https://docs.moodle.org/en/LTI_External_tools),
the [LTI 1.3 specification](https://www.imsglobal.org/spec/lti/v1p3), and the
[1EdTech security framework](https://www.imsglobal.org/spec/security/v1p0).

## Verify before rollout

- Launch as a learner and instructor. Check that no additional VideoQ login is
  requested, and that answers reference only the bound VideoQ course's videos.
- Launch from a different course/resource/deployment: it must be refused until
  an operator adds that exact binding. LMS custom parameters cannot select an
  arbitrary VideoQ course.
- Ask two related questions; confirm that context is retained in that view and
  that another learner cannot see its conversation.
- Remove the resource binding while the view is open; the next question must be
  refused. Check expired sessions and suspended course owners as well.
- Verify desktop, mobile, an embedded launch and a new-window launch. Login state
  uses `sessionStorage` in the tool frame. If the browser or LMS sandbox blocks
  storage/scripts, or changes the browsing context during login, use a consistent
  new-window launch. No cookie fallback is provided.
- Confirm that the API reverse proxy does not override the LTI page's CSP with
  `X-Frame-Options: DENY` or another incompatible `frame-ancestors` policy.
- Test the LMS's own side-by-side layout separately. LTI does not automatically
  insert the question view beside an existing video or expose that player's
  current timestamp. Those integrations depend on each LMS's extension points.

Local checks:

```bash
npm run typecheck --workspace @videoq/api
npm run test:unit --workspace @videoq/api -- test/lti-protocol.test.ts test/lti-routes.test.ts
LTI_BROWSER_TESTS=1 npm run test:unit --workspace @videoq/api -- test/lti-view.test.ts
npm run db:verify
```

The optional browser check requires a Playwright Chromium installation. Protocol
and route tests cover signed-claim validation, browser binding, replay, strict
course scope and revocation. They do not replace a live LMS interoperability test.

### Course-wide Moodle conversations

Install the companion's additional `ltisource_videoq` plugin into
`mod/lti/source/videoq`, then run Moodle's plugin upgrade and VideoQ's database
migrations. Synchronized videos now share one VideoQ course per Moodle course and
installation. Existing per-video resource bindings are consolidated by migration
0049 without deleting original courses.

The Moodle source plugin supplies a signed access proof at LTI launch. VideoQ
checks that proof with Moodle before every question and intersects the returned
per-learner activity list with its current course/resource bindings. Hidden,
restricted, disabled, deleted and unprocessed videos are excluded from retrieval;
failed permission checks deny the request. This supports video-specific viewing
conditions inside a course. Ordinary LTI course membership alone does not imply
permission to search every activity.

The companion can switch between permitted lectures while keeping the same
conversation. Selecting a cited timestamp in another lecture switches the player
to that video and plays the cited scene. Moodle continues to serve and authorize
the media. This live Moodle permission exchange is a companion extension, not a
standard LTI feature; another LMS needs an equivalent permission adapter before
learner-restricted activities can share a searchable VideoQ course.


### Moodle-native conversation display

The companion's question panel reuses Moodle's standard `core_message` message
and composer templates, active-theme styling, send/loading icons and textarea
sizing. The visible UI runs on Moodle's origin. A hidden LTI frame holds the
VideoQ session and delivers validated questions/structured answers through a
restricted renderer protocol; tokens never cross that boundary. AI generation,
course evidence and question records remain in VideoQ. No human direct-message
thread or Moodle notification is created. Standalone LTI launches use VideoQ's
fallback UI.

### Verified Moodle lab scope

The isolated Moodle 5.1.8 / Boost lab has exercised actual Moodle File uploads,
real S3-compatible storage and queue delivery, the media worker, OpenRouter
speech recognition and embeddings, and grounded streaming answers. The original
100 MiB upload contains approximately 27 seconds of speech. A separate 30-minute
spoken video completed six durable transcription chunks; its closing checkpoint
was indexed and cited in a real answer. The temporary long source was then
deleted, and its media, vectors and database record were removed.

One course-wide entry now searches all three current chapter/practice videos
under the same installation/course authority, including newly added videos.
Real cross-video retrieval cited all three. The entry resolves a currently
permitted source dynamically and handles an empty course without launching LTI.
Old bookmarks follow migrated source bindings. Hiding a video revokes an open
session's ability to ask about it. Course navigation and citation playback passed
in Playwright Chromium, Firefox and WebKit on Linux. Seeking waits for decodable
media, and native questions carry a validated playhead snapshot taken at submit.

One hundred distinct Moodle-authenticated learners sent real AI questions at the
same time; all 100 succeeded. The lab's measured p95 was approximately 6.7 seconds.
These learners share the school's VideoQ account; no additional VideoQ users are
created. A separate quota-exhaustion run correctly refused requests beyond the
school's allowance without consuming failed answers. Node scene search borrows
the bounded application pool rather than opening a connection per concurrent
answer. LTI transport has a classroom-sized IP burst limit plus a separate launch
limit; questions and feedback retain a verified-learner sustained limit.

Lifecycle checks cover rename, visibility, availability, replacement, disable,
reenable and deletion. API/worker interruptions recovered during the real-provider
upload. Database interruption returned readiness 503 and recovered after restart.
Database dumps were restored into disposable databases; Moodle filedir and
VideoQ media restores matched the uploaded media checksums. The lab runs separate
periodic VideoQ sync and recovery/cleanup services with heartbeat health checks.

These are lab acceptance results, not certification of arbitrary production
infrastructure. The Moodle upload limit is currently 2 GiB (shown as 2 GB in the UI). Other Moodle
versions/themes, Safari on Apple devices, continuous class video playback over
school Internet links, scheduled backup retention, alert delivery and full
infrastructure disaster recovery require deployment-specific validation. Local
evidence is written under `/tmp/videoq-lti-lab/acceptance/`; private database dumps
stay in its restricted `backups` directory.

### Course page organization

The demo places one course-wide AI assistant entry in General, with ordinary
Moodle video File activities in chapter sections. Learners can also switch
videos in the shared player/question workspace. LTI transport activities and
per-video question shortcuts remain available without appearing as duplicate
course-page entries. This uses Moodle stealth visibility, preserving the
underlying visibility and availability checks.

When stealth activities are enabled and supported by the course format,
enabling synchronization keeps its LTI transport and per-video shortcut off
the course page. Synchronization preserves the activities' course-page display
state and moves the managed companions with the source's section. A stable common
course entry is created in General when synchronization is enabled; it resolves
a permitted processed video dynamically instead of binding its URL to one video.

For the isolated demo only, run `php /lab/organize-course.php` inside the Moodle
container. It enables stealth activities, organizes the videos into chapters,
places the common assistant entry in General and hides duplicate shortcuts.
Teachers can still see the underlying activities while editing.

### Removing internal managed activities

Deleting an internal LTI activity or its per-video shortcut does not disable the
source's question feature. While synchronization remains enabled and the source
still exists, the scheduled task recreates the missing managed activities and
rebinds the existing processed video. Moving a source and deleting its old
section follows the same repair path. The media, transcription checkpoints and
scene index are preserved. Permission checks reject the missing/retired launch
until repair completes, and the repaired activities inherit the source's current
visibility and availability.

To stop questions intentionally, use the source activity's VideoQ settings and
choose disable, or delete the source video. Those actions continue to revoke the
binding and remove the remote copy. Sources already scheduled for Moodle deletion
cannot be re-enabled. The isolated `verify-managed-repair.mjs` exercise covers
shortcut-only deletion, LTI-only deletion, section cleanup, and explicit disable
in a disposable course without changing the real course's lessons.

### One conversation per learner and course

The public Moodle assistant URL is `view.php?course=<courseid>`. A video link
adds `&video=<source-cmid>` to preselect the video; it opens the same course
assistant rather than creating an independent video chat. Old `?lesson=...`
bookmarks redirect after the usual Moodle activity authorization. Teacher links
and managed shortcuts use the common URL as well.

Completed turns are associated with the verified LTI learner, installation and
VideoQ course. The latest 50 turns can be resumed for 24 hours after the last
answer, across video links, reloads and tabs. The existing chat log remains the
answer store; the expiring history record keeps server-issued log references and
the original question. Failed/incomplete answers are not added. The current
Moodle access proof is checked again when history is read; reducing the video
scope discards prior context, and answers referencing inaccessible sources are
excluded. Another learner, course or school cannot reuse that history key.

History feedback references are freshly bound to the current LTI session.
Neither owner log IDs nor feedback credentials are sent into the Moodle renderer.
Questions, answers, citations and rating state are rebuilt using the existing safe
renderers. `verify-shared-conversation.mjs` verifies the real two-answer flow with
a disposable learner, including deep links, a second tab, model context and
restored feedback. It uses the configured paid provider.

### Source deletion and the VideoQ library

Deleting the original Moodle video activity sends a tombstone through periodic
synchronization. VideoQ removes that video's record, transcript, scene index and
stored media through durable cleanup; the other source mappings are preserved.
The original frontend library and status totals refresh on return to the page
and every 30 seconds while visible, so external Moodle changes are reflected
without a manual reload. Ongoing processing still uses the faster three-second
cadence. `verify-moodle-source-deletion.mjs` with `LTI_VERIFY_LIBRARY=1` covers
source deletion, physical cleanup and disappearance from an already-open library.

The manually seeded pre-sync demo duplicates in the isolated lab are retired by
`retire-legacy-fixtures.mjs`. Its guards protect all active LMS resources; normal
authenticated VideoQ deletion handles their media and index cleanup. Once retired,
`prepare-web-library.mjs` does not recreate those obsolete demo records/assets.

## Disconnect Moodle without deleting learning data

In VideoQ **Settings → Connect Moodle**, select **Disconnect** next to the site,
review the explanation, then choose **Confirm disconnect**. Only the signed-in
connection owner can revoke it. New synchronization requests and LTI launches,
questions, history reads and feedback for that installation are denied. Unused
connection codes for that account/site are also invalidated. Other connections
are unaffected. Already authorized work may finish.

Moodle activities and VideoQ courses, video copies, transcripts, indexes and saved
question logs are retained; normal retention limits still apply. Disconnection
does not queue video or object-storage deletion. The entry remains in Settings
marked **Disconnected**. Moodle keeps its settings and may report a connection
error. This action does not automatically rebind or reset those settings.
