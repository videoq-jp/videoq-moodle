# Install and connect VideoQ for Moodle

Version 0.25.5 has one installable component, **local_videoq**. The `ltisource_videoq` helper distributed through 0.25.3 is retired. There is no second installer to choose or install.

## Requirements

- Moodle 5.1; verified with Moodle 5.1.8+ and Boost.
- The current VideoQ cloud service at `https://videoq.jp`, or a compatible HTTPS on-premises deployment with teacher-owned connection APIs and migrations 0062–0064.
- A Moodle administrator for installation/school authorization, and an editing teacher with an existing VideoQ account and an owned VideoQ course.

Marketplace listing/approval is not complete. GitHub releases retain the project's prerelease designation. Install in a test Moodle before school-wide rollout.

## New installation

1. Download **local_videoq.zip** and **SHA256SUMS** from the [release page](https://github.com/videoq-jp/videoq-moodle/releases). Only the ZIP is a plugin; the checksum file verifies it.
2. Verify the ZIP against its SHA-256 entry. In Moodle, open **Site administration → Plugins → Install plugins**, upload `local_videoq.zip`, and complete the standard validation/database installation.
3. Open **Site administration → Plugins → Local plugins → Connect to VideoQ**. Choose the cloud or your own service's HTTPS address.
4. Sign into VideoQ in the browser and approve communication with this school. Return to Moodle to finish the administrator connection. This grants installation/transport access, not ownership of every course or use of the administrator's learning quota.
5. As an editing teacher, open a Moodle course and select **Connect my VideoQ account**. Sign into your own VideoQ account, select an existing course you own, and return to confirm the connection.
6. Learners open the course assistant. Enrollment and current Moodle course permissions control access; learners do not register in VideoQ.

No separate LTI-helper plugin, manual LTI launch activity, sync token, video-import service, or Moodle-side video upload is needed. Keep secrets in the service runtime rather than chat, screenshots or installation notes.

## Teacher ownership and learning

Each Moodle course has one responsible teacher/account/course connection. Co-teachers may use the linked learning materials but cannot replace another teacher's account or download the owner's question history. The connected owner must still have the current Moodle content-management permissions to read/export it.

Manage videos, processing and native course contents in VideoQ. The Moodle screen follows the same course arrangement using Moodle templates and theme styles. It includes video selection, overview, course Q&A, AI questions, citations and feedback. Conversations resume automatically; learners do not get separate new-conversation/private-history controls. Mobile exposes overview through Details. The owner sees the latest 100 question logs and can export all matching logs as CSV.

## Existing installations: update without losing courses

1. Update the VideoQ service first. Cloud service updates are handled centrally; on-premises operators use the normal migration/deployment procedure.
2. Back up Moodle through your normal procedure. Upload **local_videoq.zip** as an update and complete Moodle's database upgrade. **Do not uninstall local_videoq**: an in-place update preserves its settings and historical records.
3. If the school transport is already configured, retain it. If not, complete the administrator authorization above. Each teacher connects their own VideoQ account and chooses an existing course; installation-wide ownership is not inherited automatically.
4. Open the course assistant and confirm the teacher/learner flow.
5. If `ltisource_videoq` was previously installed, uninstall **only that helper** from **Site administration → Plugins → Plugins overview**. If Moodle leaves its source directory behind, an operator removes `public/mod/lti/source/videoq` (or `mod/lti/source/videoq` in a non-public-root layout) and completes the notifications screen. The local plugin is not dependent on it, and learner screens do not use its launch callback.

Leaving an installed old helper temporarily does not prevent updating or connecting the local plugin. Generated VideoQ activity links now open the native course assistant automatically. Source videos, native VideoQ courses and shared Moodle courses must not be deleted as part of this cleanup.

## Optional Git deployment

Copy only `plugins/local/videoq` to Moodle's `public/local/videoq` (or `local/videoq` for that layout), then run Moodle's ordinary database upgrade. There is no `mod/lti/source` component in the current source distribution.

## Disconnect and uninstall

Teachers disconnect their individual course connection from VideoQ connected-app settings and repeat the course connection flow to reconnect. Another teacher cannot silently take over an existing connection.

To remove the entire integration, first disconnect the school through the normal administrator flow, then uninstall `local_videoq` in Moodle's plugin overview. This removes the plugin's Moodle tables/settings; it is different from an in-place update or removing the retired helper. It does not erase VideoQ course content. Follow [privacy handling](privacy.md) for external learner data requests.

See [verification](verification.md) for test scope and [the lab](../lab/README.md) for developer-only tooling.
