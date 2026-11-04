# VideoQ companion for Moodle

## Automatic video synchronization

Use a single existing school-owned VideoQ account for its billing and allowances.
Teachers and learners use Moodle without personal VideoQ accounts. There is no
separate organization model.

For a new connection:

1. Install both `local_videoq` and `ltisource_videoq` and finish Moodle's plugin upgrade.
2. In the school account's VideoQ **Settings → Connect Moodle**, enter this Moodle's
   base URL and generate a connection code.
3. In Moodle **Site administration → Plugins → Local plugins → Connect to VideoQ**,
   paste the code and click **Connect**. LTI registration and sync settings are automatic.

Codes last ten minutes and bind only the Moodle URL entered in VideoQ. After a
network error, retry with the same code. Existing connections are preserved.
Run Moodle cron every minute. The operator must first apply current VideoQ
migrations, enable database LTI registrations, and run its worker/queue/storage
and recovery scheduler. See the
[materials-service setup guide](../../../docs/materials-service.md).
A compatible server with tool signing keys issues `VIDEOQ2.` codes and uses LTI
service authentication without a sync API key. Existing connections can update
or reconnect from the collapsed section of the Moodle connection page. Legacy
`VIDEOQ1.` setup and manual provisioning remain compatible.

Teachers can then enable **VideoQ questions** in a video File activity's settings
navigation. The plugin creates the question activity and combined lesson link,
transfers the video, and shows processing state. Video replacement, rename,
visibility/availability changes and deletion are automatically synchronized.
Teachers can retry or disable the feature from Moodle.

File size must fit both Moodle/PHP limits and VideoQ's reported upload maximum.
The local lab allows 2 GiB; larger transfers need deployment-specific testing. The source
stays on Moodle; VideoQ processes its copy with the normal worker. Temporary disk
space is needed while transferring. HTTP retries do not duplicate videos or
upload usage; ordered generations prevent stale updates restoring an old lesson.
A replaced lesson is unavailable for questions until processing completes.
Physical cleanup is delivered through VideoQ's maintenance outbox.
Hiding a source retains its processed copy; reopening does not retransmit or
reprocess it. Source availability and visibility are also applied to the generated
question activity and combined link. Manage these settings on the source File.
Disabling the feature or deleting its source also removes the generated Moodle
activities. Reenabling creates new ones automatically.

Connection keys stay in administrator settings and are never sent to learner
browsers or object storage. TLS and Moodle's outbound HTTP policy apply. Status
messages do not expose credentials, signed upload URLs or provider diagnostics.

