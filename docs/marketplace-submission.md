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
The current Confluence guidelines were subsequently read with a JavaScript-capable
fetcher on 2026-11-05. Use that current document, rather than the legacy checklist.

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

## Privacy audit progress (2026-11-05)

- The three local plugin tables contain material sync state, replay hashes and
  export jobs; no learner conversation or user-ID column is declared there.
- `course_access::launch()` sends an expiring user/course/activity-bound proof.
  `ltisource_videoq` declares those identifiers as an external transfer.
- `chat.js` transfers questions, answers and Good/Bad feedback through the
  origin-checked bridge. Explicit feedback metadata and English/Japanese
  descriptions have now been added; the service summary includes video transfer.
- Moodle core owns login/admin session storage, and VideoQ owns AI history.
  This is a storage-boundary observation, not a claim that no personal data is
  processed. Video content, titles and conversation text can contain personal data.
- `php tests/privacy.php /path/to/moodle` checks both providers with actual
  Moodle 5.1 metadata classes and all shipped privacy language identifiers.
  It runs without a DB and with core debugging disabled. It does not replace
  developer-debug installation tests or verify export/deletion of service data.
- `lab/check-privacy.php` includes the new feedback declaration for a subsequent
  full Moodle runtime run. That runtime run is still pending.

## Current guideline findings and code-check baseline

The current guidelines require English-only strings in distributed plugins,
standard copyright/license headers, declared companion dependencies, working
ZIP installation, Privacy API support and multi-database testing. Review must
also cover service charges: provider-owned paid services cannot be required by
a free listing through an external purchase flow. The publishing/payment model
must be settled with the project owner before selecting free/paid listing status.
The descriptions above are drafts, not a final classification or approval claim.
Japanese translation delivery through AMOS remains to be planned before submission.

An isolated PHP development toolchain was installed outside this repository:
Composer 2.11.1 (official SHA256 verified), moodlehq/moodle-cs v3.7.0 and locked
transitive dependencies. No installer/runtime dependency was added to the plugins.
The initial full PHPCS run reported 578 errors and 102 warnings (386 fixable),
mainly compact control structures, long lines, docblocks and license headers.
Both Privacy API providers now pass the full Moodle standard with zero findings.
The remaining plugin files have not yet been corrected. Do not describe the whole
plugin as passing PHPCS based on those two files.

Reproduce the isolated scan using PHPCS with the Moodle standard, registering
phpcsstandards/phpcsextra and moodlehq/moodle-cs as installed standards. The exact
Composer lock, JSON findings and installation log are kept in the working
environment's private `videoq-marketplace-audit` directory. A portable checked-in
PHP-only audit runner and official plugin-CI integration remain to be completed.

## Formatting pass (2026-11-05)

Moodle PHPCBF corrected 384 findings in a disposable copy of 35 PHP files.
Before copying changes back, nikic/php-parser v5.9.0 parsed the original and
candidate files and compared node types and all subnodes, ignoring only source
positions/comments. All 35 syntax trees matched; no processing statements were
changed. The full existing standalone PHP test suite, installer/dependency
contracts and both real-Moodle metadata tests then passed.

A new full PHPCS scan reports **171 errors and 81 warnings** (zero automatically
fixable). Most remaining findings concern docblocks, copyright/license tags,
long lines and unneeded internal guards. One forbidden-function finding flags
`error_log()` in token.php and requires individual review. Full official code
compliance, installed-Moodle developer-debug tests, database compatibility,
listing classification, reviewer access and approval are still incomplete.

## Header and endpoint diagnostics pass (2026-11-05)

All previously missing file-level copyright/license annotations and single-line
PHPDoc descriptions were corrected. An AST comparison before the diagnostics
change confirmed those edits changed comments only.

The token endpoint now uses Moodle developer diagnostics instead of direct
`error_log()`. `NO_DEBUG_DISPLAY` is set before bootstrapping Moodle to preserve
the OAuth JSON response. A standalone test exercises the real endpoint file with
an isolated authentication failure and a diagnostic-display fixture: response
remains JSON with HTTP 401, diagnostics are recorded and exception details are
not exposed. The test is included in normal PHP-only CI. A full installed-Moodle
developer-debug exercise is still required; the fixture is not that exercise.

Full standalone PHP tests passed after the change. Official PHPCS now reports
**60 errors and 80 warnings**, mainly missing method documentation and long lines.
The earlier forbidden `error_log()` finding is resolved. No suppressions were added.

## Function documentation pass (2026-11-05)

Added missing method documentation with parameter/return types and descriptions,
member/constant documentation, and wrapped over-limit expressions. Full official
Moodle PHPCS now reports **0 errors and 81 warnings**. Warnings still need work;
this is not a clean PHPCS result or Marketplace approval.

The 22 changed PHP files have identical syntax trees to the preceding commit
when source positions and comments are excluded. Full standalone PHP tests,
including real Moodle metadata classes, endpoint rejection, packaging and real
RSA signing, passed. Installed-Moodle developer debugging, cross-database tests,
AMOS translation preparation and the publication/payment decision remain open.
