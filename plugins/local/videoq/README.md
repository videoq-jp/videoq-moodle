# VideoQ companion for Moodle

## Moodle interface and shared VideoQ services

The companion owns the learning and teacher interface, using Moodle templates,
theme styles, login, enrolment and capabilities. The browser calls Moodle's
`core/ajax` function or the same-origin answer stream. Moodle PHP signs private
requests to the paired VideoQ native learning API over verified HTTPS. VideoQ
provides shared AI, search, processing, persistence and erasure services.

Neither a VideoQ presentation iframe nor a hidden authentication iframe is used,
including in courses without videos. VideoQ assertions, access proofs and private
server-session identifiers stay in PHP. The optional YouTube content player uses
the provider's iframe; it is separate from learning authentication and screens.
Deploy the compatible backend with native learning protocol 1 before this
0.22.0 companion. Standalone browser-LTI remains a separate integration.

The companion includes course learning, Q&A, personal conversations, transcripts,
material management, tags and reports according to current permissions. Course
editing and enrolment remain Moodle workflows. Billing, public sharing and global
VideoQ administration are not companion screens.

## Course material management

Course content managers with activity-management rights can open **Manage VideoQ
course materials** from the course navigation or assistant. The read-only catalog
lists manageable video File activities in current Moodle section/activity order,
including videos not yet opted into VideoQ. Add a video using the core File form,
save it, then enable its VideoQ integration. The catalog links to the permitted
core course settings, organization, participant and enrolment workflows; each
destination enforces its own authority and confirmation. Category course creation
or course deletion is offered only when Moodle permits it.

Use Moodle's activity editing controls to move sources or change sections.
VideoQ selectors, transcripts, Q&A filters and material tags follow the current
permitted source sequence. This does not change independent VideoQ library or
course order. YouTube/direct-to-VideoQ imports and aggregate reporting are not
implemented by this catalog.

With a capable backend, hidden courses/materials stay integrated but every access
request checks the current user's Moodle hidden-view and activity rights. Hiding
is separate from explicit disable/deletion. New service callers negotiate
`includeUserVisibility:true`; legacy push checks the authenticated connection's
`userVisibility` flag. Older callers retain global visibility suppression. Source
visibility changes still advance generation and cancel stale queued transfers.
The asynchronous export preserves its negotiated mode without storing learner
identity. Deploy the backend course-authority/entry stack before this companion;
do not coexist with older unprotected native readers.

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


A manual association connects a Moodle video File activity to an existing
registered VideoQ LTI resource. The resource supplies the server-side identity
and binding; the companion renders the questions on the Moodle origin through
the native learning API. Desktop uses two columns; screens up to 900 px stack
video above questions. Playback context and citation seeking use local events.

This initial plugin has been tested with Moodle 5.1.8+ and the Boost theme.
It does not modify Moodle core. It is licensed GPL-3.0-or-later, as indicated in
the PHP source headers, independently of the VideoQ application license.

## Install and configure manual associations

1. Copy this `videoq` directory to `public/local/videoq` in Moodle 5.1, and
   `plugins/ltisource/videoq` to `public/mod/lti/source/videoq`.
2. Run `php admin/cli/upgrade.php --non-interactive` from the Moodle root.
3. In the same course, create a standard **File** activity containing an MP4 and
   an **External tool** activity configured for VideoQ LTI 1.3. The companion uses
   its registered identity and current Moodle availability; it does not embed or
   launch that activity to display the assistant.
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
Moodle PHP derives the current actor and resource context and signs the exact
operation and request body. VideoQ verifies the registered platform signature,
binding, current Moodle authority and connected VideoQ course membership.

This plugin stores only administrator-owned lesson associations. Moodle and
VideoQ continue to manage their own activity, session, question and quota records.
It does not implement grade return or learning completion synchronization.

## Playback and citations

The native learning response supplies the currently authorized materials. The
Moodle player and learning interface exchange local DOM events for selection,
playback context and citation seeking. Video IDs and finite times are checked;
seek requests outside the actual media duration are rejected. No cross-window
message or browser-side VideoQ credential is required.

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
[verification coverage](../../../docs/verification.md). PHP validates the plugin
and packages, and drives native Chrome through the Moodle-rendered learning
panels and player contracts. These browser contracts use controlled API/model
substitutes. Actual Moodle-to-VideoQ authentication, File/local-CPU inference and
approved privacy requests are checked separately by the paired backend's Moodle
installed acceptance workflow. Historical frame trials do not establish
acceptance of this native transport.

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
the VideoQ question interface. The companion's learning interface requires the
native learning API protocol. It
uses Moodle templates, JavaScript and current Moodle capabilities for every
operation, including empty-course tools. It does not launch a VideoQ presentation
iframe or an authentication/transport iframe. The existing standalone LTI
interface remains available as a separate integration.

Normal requests use Moodle's session-authenticated `core/ajax` external function.
Moodle PHP resolves the actor, course, current source visibility and operation
capabilities, then sends a short-lived, operation/body-bound signed assertion to
VideoQ over verified HTTPS. Assertions, access proofs and native server-session
identifiers remain in PHP. An unexpired refused session is never silently
replaced. Answers use a same-origin, sesskey-checked Moodle stream endpoint that
releases the Moodle session lock before relaying events incrementally.

Model HTML remains literal text; formatting and citations use safe DOM nodes.
Source clicks operate the local Moodle player directly, using the current
server-authorized material list and the Moodle-rendered media catalog.

Only the message list scrolls. The composer stays below it; Enter sends,
Shift+Enter adds a line and Japanese IME composition does not send. Failed
questions keep their input for retry, and connection failures show a message and
the current Moodle status. Standard theme changes affect the chat directly,
without copying colors or loading a separate imitation of Moodle CSS.

The companion also uses the standard Moodle page header, breadcrumbs, cards and
Video.js player. Auth and File-activity access checks run before page context is
changed to the course for rendering. The demo can be localized with
`lab/localize.php` after installing the official Japanese pack.

Current commands and coverage are documented in the lab guide. Earlier browser
acceptance evidence is retained in `docs/evidence/` and Git history.
