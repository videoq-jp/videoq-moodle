# VideoQ for Moodle

Connect each Moodle course to a teacher-owned VideoQ course. Install **one plugin: `local_videoq.zip`**.

Requires Moodle 5.1 and a compatible VideoQ service. Development preview, tested with Boost. Marketplace approval is pending.

## Install and connect

1. Download `local_videoq.zip` and `SHA256SUMS` from the [current release](https://github.com/videoq-jp/videoq-moodle/releases). Verify the checksum and upload the ZIP through **Site administration → Plugins → Install plugins**.
2. As an administrator, open **Connect to VideoQ**, select the VideoQ HTTPS service, sign in, and approve communication with the school.
3. As a teacher, open **Connect my VideoQ account** from the Moodle course. Sign in to your own VideoQ account and choose an existing course you own.
4. Learners open the course assistant in Moodle. They do not need VideoQ accounts.

Upload and edit videos in VideoQ. Moodle provides its native learning screen with video selection, overview, course Q&A, AI questions, citations and feedback. Chats resume automatically. Only the connected owner teacher can view course question history and export learner names/emails, questions, answers and feedback as CSV.

## Upgrade from 0.25.3 or earlier

Upgrade `local_videoq` in place; do not uninstall it. Version 0.25.4 removes the setup dependency on the retired `ltisource_videoq` helper and ships only the local plugin. An already installed helper does not block this update. Generated VideoQ activity links open the native course assistant automatically. After checking the new course connection, uninstall **only `ltisource_videoq`** from Moodle's plugin overview, then remove its code if Moodle cannot do that automatically. Do not delete Moodle courses, videos, or the local plugin.

The service must include the teacher-owned connection API and DB migrations 0062–0064. Legacy installation-wide ownership/import is retired; teachers reconnect their accounts and select existing VideoQ courses. Moodle source files are not imported or moved by this update.

See the [setup guide](docs/setup.md), [日本語の導入手順](docs/setup.ja.md), and [verification](docs/verification.md). The [lab](lab/README.md) contains developer tooling; learners and site administrators do not install it.
