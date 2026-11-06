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
JavaScript package manager or JavaScript compilation step is needed for these
plugins, their package build, this repository's lab or CI. The development-only
Moodle coding check installs locked PHP tools in an isolated directory; those
tools are not shipped in the plugin ZIPs and are not needed on a Moodle server.

## Course tags and filtering (unreleased 0.16.0)

The course assistant offers **Course tags and materials** using the existing
private tool frame. Learners can search permitted material titles, filter by tag
or untagged materials, open a ready source and read its transcript when separately
allowed. Names, counts and IDs from hidden materials are not returned.

Course content editors can create course-specific vocabulary and change tags on
sources they currently manage. Renaming or deleting a tag requires management
rights over every source using it. These tags are separate from the connected
account's global VideoQ tags. Moodle capability/module access is rechecked on each
request; LTI launch-role names alone grant no tag editing rights.

A failed or conflicted save keeps the draft. A revoked draft is read only and can
still be selected/copied. Saving again after an uncertain response uses the same
operation ID; refreshing or switching the editor asks before discarding changes.
The native bridge transfers availability/draft flags, without tag text or account
credentials. Older backends keep this control hidden; older companions can use
the embedded interface. Deploy API migration 0056 and its restricted-role grants
before enabling this companion. This increment does not complete imports,
course-order management or external privacy export/deletion and retention.

## Connected account management (unreleased 0.15.1)

After connecting, site administrators can open **Manage the connected VideoQ
account** on the connection page. Course content editors can open the same link
from their authorized source controls. VideoQ asks for its own login and matches
the exact Moodle registration to the signed-in account. Moodle roles alone do not
grant account ownership, billing access or VideoQ system-administrator authority.

The separate page links to the existing global video/tag library, independent
VideoQ courses, profile, connected apps and API-key settings. Cloud account owners
also get billing navigation; on-premises deployments keep cloud billing disabled.
VideoQ system administration requires the current VideoQ administrator role.
Opening these links does not reconnect a disabled Moodle connection or change a
subscription. No credential is transferred through the URL or private iframe.

This requires matching API and web deployments advertising the management page.
An older backend gives a local unavailable message instead of guessing a web URL.
Course-scoped tag editing, canonical Moodle imports and the remaining external
privacy workflows are separate work; this page provides independent account
operations rather than granting course users those privileges.

## Install and connect

### Course Q&A (unreleased 0.13.0)

With a compatible backend, **Course Q&A** opens the shared forum inside Moodle's
existing LTI connection. Learners use their Moodle login and a course-specific
pseudonym; no VideoQ account is created. The interface identifies questions and
replies as shared with the course. It supports search, lesson/unanswered filters,
paging, questions/replies, own text edits/deletion, voting and teacher featuring
or deletion. It uses the same authenticated forum interface as the tool view,
following Moodle's supplied font/colors. Closing it returns to the native Moodle
assistant without reloading the tool session.

The course capabilities `local/videoq:viewqa`, `postqa`, `voteqa` and `deleteownqa`
default to allow for student/teacher/editingteacher/manager archetypes.
`moderateqa` and `instructorqa` default to teacher/editingteacher/manager. The
instructor badge records the permission at the latest post/edit; it does not
grant moderation power. Override these capabilities independently for custom
roles. Each request rechecks current access; a cached Instructor/Administrator
launch claim cannot grant an operation. A moderator can delete another person's
post and feature a question; only the author edits their text.

Deploy the reviewed backend including its Q&A migrations, and run Moodle's
normal upgrade for 0.13.0. Older backends do not announce the panel; older
companions supply no Q&A permissions, so a new backend denies those operations.
Published forum data and moderation records are stored in VideoQ. The separate
external privacy/retention workflow remains required.

### Transcript reader (unreleased 0.12.0)

This branch adds **Lesson transcripts** above the native course chat. It requires
a VideoQ backend with `/api/lti/materials`, `/api/lti/transcript` and the
`features.transcripts` bridge announcement. Installing the companion alone does
not add those backend endpoints. Deploy the reviewed backend change and upgrade
the companion through Moodle's normal database-upgrade screen.

