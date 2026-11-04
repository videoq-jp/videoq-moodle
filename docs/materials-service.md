# LTI materials service: setup and migration

The new integration uses one LTI registration for learner launch and platform
service access. Administrators do not configure a synchronization API key.
VideoQ discovers opted-in courses, reconciles materials and requests background
exports. Moodle sends video bytes directly to VideoQ storage. Video playback and
the native Moodle chat interface keep their existing behavior.

## Compatible server and operator setup

This requires the VideoQ materials-service implementation and migration
`0051_lti_materials.sql`, currently in the compatible development checkout. A
build of the source base commit alone is insufficient. The initial implementation
requires configured S3-compatible signed PUT uploads; native-only bucket bindings
must first be configured for signed uploads. Do not deploy the plugin expecting
an unchanged VideoQ release to acquire missing backend features.

Apply all current VideoQ migrations using the normal release procedure. Configure
`LTI_CONFIG` with `databaseRegistrations: true` and the correct `toolOrigin`.
Keep the existing queue, processing worker and maintenance scheduler running.
Generate the tool signing key ring once, outside the checkout:

```sh
cd /path/to/videoq/apps/api
node scripts/generate-lti-tool-keys.mjs /secure/path/lti-tool-keys.json
```

Load that JSON file as the **VideoQ server secret** `LTI_TOOL_KEYS`. Do not put it
in the Moodle settings, a URL, source control, logs or a connection code. VideoQ
publishes only its public components at `/api/lti/jwks`. For rotation, replace the
current private JWK and retain its public `kty`, `n`, `e` components in `previous`
while old tool instances finish their requests. Remove retired public keys after
the deployment overlap. The key ring accepts at most three retired public keys.

## Connect or migrate

1. Install/update `local_videoq` 0.7.0 and `ltisource_videoq`. Finish Moodle's
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
  follows no upload redirects and clears signed URLs when a job finishes.
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
Evidence includes [production-path lifecycle checks](evidence/lti-materials-runtime.json)
with real OpenRouter transcription, embeddings and an answer, and
[browser acceptance](evidence/materials-browser.json). Native chat streaming,
Good/Bad/reset and timestamp seeking are covered by
[the real browser/provider check](evidence/materials-chat.json).

Development commands (the runtime fixture requires the disconnected disposable
lab, and must not run against an active connection):

```sh
node lab/check-materials-service.mjs
VIDEOQ_ROOT=/path/to/videoq MATERIALS_REAL_AI=1 node lab/check-materials-runtime.mjs
node lab/migrate-materials-browser.mjs
node lab/verify-materials-chat.mjs
```

The runtime fixture uses an isolated queue and temporary course/tool. It restores
its Moodle configuration and temporary database schema; production inference uses
the user's private provider file without printing credentials. The browser rollout
command intentionally leaves the approved existing lab connection migrated.
Large-file stress, broad LMS interoperability and production concurrency still
need deployment-specific verification. Do not treat a local acceptance run as an
LTI certification or a production capacity guarantee.
