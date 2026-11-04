# Decision: LTI service authentication with Moodle-side media export

Status: accepted architecture direction; isolated spike validated. Production
migration is not implemented. Existing connections and deployment are unchanged.

## Decision

Keep LTI 1.3 launch and service authentication in VideoQ. Put Moodle-specific
material discovery, access evaluation and media export in `videoq-moodle`.
VideoQ reads the desired material state, reserves an upload using its existing
pipeline and requests that Moodle export the file directly to the signed storage
URL. VideoQ verifies completion and starts its existing processing pipeline.

This revises the **media transport** in the earlier
[LTI materials pull proposal](lti-only-integration.md). Retain its discovery,
identity, reconciliation and access-control model. Do not make VideoQ download
large Moodle files through its API as the default transport.

The administrator configures one LTI registration. No separately managed sync API
key or standalone federation server is required. The service contract is a
custom extension, not a video synchronization feature supplied by LTI Core.
Other LMS products need an implementation of that contract.

## Why this fits the requirements

- Moodle remains the source and player of course videos.
- The plugin handles Moodle file APIs, permissions and background export tasks.
- VideoQ reuses upload reservations, quotas, object storage, transcription,
  indexing and course membership instead of adding a remote-file download worker.
- API calls carry small control messages. Media bytes go from Moodle to storage.
- Stable installation/course/material identifiers reconcile replacements and
  deletions without moving unrelated videos or creating separate chat courses.
- A signed upload URL is a short-lived internal transfer capability, not another
  administrator-managed integration credential.

The LTI launch authenticates a learner in a course context. Service access uses a
separate short-lived access token obtained with the tool's registered signing key.
A launch token must not be reused as a general-purpose server API credential.
See the [LTI Core specification](https://developers.imsglobal.org/spec/lti/v1p3)
and [security framework](https://developers.imsglobal.org/spec/security/v1p0).

## Workflow

1. Moodle launches VideoQ with the registered LTI identity and course context.
2. VideoQ obtains a scoped Moodle service token with its tool client assertion.
3. VideoQ reads the opted-in materials manifest and learner access decisions.
4. For a new version, VideoQ reserves an upload and sends its expiring storage
   destination to the Moodle export service, bound to that exact source version.
5. A Moodle ad-hoc task uploads the file. VideoQ polls job status, verifies the
   expected object and confirms the existing upload reservation.
6. Existing VideoQ workers process it. Reconciliation retires replaced or deleted
   source copies; learner retrieval uses Moodle's current permitted material IDs.

Never interpret a failed or incomplete manifest request as deletion. Revoke
retrieval when access cannot be verified; purge only after an authoritative
source deletion. Hiding a source revokes learner retrieval while retaining its
processed copy for reopening.

## Minimum production changes still required

This does require one VideoQ release. The spike's zero production-source changes
mean it ran as a standalone adapter, not that a plugin can add missing backend
features to an unchanged deployed VideoQ.

VideoQ needs signing-key/JWKS lifecycle, launch bootstrap before materials are
processed, a materials-service controller and scheduled reconciliation, export
job polling, and a connection mode that does not require the legacy sync-key
hash. Keep these at the generic LTI/materials boundary. Moodle-specific code stays
in the separate repository. Existing playback and AI processing need no redesign.

The production Moodle exporter must return 202 and a job identifier, rather than
hold an LTI launch or API request open during a large upload. Require scoped
course access, stable version and idempotency identifiers, destination origin and
object-prefix restrictions, no redirects, expiring URL renewal and retry handling.
A token for service access is not permission to export every course.

## Evidence and limits

The [disposable lab spike](../../lab/spikes/lti-pull/README.md) exercises real Moodle
Files, signed tool authentication, storage transfers and existing VideoQ upload,
quota, processing and reconciliation functions. Its handoff result is recorded in
[the evidence artifact](../evidence/lti-handoff-spike.json).

Cases cover registration, repeat synchronization, hiding/reopening, replacement in
the same course, deletion of source/video/vectors/storage, and learner retrieval
filtering. Negative authentication cases cover replay, wrong audience, ungranted
scope, stock-token-endpoint scope bypass and anonymous access. Existing videos
and connection states are compared before and after; temporary fixtures are removed.

This is a small-file synchronous export proof. Production async export, browser
launch/bootstrap, persistent scheduling, large files, multiple courses, pagination,
JWKS rotation and load behavior remain unvalidated. The adapter uses a placeholder
legacy hash solely to fit the current schema; it is not a sync credential.

The lab's external AI key had expired. Processing therefore used deterministic
local HTTP inference, with real storage and worker code. This validates pipeline
integration, not transcription accuracy or real-provider answer quality. The
existing provider configuration was not changed.

## Alternatives

Pure VideoQ media pull is technically viable and was exercised during exploration,
but requires remote download/resource handling in the core runtime or another
worker. That is a poorer fit for minimizing core changes here. A separate
federation service or manually configured sync key adds administration without
solving a requirement that the registered tool key cannot cover.

Adopt the revised architecture; do not claim the current production integration
has already migrated or that all LMS products become compatible automatically.