The reader lists currently permitted lessons, pages through their transcript
cues and lets learners jump to a cue's playback position. The course capability
`local/videoq:viewtranscript` defaults to allow for student, teacher,
editingteacher and manager roles. Course role overrides can prevent transcript
viewing while retaining the existing assistant. File and LTI activity visibility,
availability conditions, user suspension and enrolment checks still apply to
each request. Moodle administration privileges do not grant access to the
connected VideoQ account's billing, keys or global library.

Older backends do not announce the reader, so the new companion keeps it hidden.
Permission fields are returned only when a backend requests
`includePermissions: true`, preserving the previous strict access-response
format for older tools. A new backend with an older companion receives no
positive transcript permission and denies transcript reads; existing chat can
continue. No source video or transcript is changed by the reader.

### Install through Moodle administration

The official Marketplace listing is still being prepared and cannot be installed
from the directory yet. After approval and publication, use Moodle's **Site
administration → Plugins → Install plugins → Find plugins in Moodle Marketplace** and install both VideoQ components, including the declared
companion dependency. No Git or server-shell operation is needed for this path.

For a review/evaluation build, a developer can provide the two component ZIPs
built with the [submission profile](translations.md). Upload `local_videoq.zip`
first and then `ltisource_videoq.zip` through Moodle's standard ZIP installer and
complete the database upgrade. The optional manual connection settings are not
required during installation. Then open **Connect to VideoQ** as described below. Do not upload the repository source ZIP or the
AMOS translation ZIP. Web installation must be enabled by the Moodle host.
Historical prereleases are not automatically the current submission candidate.

### Optional Git installation for server operators

Clone the repository outside Moodle's public directory. For Moodle 5.1, place
both plugin directories under its `public/` directory. Replace the example paths
below with your actual installation paths:

```sh
git clone https://github.com/videoq-jp/videoq-moodle.git /opt/videoq-moodle
cp -R /opt/videoq-moodle/plugins/local/videoq /var/www/moodle/public/local/videoq
cp -R /opt/videoq-moodle/plugins/ltisource/videoq /var/www/moodle/public/mod/lti/source/videoq
sudo -u www-data php /var/www/moodle/admin/cli/upgrade.php --non-interactive
```

Run the upgrade as your Moodle web-server user. The destination plugin directories
must not already exist for this initial-install example. For updates, back up the
site, enable maintenance mode, fetch the reviewed release, replace both plugin
directories and run the upgrade again. Do not copy over existing directories and
leave removed plugin files behind. ZIP installation through Moodle's plugin
installer is an alternative when release archives are available; it is not required.

### Connect from Moodle

1. As Moodle administrator, open **Site administration → Plugins → Local plugins
   → Connect to VideoQ** (or `/local/videoq/connect.php`). Enter your **VideoQ
   HTTPS URL**: use `https://videoq.jp` for the compatible cloud release or your
   own on-premises URL, including its custom HTTPS port if needed.
