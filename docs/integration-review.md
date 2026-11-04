# Moodle integration review — 2026-11-04

Scope: `videoq-moodle`, its HTTPS contract with the deployed VideoQ trial,
Moodle 5.1.8+ / Boost, installation, access control, synchronization, UI, privacy,
PHP-only tooling and release publication. Candidate: local_videoq 0.9.1 and
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
[evidence](evidence/integration-review.json); older reports are not relabeled.

## Remaining deployment boundaries

- Pin a VideoQ deployment that implements this service contract. Installing the
  Moodle ZIPs cannot add missing APIs to an arbitrary VideoQ deployment.
- Clean course layout depends on Moodle's **Allow stealth activities** setting
  and a course format supporting it. The plugin does not change global settings.
- Course copy/import and individual `.mbz` backup/restore do **not** yet remap
  site-level bindings and generated links. Whole-database/object-store recovery
  checks are a different capability. Add dedicated restore support before relying
  on course duplication in production.
- Remote disconnect is enforced by VideoQ. Platform-side trust revocation also
  requires disabling/removing the registered external tool in Moodle. The plugin
  does not claim that a remote disconnect deletes that Moodle registration.
- Chrome / Boost are verified. Other browsers/themes and large-file limits need
  checks in the intended deployment. Two-learner question concurrency evidence
  does not prove capacity for 100+ learners or simultaneous video playback.

These limits prevent treating the trial as a complete production sign-off.
