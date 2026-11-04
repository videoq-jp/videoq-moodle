# Disposable LTI materials transport spike

This is development-only code for the existing local Docker lab. It is not a
Moodle plugin release or a production endpoint. See the
[architecture decision](../../../docs/design/decision-lti-materials-handoff.md).

Run from the repository root with Node 22+, local Docker sudo access, ffmpeg,
the compatible VideoQ checkout and the lab's spoken lesson fixture available:

```sh
VIDEOQ_ROOT=/path/to/videoq SPIKE_TRANSPORT=handoff node lab/spikes/lti-pull/run.mjs
```

`SPIKE_TRANSPORT=pull` exercises the earlier API-mediated media download for
comparison. Do not run two instances together. The script guards the exact lab
origins, creates an ephemeral course/tool/key/queue, compiles a standalone adapter
against the compatible core checkout and removes its fixtures in `finally`.
Original video identities and connection activation states are checked.

Handoff sends a signed storage URL to Moodle, which uploads the real File directly
to storage. The experiment is synchronous and uses a small video. Production
requires Moodle ad-hoc tasks, job polling, retries and destination restrictions.

Inference is a deterministic local HTTP fixture in an isolated processing queue.
Storage, quota, worker processing, indexing and source reconciliation use existing
core functions. This does not assess AI quality or large-file behavior. Existing
worker/provider settings are not changed.

Reports are written under `/tmp/videoq-lti-lab/acceptance/` only after successful
checks and cleanup. No production API routes, scheduler or launch handler are
installed. Temporary endpoint code must never be copied to a production web root.