2. Choose **Connect to VideoQ**. Sign in to the owning VideoQ account, confirm
   the displayed Moodle URL and account quota permissions, then allow the
   connection. You return to Moodle automatically. Complete the flow within ten
   minutes; no code copying or manual LTI/key registration is needed. The current cloud service at `https://videoq.jp` supports
   this flow. For an on-premises deployment, use a release containing
   [VideoQ PR #1319](https://github.com/videoq-jp/videoq/pull/1319).
   No setup form or connection-code generation in VideoQ settings is needed.
3. Add a video through Moodle's ordinary **File** activity. Enable **VideoQ
   questions** in its settings. Wait for background processing to finish.
4. Open the course-wide VideoQ assistant. Moodle plays the selected video and
   renders the chat using its native messaging templates. VideoQ generates
   streamed answers and supplies citations. Clicking a citation selects its
   permitted video and seeks to the cited time.

Do not paste AI-provider keys, private signing keys or server configuration into
the connection form. The [materials-service guide](materials-service.md)
describes operator configuration and migration from legacy `VIDEOQ1.` connections.
Normal setup requires no separate synchronization credential or connector server.

## Data requests and retention

Review [data handling](privacy.md) before enabling the service. The unreleased
0.17.0 provider can process external requests when the paired service is enabled.
Legacy data, shared content, backups and provider records still need an operator process.

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
Repeat **Connect to VideoQ** to reconnect through the supported workflow.
For platform-side trust revocation as well, disable/remove the registered VideoQ
external tool in Moodle through its standard tool administration. The remote
VideoQ disconnect does not delete Moodle's tool registration.

## Uninstall

Disconnect and revoke the tool's trust as described above before removing plugins.
Use Moodle's plugin overview to uninstall `ltisource_videoq` first, then remove
its `public/mod/lti/source/videoq` code directory. Only then uninstall
`local_videoq` and remove `public/local/videoq`. Moodle can still block removal
of the local plugin while dependent companion code remains on disk, even after
its database uninstall succeeds.

Uninstalling removes plugin configuration and synchronization tables. Source
File activities and course videos remain in Moodle. It does not erase VideoQ's
processed copies or question history; manage those separately in the owning
VideoQ account. Reinstallation requires connecting and enabling questions again.

## Operations and troubleshooting

The unreleased local_videoq 0.14.0 adds **My AI conversations** to the existing
assistant workspace when the backend supports saved conversations. Upgrade the
backend and run its migrations before relying on this feature. It provides
list/read/resume, new conversation, per-conversation NDJSON download and deletion.
The independent course capability `local/videoq:manageownhistory` defaults to
students, teachers, editing teachers and managers; course overrides can remove
it without disabling ordinary AI chat. Missing capability support denies saved
history. A teacher can manage only their own conversations through this panel.
See [data handling](privacy.md) for ownership, retention and external privacy
request limits. Opening history or course Q&A reuses the current tool session.

Keep Moodle cron/ad-hoc tasks, the VideoQ processing worker and materials scheduler
running. Configure PHP/web upload and request limits, storage capacity and Moodle
File limits for your expected video sizes. Use publicly trusted HTTPS or install
your organization's CA through Moodle's standard trust configuration. Keep peer
and hostname verification enabled. Do not expose signed upload URLs in logs.
Moodle must reach the selected VideoQ server and VideoQ must reach Moodle. For
deployments with a separate API hostname, enter the public origin serving
VideoQ's `/api` routes; discovery opens its configured web UI automatically.
For
private on-premises endpoints, configure Moodle's normal outbound-network and CA
trust policies. No URL is hardcoded to the cloud service; redirects are disabled
on discovery and exchange requests. The administrator's Moodle session and an
S256 verifier protect the temporary callback code, including response recovery.
If the exchange is interrupted, use **Retry connection** on the Moodle page
within the original ten-minute session. After expiry, start the connection again.

If processing stalls, inspect Moodle's VideoQ status, scoped task logs and the
external VideoQ worker/queue health. Check AI credentials on the VideoQ service;
they do not belong in Moodle. For a failed LTI login, check public HTTPS URLs,
client/deployment matching, clock synchronization and key discovery. Clear
Moodle caches after changing tool/plugin configuration through supported APIs.

The [lab](../lab/README.md) provides reproducible PHP/native-browser checks.
[Coverage notes](verification.md) distinguish current checks from historical
acceptance evidence. A local passing run does not certify LTI interoperability or
prove capacity for 100 concurrent learners; test the actual target deployment.

## Course layout and lifecycle limits

For one visible assistant entry per course, enable **Site administration →
Advanced features → Allow stealth activities** before enabling VideoQ questions,
and use a course format that supports activities available but not shown on the
course page. Otherwise Moodle also displays the generated per-video transport
activities and shortcuts. The plugin does not change this site-wide setting.

Manual lesson associations are a legacy mode. Materials-service connections use
only File activities explicitly enabled for synchronization; legacy JSON
associations cannot extend their permitted material list. A legacy `VIDEOQ1.` code
cannot replace an active materials-service connection.

A failed **new, unused** materials setup can be cancelled from the connection
page. Configured connections and tool registrations already used by activities
are protected. If a lost exchange response created a connection on VideoQ, remove
that unused remote connection in VideoQ as well; cancelling local setup does not
claim to revoke the remote registration.

For materials-service connections, local_videoq 0.10.0 course backups retain each File's VideoQ opt-in and
rebuild managed activities and links using the destination course and site's
connection. Upgrade the destination local plugin to 0.10.1 or later, install the
launch companion, and configure the destination connection
before restoring; normal Moodle cron initializes and synchronizes restored
materials asynchronously. A restored course has its own VideoQ course/videos;
private questions, answers and connection credentials are not copied by this
plugin. The restoring user must be allowed to manage the source activities.
Version 0.10.1 also preserves the existing assistant entry when importing into an
existing course. Same-site full and partial restoration are verified; cross-site
native chat still has an unresolved startup timeout in the trial.

Backups made before 0.10.0 have no portable opt-in markers: remove their obsolete
generated VideoQ activities/links, then enable **VideoQ questions** on the restored
File activities manually. Whole-database/object-store
recovery and individual `.mbz` restore are separate capabilities. See the
[verification scope](verification.md) before relying on a particular workflow.

## Teacher material editing (unreleased 0.15.0)

The course assistant's **Manage course materials** panel edits the AI description
and SRT transcript, rebuilds the search index and retries eligible processing
failures. It requires both `local/videoq:managecontent` in the course and
`moodle/course:manageactivities` on the source File activity. The new capability
defaults to editing teachers and managers; students and non-editing teachers do
not receive it. Current permissions and source visibility are rechecked for every
operation. A launch's Instructor/Administrator claim grants no editing authority.

**Manage Moodle source activities** links authorized sources to Moodle's title,
file and visibility editor, the existing synchronization page, and core course
activity controls (including deletion). Use these flows for source changes.
The derived editor does not replace the Moodle source file or change enrolments.

Unfinished materials remain available to authorized teachers for management;
learners and AI search receive only ready materials. SRT changes temporarily take
the material out of AI search until its index finishes. Empty SRT requires an
explicit clear confirmation. Conflicting edits and lost responses retain the
draft; copy a revoked draft from the read-only editor before leaving the page.
Processing is polled at most 60 times at five-second intervals, then needs manual
refresh. For an old/unsupported or already-completed processing receipt, use
**Manage VideoQ synchronization** to retry. Processing limits still apply.

Upgrade the compatible VideoQ worker before enabling the new API's explicit-clear
jobs, then apply the API's migrations/grants and deploy the API before upgrading
the companion. Older peers retain learning but cannot infer the new write grant.
This version remains unreleased pending the verification gates; no Marketplace
archive or public source branch is updated by this feature work.

## External privacy service (unreleased 0.17.0)

Deploy the paired VideoQ privacy branch and this plugin together. In the existing
VideoQ `LTI_CONFIG` JSON, enable `privacyEnabled: true` only after all API writer
processes are upgraded and drained. Keep the existing canonical tool origin,
registrations and database-registration settings. The service defaults to off.
Apply the complete existing migrations, official conversation checkpoint setup
and API security profile. No new secret or Moodle schema migration is required.

Old recent-history caches without subject attribution must expire (24-hour TTL)
and the normal expired-credential cleanup must run. Until then the service
rejects privacy operations rather than guessing whose data a cache contains.
Do not enable the service during a mixed-version rollout. Old/unavailable
backends fail requests; coordinate the rollout before accepting real requests.

Use Moodle's normal privacy requests and approval workflow. No additional web
endpoint lets a learner select another person's ID or sign assertions. Requests
remain possible for inactive/over-quota connections while the registered
platform identity and key remain. Keep these until outstanding requests are
finished, and test export/delete with synthetic records before learner use.

## Optional audit retention

The paired audit-retention backend adds `auditRetentionDays` (integer 1–3650) to
VideoQ's existing `LTI_CONFIG` JSON. Omit it to disable automatic cleanup; no
implicit retention period is chosen. Apply the complete schema/data migration
chain (0057/0058) and security profile, then drain/upgrade every API writer before
enabling this setting. Older APIs reject the new setting. This metadata-only
plugin update is 0.17.1 / 2026110700; it needs no new Moodle schema or secret.

Cleanup removes expired material/tag operation receipts and clears expired LTI
moderation actor keys. It retains minimal operation-ID hashes so retrying an old
request cannot repeat a write. Normal core-approved personal data requests do
not depend on this optional cleanup setting. See [data handling](privacy.md).
