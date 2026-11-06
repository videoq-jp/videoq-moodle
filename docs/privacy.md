# Data handling for Moodle administrators

VideoQ is an external service chosen by the Moodle administrator. The plugins
also retrieve transcript text and timestamps for permitted lessons when the
unreleased transcript reader is enabled. Each list/page request rechecks the
current user's permitted activities and transcript capability. The bearer
credential remains inside the registered VideoQ iframe; Moodle receives the
authorized text through an origin, source and channel checked bridge.

The plugins implement Moodle's metadata and request providers. This describes the current
implementation, not a legal compliance certification.

The unreleased course Q&A stores published questions/replies, votes and
moderation identifiers in VideoQ. Its author pseudonyms differ by course and do
not create VideoQ accounts. The posting interface explains course sharing;
private AI conversations are not automatically published to this forum.
Moodle's panel opens the authenticated tool frame; forum bodies and credentials
remain in that frame. Only feature availability and display commands cross the
parent bridge. External data requests must include this forum data as well as AI
history; the plugin request callbacks do not yet perform that external work.

The unreleased personal-conversation panel lets a learner list, resume, start,
download or delete their own saved AI conversations when the current course
grants `local/videoq:manageownhistory`. Each operation, including pagination,
rechecks current enrolment, activity visibility and capability. Teaching roles
do not grant access to another learner's conversations. The connected VideoQ
account owner remains able to manage stored answer logs. Conversation contents
are displayed by the existing authenticated tool iframe; credentials stay there.

The corresponding VideoQ backend atomically attributes new LTI logs to an opaque
verified subject key without creating VideoQ learner accounts. It hides a saved
conversation if any lesson in its recorded resource scope is no longer allowed.
The downloadable NDJSON contains that conversation's committed transcript,
sources and feedback metadata. It is not an export of all external personal
data. Deleting a conversation removes its linked logs, turns, attribution and
model memory from the live database. There is no delete-all operation yet.

With the coordinated backend and its existing pruning scheduler, saved
conversations expire after thirty days of inactivity; new successful answers
extend that period. Attributed logs without an active saved conversation expire
after thirty days from creation. Older turns in an active conversation remain
until it expires or is deleted. Earlier logs without durable attribution,
backups, operational logs, AI-provider records and course Q&A require separate
operator handling. A short-lived launch/history credential expiring does not
erase those records. Moodle's Privacy API callbacks still do not call an
external per-user export/deletion endpoint.

## Where data is held

| Location | Data and responsibility |
| --- | --- |
| Moodle File activity | Original video, activity title, enrollment and access rules. Moodle's activity and file providers handle their personal data. |
| Plugin synchronization tables | Course/activity bindings, generation/fingerprint, transfer status, replay hashes and temporary signed upload destinations. These tables contain no learner conversation or per-learner records. |
| Moodle core | Administrator connection session, configuration audit records, and pending restore tasks associated with the initiating user. Moodle core's Privacy API handles its records. |
| Configured VideoQ server | Transferred video/title, course and activity identifiers, launch/access proof identifiers, questions, answers, feedback and available playback position. Hosting, AI processing, operational logs and retention depend on that deployment. |

The learner bridge does not create a Moodle bot account or human-message
conversation. Using Moodle messaging templates does not put the AI conversation
in Moodle's messaging database. The companion LTI source declares user, course
and resource identifiers; generated LTI activities disable name/email sharing.
An administrator who changes tool privacy settings can change core LTI disclosures.

## Export and deletion requests

Moodle's Privacy API sees no personal contexts/users in these plugins' own tables.
Their request methods return no local personal records and leave shared course
materials, connection configuration and synchronization bindings intact. Core
providers handle Moodle-owned activity, file, session/task and audit records.
This follows the [official external-only provider guidance](https://moodledev.io/docs/5.1/apis/subsystems/privacy/faq).

**A Moodle data request does not automatically export or delete VideoQ data.**
Administrators must arrange external requests with the owner/operator of the
configured VideoQ deployment. Record that process, contact and actual retention
policy before enabling the service for learners. No per-learner external
export/deletion endpoint is called by these providers. Do not treat an empty
Moodle plugin export as evidence that the external service holds no data.

Deleting a source activity or disabling VideoQ questions follows material
synchronization/tombstones. This is distinct from a learner data request.
Disconnecting, uninstalling, or deleting a Moodle account does not itself certify
external deletion. Uninstalling removes plugin tables/settings while preserving
source Moodle materials; revoke the remote connection separately.

## Temporary integration state

- Browser connection approval is valid for ten minutes. Pending state belongs to
  the administrator's Moodle session; expiry rejects authorization, and session
  lifecycle controls eventual removal after an interrupted flow.
- Replay assertions are removed after expiry plus a two-minute grace period.
- The LTI-mode scheduler marks queued/uploading exports older than an hour failed
  and clears their signed destinations. Completed, failed or stale export records
  older than a day by modification time are deleted. Normal Moodle cron is required.
- Synchronization bindings persist for material lifecycle/reconciliation; they are
  not learner profiles. Do not delete shared bindings to service one learner's request.

VideoQ's short-lived launch/history credentials are distinct from durable chat
logs, source storage and backups. A credential expiring does not establish that
all related external data was deleted. Deployment operators must document those
retention and cleanup policies separately.

## Verification

`tests/privacy.php <Moodle-source>` uses real pinned Moodle interfaces and metadata
classes without a DB; normal CI runs it with networking disabled. The dedicated
`lab/check-marketplace-privacy.php /site` fixture also executes all six request
methods for both components with system/course/module contexts and nonempty
approved requests. It compares shared course, file, synchronization and settings
records before and after. This does not test external VideoQ data requests.