The [synchronization lab](../../../lab/README.md#automatic-synchronization-acceptance-test)
verifies the full lifecycle with real storage/queue/worker processing and
deterministic inference providers on Moodle 5.1.8+.

## Manual companion mode

A Moodle 5.1 local plugin that places an existing Moodle video File activity next
to an existing VideoQ LTI 1.3 activity. Desktop uses two columns; screens up to
900 px stack video above questions. The video stays on Moodle and the question
view stays on the VideoQ origin. With a configured VideoQ video binding, the companion exchanges playback
position and citation-seek commands with the question frame. Question text, API
credentials and learner identity never cross this player bridge.

This initial plugin has been tested with Moodle 5.1.8+ and the Boost theme.
It does not modify Moodle core. It is licensed GPL-3.0-or-later, as indicated in
the PHP source headers, independently of the VideoQ application license.

## Install and configure manual associations

1. Copy this `videoq` directory to `public/local/videoq` in Moodle 5.1, and
   `plugins/ltisource/videoq` to `public/mod/lti/source/videoq`.
2. Run `php admin/cli/upgrade.php --non-interactive` from the Moodle root.
3. In the same course, create a standard **File** activity containing an MP4 and
   an **External tool** activity configured for VideoQ LTI 1.3. The external tool
   must use embedded display, not a new-window or replace-window launch.
4. For manual associations, bind that external-tool resource to the correct VideoQ
   course in `LTI_CONFIG` and set `courseAccess: "moodle"` on the registration.
   The plugin does not upload the Moodle video or its transcript to VideoQ.
   For playback hints and citation seeking, add `videoId` to that resource entry
   in `LTI_CONFIG`, using the matching VideoQ video ID. VideoQ checks that the
   video belongs to the bound course. Refresh the lesson after changing bindings.
5. Under Site administration > Plugins > Local plugins > VideoQ companion, set
   **Video and question tool associations** to a JSON list:

   ```json
   [
     {
       "id": "lesson-1",
       "title": "Lesson 1",
       "videoCmid": 10,
       "ltiCmid": 11
     }
   ]
   ```

   The numbers are **course-module IDs**, as shown by the `id` parameter in each
   activity's normal `/mod/resource/view.php?id=...` or `/mod/lti/view.php?id=...`
   URL. They are not course IDs, LTI resource-link IDs or instance IDs.
6. Add a standard Moodle URL activity pointing to
   `/local/videoq/view.php?lesson=lesson-1` on your Moodle origin.

The browser selects only a configured lesson slug. It cannot supply arbitrary
video URLs, module IDs, a different VideoQ course, or an external tool URL.
Configuration editing requires the site-administrator settings permission.
Both activities must belong to the same course.

## Access and data

The companion checks Moodle login, enrolment, activity availability, hidden/deleted
activities and the existing `mod/resource:view` and `mod/lti:view` capabilities.
Moodle's File activity serves its own media through its normal redirect and
`pluginfile.php` handling, including access checks for subsequent media requests.
The companion does not read or expose another component's raw file-storage path.
The question frame launches through Moodle's normal LTI endpoint; VideoQ verifies
the platform signature and configured resource binding as usual.

This plugin stores only administrator-owned lesson associations. Moodle and
VideoQ continue to manage their own activity, session, question and quota records.
It does not implement grade return or learning completion synchronization.

## Playback and citations

When the current frame has an authenticated `videoId` grant, the companion and
question view exchange `videoq:ready`, `videoq:state` and `videoq:seek` messages.
Both sides verify the exact origin, source window, protocol version and a random
per-view channel. Video IDs and finite times are checked; seek requests outside
the actual media duration are rejected. The bridge does not send an LTI token,
VideoQ token, question, answer or user identity.

The question view automatically attaches available playback context without
extra position controls or question suggestions. The API
adds a bounded video/time hint to the latest user message only after verifying
the grant and current course membership. It resolves the SRT interval containing
the reported time and supplies that interval as the current-scene target; missing
subtitles are explicitly marked unavailable. Position is learner-controlled context,
not proof of viewing or a completion record. Model interpretation is not guaranteed.

Only sources actually cited by the answer appear in its reference list. A cited
source from the currently associated video has a seek button. Clicking it changes
the Moodle player's time and starts playback; if the browser blocks playback,
the native play controls remain available. On narrow screens, the player scrolls
into view. Seeking does not require a fresh playhead update; Moodle validates
the target against the current media duration. Sources from another
video remain text. Decimal-dot and SRT decimal-comma timestamps are supported.
Standalone LTI launches without a connected player keep the normal question UI.
No playback position is stored in Moodle by this plugin; VideoQ may retain the
position as part of the question text in its existing guest question logs.

## Reproducible verification

The repository's `lab` lab mounts this plugin, creates a 24-second
original silent video as a Moodle File activity, and associates it with the test
VideoQ resource. Its AI provider defaults to a deterministic fixture; the lab
also supports an explicitly enabled real OpenRouter mode.

```bash
node lab/verify-companion.mjs
```

That command also toggles the lab's File/LTI visibility and restores it in `finally`
blocks to verify access denial; it must run only against the isolated lab.
It checks anonymous access, an unenrolled account, both learner and teacher views,
actual video playback while submitting a question, desktop columns and mobile
stacking. Screenshots and `companion-verification.json` go to the lab directory.

The lab's `check-plugin.php` checks valid and malformed JSON associations,
duplicate IDs, wrong module-ID types, unknown keys and admin setting validation.

Playback checks (default does not call a paid model):

```bash
node lab/verify-playback.mjs
# One real AI answer at the 10-second VideoQ scene:
node lab/verify-playback.mjs --live
```

The first test checks origin/window/channel rejection, cross-video and out-of-range
seek rejection, SRT timestamp handling, citation filtering, automatic playback context
and preservation of paused playback. The live check exercises the actual model
and seeks back to its cited 8-second scene.

## Native Moodle messaging interface

The companion renders Moodle's actual
`core_message/message_drawer_view_conversation_body_message` and
`core_message/message_drawer_view_conversation_footer_content` templates through
`$OUTPUT->render_from_template`, including active-theme overrides. The standard
`message-app`, sent/received message styles, textarea, send/loading icons and
`core/auto_rows` behavior come from Moodle. Local CSS controls only the surrounding
layout and message-list scrolling. The read-only AI messages remove core's
human-message bulk-delete selection role; no unsupported delete/contact controls
are shown. Suggested questions and playback-position controls remain absent.

Moodle 5.0 removed the old `mod_chat` activity from core. Its replacement here is
the existing standard messaging UI, not an installation of the legacy activity.
The AI conversation is not a human direct-message thread: VideoQ still handles
answer generation, course-wide evidence and question records. No bot user,
Moodle message record or notification is created. Standalone LTI launches retain
the VideoQ question interface when the companion is not present. Older backends
or course-only bindings without a playback grant automatically fall back to
the visible LTI interface if the native renderer handshake is unavailable.

The companion's LTI iframe now acts as an off-screen authenticated transport.
Only the allowed Moodle parent, exact source window, protocol version, per-launch
channel and permitted video can activate the `videoq:ui` renderer protocol. Native
`videoq:question` commands have bounded text and unique request IDs. Replies carry
only display status, question text or structured answer data; session tokens,
ID tokens, browser proof and owner log capabilities never leave the API frame.
The parent applies the same source/origin/channel checks before rendering. Model
HTML remains literal text; formatting and citations use safe DOM nodes. Source
clicks use the existing restricted seek bridge and server-rendered media catalog.

Only the message list scrolls. The composer stays below it; Enter sends,
Shift+Enter adds a line and Japanese IME composition does not send. Failed
questions keep their input for retry, and connection failures show a message and
the existing separate-view link. Standard theme changes affect the chat directly,
without copying colors or loading a separate imitation of Moodle CSS.

The companion also uses the standard Moodle page header, breadcrumbs, cards and
Video.js player. Auth and File-activity access checks run before page context is
changed to the course for rendering. The demo can be localized with
`lab/localize.php` after installing the official Japanese pack.

```bash
node lab/verify-design.mjs
node lab/verify-course.mjs
```

These checks intercept answer responses and make no paid AI calls. They verify
standard message/composer markup, theme inheritance, core textarea resizing,
scrolling, rich-text safety, IME handling, retry/connection errors, playback,
conversation preservation and cross-video citations at desktop/mobile widths.

## Course-wide questions and switching videos

Install both plugins: `local/videoq` into `local/videoq`, and
`plugins/ltisource/videoq` into `mod/lti/source/videoq`. Run Moodle's normal plugin
upgrade. The source plugin adds an expiring, Moodle-signed custom access proof to
LTI launches; the companion initializes its private signing key during install or
upgrade. Do not copy that key between Moodle sites.

Automatic synchronization groups videos by **installation + Moodle course ID**.
Enabling another video adds it to that VideoQ course; replacement, hiding,
disabling or deleting one video leaves its siblings intact. The course title
comes from Moodle. Run VideoQ database migrations 0048 and 0049 when upgrading.
0049 consolidates existing LMS resource bindings and memberships; it preserves
original courses and their records rather than deleting them.

Database-backed LTI registrations require live Moodle permission checks. For
administrator-managed static bindings, set `courseAccess: "moodle"` on the
registration and map all related resources to the same `courseId`, with an
individual `videoId` per resource. A manually configured registration without
this option retains its administrator-defined course scope; do not put
learner-restricted videos into such a shared scope.

At launch and before each question, VideoQ calls the trusted Moodle issuer's
`/local/videoq/access.php`. This cookie-free endpoint accepts only a signed,
one-hour proof bound to the learner, course and launch activity. It rechecks
account/enrolment state, course and activity visibility, `uservisible` (including
availability conditions), deletion and view capabilities for each video and LTI
activity. Failure to reach Moodle denies the question. VideoQ intersects this
list with its current enabled, processed resource bindings and course membership
before retrieval, including transcript/scene tools. Changed permissions also
remove prior answer context from the model input for that session.

The video selector and citations use the same permitted catalog. A citation in
another lecture changes the Moodle player source, seeks and starts playback,
without replacing the question iframe or clearing its conversation. Player URLs
come only from Moodle's server-rendered catalog; received messages cannot supply
an arbitrary media URL. Position hints follow the selected video. The regular
File activity continues to enforce access when serving the media.

The source plugin also supports launching a course conversation in a separate
window through the same tool type. Without the companion player, citations are
text references. The companion's selector contains processed, accessible videos
only; it does not add timestamp controls or suggested questions. Reload the
companion after adding a lesson to refresh its server-rendered media catalog.

The player uses the configured Moodle Video.js CSS class and standard controls,
play button, colors and proportions. Companion CSS controls the surrounding
layout only; it does not override Video.js skin selectors.
