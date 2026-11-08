# Data handling for Moodle administrators

VideoQ is an external service chosen by the Moodle administrator. The companion
renders the learning interface on Moodle and retrieves permitted transcripts,
answers and course data through Moodle's session-authenticated endpoints. PHP
signs private operation/body-bound requests to VideoQ over verified HTTPS. Each
request rechecks current Moodle activity access and the relevant capability.
Assertions, access proofs and private server-session identifiers remain in PHP;
there is no VideoQ presentation or authentication iframe.

The plugins implement Moodle's metadata and request providers. This describes the current
implementation, not a legal compliance certification.

The unreleased course Q&A stores published questions/replies, votes and
moderation identifiers in VideoQ. Its author pseudonyms differ by course and do
not create VideoQ accounts. The posting interface explains course sharing;
private AI conversations are not automatically published to this forum.
Moodle's panel displays authorized forum data returned through the PHP gateway;
the browser receives no VideoQ credential. The approved external-data request workflow described below
includes this forum data and attributed AI history when the paired privacy
service is enabled.

The unreleased personal-conversation panel lets a learner list, resume, start,
download or delete their own saved AI conversations when the current course
grants `local/videoq:manageownhistory`. Each operation, including pagination,
rechecks current enrolment, activity visibility and capability. Teaching roles
do not grant access to another learner's conversations. The connected VideoQ
account owner remains able to manage stored answer logs. Conversation contents
are displayed by the Moodle panel through the authenticated PHP gateway.

The corresponding VideoQ backend atomically attributes new LTI logs to an opaque
verified subject key without creating VideoQ learner accounts. It hides a saved
conversation if any lesson in its recorded resource scope is no longer allowed.
The downloadable NDJSON contains that conversation's committed transcript,
sources and feedback metadata. It is not an export of all external personal
data. Deleting a conversation removes its linked logs, turns, attribution and
model memory from the live database. This panel handles one conversation at a
time; subject-wide export and erasure use Moodle's approved data requests below.

With the coordinated backend and its existing pruning scheduler, saved
conversations expire after thirty days of inactivity; new successful answers
extend that period. Attributed logs without an active saved conversation expire
after thirty days from creation. Older turns in an active conversation remain
until it expires or is deleted. Earlier logs without durable attribution,
backups, operational logs and AI-provider records require separate operator
handling. A short-lived launch/history credential expiring does not erase those
records. With the coordinated privacy service enabled, Moodle's Privacy API
callbacks invoke the external subject export/deletion endpoint for approved
contexts; attributed course Q&A is included in that workflow.

## Where data is held

| Location | Data and responsibility |
| --- | --- |
| Moodle File activity | Original video, activity title, enrollment and access rules. Moodle's activity and file providers handle their personal data. |
| Plugin synchronization tables | Course/activity bindings, generation/fingerprint, transfer status, replay hashes and temporary signed upload destinations. These tables contain no learner conversation or per-learner records. |
| Moodle core | Administrator connection session, configuration audit records, and pending restore tasks associated with the initiating user. Moodle core's Privacy API handles its records. |
| Configured VideoQ server | Transferred video/title, course and activity identifiers, launch/access proof identifiers, questions, answers, feedback and available playback position. Hosting, AI processing, operational logs and retention depend on that deployment. |

The learning interface does not create a Moodle bot account or human-message
conversation. Using Moodle messaging templates does not put the AI conversation
in Moodle's messaging database. The companion LTI source declares user, course
and resource identifiers; generated LTI activities disable name/email sharing.
An administrator who changes tool privacy settings can change core LTI disclosures.

## Export and deletion requests

Unreleased `local_videoq` 0.17.0 adds external data requests through Moodle's
standard approved-context and approved-user workflows. The paired VideoQ
privacy service must be explicitly enabled after coordinated deployment. This
is separate from the historical Marketplace candidate, whose callbacks were
metadata-only. Core providers continue to own Moodle activity/file/session/task
and audit records.

The provider exports attributed personal conversations, question/answer logs,
retrieval evidence, evaluation scores, authored Q&A, votes, moderation and
material/tag operation receipts, recent history and model memory. Authentication
credentials and provider error traces are excluded. Ordinary records use NDJSON;
large records use numbered UTF-8 fragments and a SHA-256 concatenation manifest.
Concurrent changes can require restarting the request. No record is silently
truncated.

Only approved course contexts are processed. Missing/deleted course mappings use
the system context for residual records; system approval does not grant access
to all courses. Former/deleted users are included while their Moodle IDs remain.
Individual erasure removes attributable records and invalidates selected sessions.
Own Q&A text/identity are replaced with tombstones so peer replies survive.
Shared materials, source mappings and tag vocabulary/assignments remain.

