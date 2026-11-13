# VideoQ for Moodle

Watch linked VideoQ course videos and ask VideoQ questions across the course.

Requires Moodle 5.1 and a compatible VideoQ deployment. Development preview,
tested with the Boost theme.

Learning and teacher screens use Moodle templates, login and course permissions.
The browser talks to Moodle; PHP calls VideoQ's shared AI/search/storage services
over verified HTTPS. No VideoQ presentation or authentication iframe is used.
This preview requires the paired backend with native learning protocol 1.
Public sharing, billing and global VideoQ administration remain outside the
Moodle interface. See [current verification](docs/verification.md).

## Installation

Marketplace submission is being prepared. These plugins are not yet listed or
approved. The manual steps below are for development and evaluation; the target
is installation from the official listing once review and publication complete.

1. Install both VideoQ components through Moodle's **Install plugins** screen.
   The target is the official directory after approval. During evaluation, upload
   the matching component ZIPs provided for that build (local plugin first).
   See the [installation guide](docs/setup.md); Git is an optional operator method.
2. In Moodle, open **Site administration → Plugins → Local plugins → Connect to
   VideoQ** and enter your cloud or on-premises VideoQ HTTPS URL.
3. Sign in to VideoQ and allow the connection to return to Moodle automatically
   (the current `https://videoq.jp` service supports this flow).
4. As a course teacher, choose **Connect my VideoQ account**, sign in to VideoQ and select an existing course.
5. Add/edit videos in VideoQ. Learners open the course assistant in Moodle; no VideoQ student account is needed.

Version 0.25.1 removes Moodle upload/import controls. The workspace follows the VideoQ/Classroom arrangement with Moodle theme styles: video and Overview/Q&A at left, Videos/AI tabs at right.
Apply the matching VideoQ API/web changes and migrations 0063/0064 before upgrading.

See the [setup guide](docs/setup.md) for details and the
[development guide](lab/README.md) for building and testing.

The viewer matches VideoQ: learner chats continue automatically, without new-conversation or private-history controls. The connected owner teacher has course question/answer history and CSV; other teachers and learners cannot read the owner logs.
