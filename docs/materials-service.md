# LTI materials service: setup and migration

The new integration uses one LTI registration for learner launch and platform
service access. Administrators do not configure a synchronization API key.
VideoQ discovers opted-in courses, reconciles materials and requests background
exports. Moodle sends video bytes directly to VideoQ storage. Video playback and
the native Moodle chat interface keep their existing behavior.

## Compatible server and operator setup

This requires the compatible VideoQ materials-service backend. The mainline
implementation is being reviewed in
[VideoQ PR #1319](https://github.com/videoq-jp/videoq/pull/1319); installing these
plugins alone does not add missing backend features to a deployed VideoQ release.
Apply the backend's migrations using its normal release procedure. Keep the
existing queue, processing worker and maintenance scheduler running.

The automatic setup in that PR uses VideoQ's existing canonical HTTPS
`BETTER_AUTH_URL`, `USER_SECRET_ENCRYPTION_KEY` and S3-compatible signed-upload
configuration. No LTI-specific server settings or manual signing-key registration
are needed. The first authenticated connection-code request generates a key and
saves it encrypted in the VideoQ database. Restarts and replicas reuse it; restore
database backups with the same application encryption key. An administrator can
disable this automatic setup with `LTI_CONFIG=false`.

The older experimental lab has a separate migration history through
`0051_lti_materials.sql`; do not apply mainline migrations to that lab database.
Its backend still needs explicit `LTI_CONFIG` with `databaseRegistrations: true`
and the correct `toolOrigin`, plus a manually managed signing key. For this older
backend or an advanced operator-managed override, generate the ring outside the
checkout:

```sh
cd /path/to/videoq-moodle
php scripts/generate-lti-tool-keys.php /secure/path/lti-tool-keys.json
```

For that manual path, load the JSON as the **VideoQ server secret** `LTI_TOOL_KEYS`. Do not put it
in the Moodle settings, a URL, source control, logs or a connection code. VideoQ
publishes only its public components at `/api/lti/jwks`. For rotation, replace the
current private JWK and retain its public `kty`, `n`, `e` components in `previous`
while old tool instances finish their requests. Remove retired public keys after
the deployment overlap. The key ring accepts at most three retired public keys.

## Connect or migrate

1. Install/update `local_videoq` 0.9.1 and `ltisource_videoq`. Finish Moodle's
   plugin upgrade and keep normal Moodle cron/ad-hoc task execution enabled.
2. In the owning VideoQ account, open Settings → Connect Moodle. Enter the exact
   HTTPS Moodle base URL and generate a connection code. A materials-capable
   server issues a `VIDEOQ2.` code.
3. Open Moodle's `/local/videoq/connect.php` as its administrator. Paste the code
   and connect. The plugin registers its service endpoints and configures the
   tool JWKS automatically.
4. Add ordinary video File activities and enable VideoQ questions. Processing runs
   in the background; the existing course assistant becomes available when ready.

An explicit migration reuses the configured LTI client/deployment and the same
VideoQ installation. Existing course IDs, video IDs, media and account quotas stay
attached. The old synchronization credential is removed from both sides and is
immediately rejected. A lost exchange response can be recovered by retrying the
same ten-minute code. Codes never authorize rebinding another owner's connection.

Existing `VIDEOQ1.` connections remain supported for compatibility. Updating the
plugin alone does not silently migrate or reconnect a site. An operator must
provide the compatible server before an administrator selects the new setup.

## Runtime behavior

- VideoQ polls only courses with enabled materials or unacknowledged changes.
  Explicit deletion tombstones are reconciled; an empty/failed response never
  means that unrelated videos should be deleted.
- Source revisions and export job IDs bind a transfer to one material generation.
  Repeated requests reuse a durable idempotent Moodle ad-hoc task. Failed or expired
  exports receive a fresh signed destination on a later reconciliation.
- Moodle permits only the storage origin/path prefix approved during setup,
  follows no upload redirects, verifies HTTPS peer/hostname certificates and
  clears signed URLs when a job finishes.
- Before each question, VideoQ asks Moodle for the learner's currently permitted
  materials. Hiding, availability changes and enrollment checks fail closed.
- VideoQ reports processing state back to Moodle. The lab polls about every 30
  seconds; metadata synchronization is eventually consistent, not instantaneous.
- Removing an uploading source queues immediate storage cleanup plus a delayed
  second cleanup after the upload capability/transfer window. This covers a PUT
  that finishes after deletion without refunding storage twice.
- Disconnect in the owning VideoQ account's settings. Future launches, questions
  and synchronization are denied; existing processed learning data is retained.
  Moodle's local connection configuration alone does not prove remote access is active.

Normal Moodle cron executes `local_videoq\\task\\export_material`. The lab's scoped
runner executes only VideoQ synchronization and export tasks, leaving unrelated
Moodle jobs alone. No separate connector server or federation deployment is added.

## Verification

The local trial was migrated in place and its existing video was preserved.
A second temporary File was exported by normal Moodle cron, processed by the
normal SQS worker with real inference, placed in the existing VideoQ course and
then removed by normal reconciliation and storage cleanup; see
[background acceptance](evidence/materials-background.json). This test does not
invoke worker task functions directly.
Evidence includes [production-path lifecycle checks](evidence/lti-materials-runtime.json)
with real OpenRouter transcription, embeddings and an answer, and
[browser acceptance](evidence/materials-browser.json). Native chat streaming,
Good/Bad/reset and timestamp seeking are covered by
[the real browser/provider check](evidence/materials-chat.json).

Current verification commands use PHP and native Chrome:

```sh
php lab/verify-chat.php
php lab/verify-chat.php --live
php lab/verify-background.php /private/original-spoken-lesson.mp4
```

See [coverage](verification.md) for the distinction between historical evidence
and current executable tests. Mutating acceptance requires the isolated local
lab; real inference may incur provider charges.

Large-file stress, broad LMS interoperability and production concurrency still
need deployment-specific verification. Do not treat a local acceptance run as an
LTI certification or a production capacity guarantee.


## Private certificates in the local lab

The lab uses a private Caddy CA. Its original root certificate file is readable
only by root, so pointing PHP at that volume is insufficient for web requests.
`lab/trust-lab-ca.php` combines the **public certificate only** with the system
trust bundle in Moodle data, readable by web PHP. The scoped synchronization
runner refreshes it automatically. Caddy's private key is never copied or exposed.
For an already-running lab, initialize the bundle after the proxy is ready:

```sh
sudo docker exec videoq-lti-lab-moodle-1 php /lab/trust-lab-ca.php
sudo docker exec -u www-data videoq-lti-lab-moodle-1 php /lab/check-materials-tls.php
```

The TLS check rejects the private certificate when using only public system roots,
then succeeds with the configured bundle. In production, use a valid publicly
trusted certificate or install your organization's CA through the normal Moodle
trust configuration. Keep peer verification enabled. A CLI-only key-fetch test
is insufficient: verify authentication through Moodle's web endpoint as well.
