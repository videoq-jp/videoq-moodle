# Course entry without videos

The local plugin can create a course-owned VideoQ LTI entry without adding a
dummy video File activity. It enables coursewide Q&A, personal-history controls
and teacher tag vocabulary according to current Moodle capabilities. AI chat and
transcripts still require readable, processed material. This is unreleased work;
it does not complete all VideoQ feature parity or canonical course deletion.

## Teacher setup

1. Upgrade the backend with migration 0059 and course-entry support, then upgrade
   `local_videoq` to 0.18.0 / 2026110701. Drain older backend writers before enabling
   entries. The configured backend must advertise `courseEntries: true`.
   Use a regular course; the site frontpage is not a supported course entry.
2. Open **Manage VideoQ course entry** in course settings, or follow the setup link
   from the empty VideoQ course view. Both `local/videoq:managecontent` and
   `moodle/course:manageactivities` must be granted at course context. A module-only
   manager and a non-editing teacher do not gain course setup rights.
3. Select **Enable course entry**. The POST checks the Moodle session key, validates
   the approved connection and creates a real private LTI activity and the stable
   course assistant URL. No media file, learner account or backend upload is made.
4. Wait for synchronization, then open the course assistant. An empty course shows
   the standard private LTI UI. With readable videos it reuses the native player
   and controls. No fake playback ID is assigned.

Repair reuses a valid owned activity. If an old activity was deleted, moved or
changed to a different tool/destination, an explicit teacher repair creates a new
one and leaves that modified activity alone. Background synchronization reconciles
existing entries and never creates an activity. Disabling revokes this entry and
retains shared course data and other video activities.

## Access and compatibility

The retained `local_videoq_courses` row contains activity/course identifiers,
integration state, generation and synchronization status, with no learner data.
It survives core activity/course deletion long enough to send a tombstone.

A signed launch proof includes `entryGeneration`. On every access, Moodle checks
the current entry, course, actual LTI module/type/destination, generation, user
suspension, enrolment, hidden-course rights, activity availability and `mod/lti:view`.
Current course capabilities govern each Q&A/history/content action. A disabled or
retired entry cannot obtain a source-style proof through another video activity.
An empty current resource scope permits no source-bound Q&A or hidden conversation
contents. Teacher role claims do not grant access to another learner's history.

Materials-service callers must send `includeCourseEntries: true`. Only those
callers receive course entries in discovery and complete snapshots, or
`courseEntry: { generation }` in access responses. Old callers retain video-only
responses. A snapshot contains at most 1,000 videos and one entry. Entry
acknowledgements are generation-bound and never create file exports.

## Verification

`tests/course-entry.php` checks backend negotiation, bounded verified HTTPS,
course-wide authority, private LTI creation, repair reuse and offline disable.
The installed PostgreSQL/MySQL matrix also runs `lab/check-course-entry.php` after
upgrade and reinstall, using real Moodle activities, signed proofs, role changes,
suspension, hidden activities, disable and core deletion. These checks do not
replace normal browser setup and real backend launch acceptance. Full VideoQ
feature parity, real AI/source lifecycle, wider owner/admin/privacy acceptance and
coordinated release remain separate gates. Existing Stripe billing is unchanged.