Requests use short-lived, single-use assertions signed by Moodle's platform key,
with a distinct purpose and exact API audience. Learner roles and browser
credentials do not grant privacy authority. Public connection identities are
remembered for requests after reconnecting; no user IDs or connection secrets are
stored in this history. A changed/cloned Moodle issuer cannot use old identities.
Keep the signing key and remote registration until outstanding requests are done.
Uninstall removes local configuration, including this public connection history.

External service failures stop the request. Ask the operator to resolve the
connection/rollout issue and retry; an empty export must not be treated as proof
that an unreachable service has no data. Legacy unattributed logs, physically
removed Moodle user IDs, backups, application logs, AI-provider storage and
personal data embedded in shared materials need separate operator review.
The unreleased audit-retention backend can delete expired material/tag receipts
and clear LTI moderation actor keys when the operator explicitly configures a
retention period. A permanent, namespaced hash of each operation UUID remains
to prevent duplicate execution; it has no actor, course, action, contents or
timestamp columns. Someone holding the original UUID can recognize that marker.
This is a technical replay safeguard, not a legal anonymity guarantee. Record the operator contact and actual retention policy.

See [setup](setup.md#external-privacy-service-unreleased-0170) for deployment
requirements. Disconnecting, deleting a source activity or a Moodle account,
and uninstalling do not themselves certify external deletion.

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

Native learning sessions have an immutable window of at most one hour. PHP caches
the random session identifier and original window together. An unexpired refused
session is not silently replaced. Erasure revokes the data session while a
short-lived started marker with no subject payload prevents recreation with that
same window.
Legacy browser-LTI credentials use their separate authentication path.

VideoQ's short-lived learning credentials are distinct from durable chat
logs, source storage and backups. A credential expiring does not establish that
all related external data was deleted. Deployment operators must document those
retention and cleanup policies separately.

## Aggregate usage reporting (unreleased 0.20.0)

Course reports require the separate current `local/videoq:viewreports`
capability. Only UTC question/feedback counts for currently readable material
scopes are returned; message text and learner identities are excluded. Successful
reads create an external receipt with a pseudonymous actor key, course/context,
requested dates, resource count, scope hash and time. A receipt stores no report
or message contents. These records are included in core-approved external privacy
export/erasure and optional configured audit retention. Older servers are queried
for `report_accesses` only when they advertise that collection. The authorized
aggregates and CSV pass through Moodle PHP to the report panel; credentials stay
server-side. Legacy unattributed answers are excluded from counts.

## Verification

The unreleased 0.15.0 material editor also sends teachers' AI descriptions, SRT
edits and processing requests to VideoQ. Its metadata declaration includes the
external audit receipts: a pseudonymous actor key, source identifiers, operation,
revision hashes and time. Audit receipts contain no draft text. Unsaved drafts
remain in the Moodle page until submitted through the authenticated PHP gateway.
After a permission revocation, an existing draft can
be copied from read-only fields; no new protected data is requested for that draft.
The new external privacy callbacks include these personal receipts. Shared edited
content still requires the operator workflow above; receipt cleanup must be
explicitly configured and scheduled.

`tests/privacy.php <Moodle-source>` uses real pinned Moodle interfaces and metadata
classes without a DB; normal CI runs it with networking disabled. The dedicated
`lab/check-marketplace-privacy.php /site` fixture also executes all six request
methods for both components with system/course/module contexts and nonempty
approved requests. It compares shared course, file, synchronization and settings
records before and after. This does not test external VideoQ data requests.

`tests/privacy-requests.php` checks approved scope intersection, former users,
Unicode and large exports, integrity failures and transport limits. The historical
Marketplace matrix reports above do not establish this new external lifecycle;
use the paired privacy branch acceptance evidence for it.

## Optional automatic audit cleanup (unreleased 0.17.1 metadata)

After deploying the paired audit-retention backend and complete migrations/grants,
the operator may set `auditRetentionDays` (integer 1–3650) in the existing VideoQ
`LTI_CONFIG` JSON. Unset means disabled. This controls personal material/tag
receipts and LTI moderation actor identifiers, independently of enrolment.
Moodle/browser roles cannot change this deployment setting.

The normal retention schedule processes bounded batches. Monitor the structured
`lti_audit_retention_backlog` event; remaining volume, contention or runtime budget
can require more invocations. There is no strict wall-clock deletion SLA. In the
on-prem API runtime, `node dist/node.mjs retention` runs the retention path;
`maintenance` runs delivery recovery. Shared materials, peer posts, native
moderation actors and the minimal replay markers remain. Approved individual
privacy deletion still works without opting in to automatic cleanup.
