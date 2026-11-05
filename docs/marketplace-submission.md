# Moodle Marketplace submission

Status: preparation in progress. Neither component is submitted or approved.

## Current official entry points

- https://marketplace.moodle.com/
- https://docs.moodle.org/all/es/plugin (English FAQ content)
- https://moodledev.io/general/community/plugincontribution/checklist
- Current submission guidelines linked from that checklist:
  https://moodle.atlassian.net/wiki/external/ODRhYjAyNTY4NDVmNGJlNjljN2ViMzkwYzdmYmIwMGI

Checked 2026-11-05. The old directory contribution checklist is explicitly legacy.
The official FAQ describes signing in, uploading a component ZIP, completing
archive/CI checks, review through Jira Service Management, and manual publication
after approval. Marketplace version upload by API is currently unavailable.
The current Confluence guidelines must be reviewed in a browser before submission;
the text could not be retrieved by the documentation client used for this audit.

## Two component submissions

| Component | Purpose | Dependency | Archive |
| --- | --- | --- | --- |
| `local_videoq` | Course video synchronization and AI question interface | Moodle LTI activity | `local_videoq.zip` |
| `ltisource_videoq` | LTI launch integration for VideoQ | `local_videoq` (version.php) | `ltisource_videoq.zip` |

Build component ZIPs with `php scripts/package-plugins.php <output-directory>`.
Do not submit GitHub's whole-repository source ZIP. Each component archive must
contain a single `videoq/` root, its license, version metadata and its own README.
The release workflow can publish checked archives to GitHub; it does not submit
them to Marketplace. Confirm component-name availability in the submission form.

## Proposed listing text

### local_videoq — VideoQ course video assistant

Short description: Ask AI questions across your Moodle course videos while
watching them in Moodle, with streamed answers and video citations.

Full description: VideoQ adds a course-wide AI assistant beside Moodle video
playback. Teachers enable questions for File activities; video additions,
replacements and deletions synchronize to a compatible VideoQ server for
transcription and indexing. Moodle controls enrollment and source-video playback.
VideoQ supplies answers, citations and answer feedback. Setup starts in Moodle:
enter a VideoQ HTTPS URL, sign in to the owning account and approve the connection.
Install the companion ltisource_videoq plugin as well. A VideoQ server, owning
account and available processing allowance are required. Cloud hosting and AI
usage may incur separate charges; the GPL Moodle plugin itself is free.

### ltisource_videoq — VideoQ LTI integration

Short description: LTI 1.3 launch integration for the VideoQ Moodle course video
assistant. Requires the local_videoq companion plugin and a VideoQ server.

Full description: This companion supplies the Moodle LTI integration used by
VideoQ course activities. Install it alongside local_videoq and start setup from
Moodle's Connect to VideoQ administration page. Moodle user, course and resource
identifiers are exchanged with the chosen VideoQ server for access control.
Hosting and AI processing are separate services, not included with the GPL plugin.

Source: https://github.com/videoq-jp/videoq-moodle
Issues: https://github.com/videoq-jp/videoq-moodle/issues
Documentation: https://github.com/videoq-jp/videoq-moodle/blob/main/docs/setup.md

## Evidence still required before submission

- Read the current guidelines in full and record applicable requirements.
- Establish the publisher account and confirm component-name availability.
- Audit both Privacy API providers against actual database storage and external
  transfers, including administrator/user identifiers, conversations and files.
- Run Moodle's official plugin CI/coding checks for each component with the
  companion dependency available. Existing repository tests are not equivalent.
- Verify install, upgrade and uninstall with developer debugging enabled on the
  supported Moodle version; include PostgreSQL and MySQL/MariaDB evidence.
- Resolve review blockers and select truthful release maturity/support versions.
- Capture sanitized screenshots of setup, course video/chat and citation behavior.
- Prepare reviewer access to an isolated demonstration site without real learner
  data or production secrets; transmit credentials only through the review channel.
- Build reproducible archives and record commit, checksums and evidence links.
- Submit both components, address review feedback and publish after approval.
- Verify actual installation from the approved listing in a clean Moodle site.

Do not mark this goal complete on ZIP generation, GitHub publication or submission
alone. Approved listings and directory-based installation need direct evidence.
