# Standalone local_videoq 0.25.4 verification

A real installed Moodle 5.1.8+/Boost instance was updated in place, then the old helper was uninstalled with Moodle's CLI and its code removed. `result.json` contains only non-secret verification metadata.

The actual browser completed administrator school authorization against the local VideoQ service without the helper. A teacher created a new connection from a newly enrolled Moodle course to an existing owned VideoQ course. A student played the video (time advanced and decoded frames), received a real local-model answer with citations, submitted feedback, and restored the conversation/feedback by reloading.

Both owned `/mod/lti/view.php` and `/mod/lti/launch.php` links opened the native viewer rather than an external launch. Course navigation exposed one native assistant link and no generated LTI links. Owner history was absent for the learner. Real signed Moodle API requests returned two videos for teacher/student/co-teacher, CSV 200 with the student's profile and new question for the connected teacher, and CSV 403 for student/co-teacher. Original-course history remained available after the helper uninstall.

The test used the existing isolated `videoq-linked-20261113` environment and owned synthetic accounts/course data. Passwords, cookies, pairing tickets, signed media URLs, signing keys and raw CSVs remain outside the repository. These checks do not claim a production Moodle upgrade, fresh video transcription/indexing, Google Classroom real-domain acceptance, or iPhone hardware coverage.
