> Historical implementation notes. For the supported single-plugin installation and teacher-owned connection flow, use [setup](setup.md). The old import/LTI-helper workflow below is retired.

# Moodle integration review — 2026-11-05

Scope: `videoq-moodle`, its HTTPS contract with the deployed VideoQ trial,
Moodle 5.1.8+ / Boost, installation, access control, synchronization, UI, privacy,
PHP-only tooling and release publication. Candidate: local_videoq 0.10.1 and
ltisource_videoq 0.4.1. README remains a short installation entry point.

## Conclusion

The division of responsibilities is appropriate for a controlled Moodle trial:
Moodle owns source activities, enrollment/availability and playback; VideoQ owns
its processed copy, retrieval and streamed answers. Learner launch uses LTI 1.3;
background discovery/export/access checks use a custom service authenticated by
the registered tool key. This is **not** standardized LTI video synchronization,
LTI certification, or compatibility with every LMS. The distinction follows
[LTI Core's message/service authentication model](https://www.imsglobal.org/spec/lti/v1p3)
and is explicit in the [architecture decision](design/decision-lti-materials-handoff.md).

## Findings corrected

| Finding | Correction and verification |
|---|---|
| A legacy code could start replacing a keyless materials connection | Reject legacy setup when materials mode is configured; verify identity/settings remain unchanged |
| New materials setup did not check whether the launch companion was installed | Reject before creating a type or contacting VideoQ |
| Failed new materials setup lacked cancellation, while cancellation needed stronger protection for configured types | Permit cancellation only for unused local types; reject configured/activity-bound types; explain that remote disconnection is separate |
| Export/acknowledgement lookup could cross another tool's material row within the same course | Scope row reads/updates and background jobs to the authenticated tool type; verify foreign rows remain unchanged |
| Legacy manual associations could interfere with a migrated service's access manifest | Materials mode reads synchronized File activities only; hide legacy mapping settings in that mode |
| Launch companion privacy metadata omitted the user/course/activity identifiers it transmits | Declare the external location; update automatic playback-position wording |
| Concurrent live tests could invalidate each other's preservation snapshots | Serialize live checks with a PHP lock and test exclusion/cleanup |
| Course copies retained source-specific internal links and lacked portable opt-ins | Back up source opt-ins only; remap to restored Moodle IDs and rebuild transports using the current site's connection |
| Deleting copied transports during restore invoked recycle-bin backups and removed the active restore's temporary ID table | Hide copied transports immediately; delete/rebuild in a separate post-restore task; hold synchronization until restore succeeds |
| Importing into an existing course duplicated its course assistant entry | Retain the destination's existing entry; retire only this backup's redundant restored link after restore completes |

Privacy metadata follows Moodle's
[external-location declaration API](https://moodledev.io/docs/4.5/apis/subsystems/privacy).
No learner name/email is added by the custom proof; it binds Moodle identifiers.
Question records remain owned by VideoQ and its retention/export/deletion policy.

## Verification

The live PHP service contract covers signed service authentication, replay,
audience/scope, tampered/expired/user-mismatched proofs, account suspension,
conditional availability, hidden sources and unenrolled users. New negative tests
cover connection replacement, missing companion, cancellation, foreign tool rows
and stale legacy settings. Its transaction/config cache is restored afterward.

Browser and normal-scheduler checks cover native player/chat, incremental answer,
Good/Bad/reset, citation seek, two-video switching without resetting conversation,
real processing, replacement within the existing course, source deletion and
physical media cleanup. Existing user videos and the active keyless connection
must be preserved. Current results are recorded in
[earlier review evidence](evidence/integration-review.json); older reports are not relabeled.
The [100-learner trial](evidence/capacity-100.json) records the current concurrent
question/video delivery measurements separately.
The [course-portability run](evidence/course-portability.json) verifies real
Moodle copy/archive restore, scoped incremental answers in each destination,
private-history separation and source-preserving deletion with normal schedulers.

## Remaining deployment boundaries

- Pin a VideoQ deployment that implements this service contract. Installing the
  Moodle ZIPs cannot add missing APIs to an arbitrary VideoQ deployment.
- Clean course layout depends on Moodle's **Allow stealth activities** setting
  and a course format supporting it. The plugin does not change global settings.
- Portable opt-in markers require a backup made with local_videoq 0.10.0 or later.
  The destination must have both plugins and a configured connection; Moodle cron
  performs initialization and transfer. Same-site full-course copy and `.mbz`
  restoration, partial activity backups and import into an existing course are
  verified paths. Cross-site restoration remains incomplete: independent video
  synchronization/indexing passed, but native chat readiness timed out in Chrome.
- Remote disconnect is enforced by VideoQ. Platform-side trust revocation also
  requires disabling/removing the registered external tool in Moodle. The plugin
  does not claim that a remote disconnect deletes that Moodle registration.
- Chrome / Boost are verified. Other browsers/themes and large-file limits need
  checks in the intended deployment. The current burst passed 100 real questions
  and 100 authenticated video ranges alongside one native playback observer.
  Completion p95 was 23.4 s; this is not a response-time SLA or a full-length
  100-device playback test. Set latency goals and test the intended infrastructure
  before production sign-off.

These limits prevent treating the trial as a complete production sign-off.

## Follow-up findings

The [full-duration trial](evidence/sustained-100.json) **failed**: all 100
authenticated media responses returned 206, but only 31 complete 281,054,258-byte
files matched the original content hash. Total received was 15,494,199,822 bytes
over approximately 29.5 minutes. HTTP success alone is not full-video success.
The native observer advanced 1,766.9 seconds over 1,767.8 seconds, with one waiting
event, 12 dropped frames and no playback errors. It is one decoder, not 100 devices.

Three real questions during this run succeeded. First text took 9.763, 4.865 and
6.254 seconds; completion took 10.931, 6.114 and 7.839 seconds. The user has not
set a latency target. Three sequential probes are not a new 100-question burst.

The [earlier sustained attempt](evidence/sustained-baseline-aborted.json) was
stopped after this shared 3,909 MiB host exhausted memory/swap. The revised trial
used independent HTTP/1.1 transfers, authenticated core X-Sendfile delivery and a
memory guard. It stayed above 265 MiB available memory, but incomplete transfers
remain unresolved. The client and servers share the host; these results do not
isolate a Moodle server defect or establish production capacity. The revised
checker now also records cURL completion codes for further diagnosis; those codes
were not captured for the reported failed run.

[Firefox / Boost](evidence/firefox-boost.json) passed the held-stream UI suite,
including feedback, citation seeking and responsive widths. This is UI/protocol
verification rather than a real AI answer for that specific run.

One real AI answer during partial-restore verification ended with an SSE error.
The deployed API logged the sanitized reason `tool_after_answer`: the model
requested another tool call after answer output had begun. Moodle-side changes
do not fix this VideoQ/model failure. A later retry must not erase this finding.
The deployed VideoQ source/services were left unchanged.

The [portability follow-up](evidence/portability-followup.json) passed real scoped
answers, Good/Bad/reset, timestamp seeking and private-history separation for a
partial activity archive and import into an existing course. Import retained the
pre-existing course assistant entry. The second site used a separate database,
files, issuer, client ID and signing key, and successfully indexed its own video.
Its Classic-configured Chrome page reached login, the assistant and LTI launch,
but native chat readiness timed out before a question. The cause is unresolved;
Firefox/Classic was therefore not reached. This is not a cross-site UI pass.
All owned test courses, processed videos and physical video files were removed;
the disposable registration was revoked/removed and its containers stopped.
The original video, physical file and active keyless connection were preserved.
