# VideoQ companion for Moodle

## Automatic video synchronization

Use a single existing school-owned VideoQ account for its billing and allowances.
Teachers and learners use Moodle without personal VideoQ accounts. There is no
separate organization model.

For a new connection:

1. Install `local_videoq` and `ltisource_videoq` and finish Moodle's plugin upgrade.
2. In Moodle **Site administration → Plugins → Local plugins → Connect to VideoQ**,
   enter the cloud or on-premises VideoQ HTTPS URL.
3. Sign in to the owning VideoQ account, approve the connection and return to Moodle.
   No connection-code copying, manual LTI registration or separate API key is needed.

The cloud service at https://videoq.jp supports this flow. An on-premises deployment
must include the compatible LTI materials backend, processing worker, storage and
scheduler. A VideoQ account and processing allowance are required; the Moodle
plugins are GPL software and do not include hosting or paid AI service usage.
Run Moodle cron every minute. See the
[setup guide](https://github.com/videoq-jp/videoq-moodle/blob/main/docs/setup.md).

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

The [synchronization lab](https://github.com/videoq-jp/videoq-moodle/blob/main/lab/README.md#automatic-synchronization-acceptance-test)
verifies the full lifecycle with real storage/queue/worker processing and
deterministic inference providers on Moodle 5.1.8+.

## Manual companion mode (legacy connections only)

Materials-service connections use synchronized File activities instead of manual
JSON associations. See the setup guide for supported course layout and lifecycle
limits.


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

See the [Moodle lab](https://github.com/videoq-jp/videoq-moodle/blob/main/lab/README.md) and
[verification coverage](../../../docs/verification.md). PHP validates the plugin and packages, and drives native Chrome to test
streaming, feedback and seeking.
The default chat test uses a browser-only held response and no paid provider.
The `--live` test makes one actual model request.

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

Current commands and coverage are documented in the lab guide. Earlier browser
acceptance evidence is retained in `docs/evidence/` and Git history.
