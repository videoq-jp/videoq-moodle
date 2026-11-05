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

## Install and connect

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

Review [data handling](privacy.md) before enabling the service. Moodle privacy
requests do not automatically export/delete external VideoQ history; arrange the
external request and retention process with the configured deployment operator.

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
