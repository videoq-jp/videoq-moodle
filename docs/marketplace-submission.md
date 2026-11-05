# Moodle Marketplace submission

Preparation in progress. Neither component has been submitted, approved or
published. Passing the checks below does not establish official approval.

## Current official guidance

Checked on 2026-11-05:

- [Current submission guidelines](https://moodle.atlassian.net/wiki/external/ODRhYjAyNTY4NDVmNGJlNjljN2ViMzkwYzdmYmIwMGI)
- [Marketplace](https://marketplace.moodle.com/)
- [Official contribution entry point](https://moodledev.io/general/community/plugincontribution/checklist)
- [Official submission/API FAQ](https://docs.moodle.org/all/es/plugin)

The old Plugins Directory checklist is marked legacy. The current guidelines
were read with a JavaScript-capable fetcher. The official process requires an
account, component ZIP upload, automated checks, review and explicit publication
after approval. Version-upload automation through the old API is unavailable.

## Decisions needed from the publisher

- Marketplace account and publisher identity: not provided yet.
- Free/paid listing classification: not selected yet. Current guidelines require
  Marketplace purchase when provider-owned paid service access is mandatory.
  Confirm whether the supported free self-hosted deployment qualifies, or arrange
  Marketplace sales. Do not classify a mandatory externally purchased VideoQ
  service as a free listing. The descriptions below remain drafts.
- Reviewer access: prepare a public, isolated demonstration site and deliver
  credentials privately through the review channel. Do not use production data,
  billing credentials, AI-provider keys or private signing keys.

## Component packages

| Component | Purpose | Dependency | Archive |
| --- | --- | --- | --- |
| `local_videoq` | Course video synchronization and AI question interface | Moodle LTI activity | `local_videoq.zip` |
| `ltisource_videoq` | LTI launch integration | `local_videoq` (version.php) | `ltisource_videoq.zip` |

Confirm component-name availability during submission. Source and issue tracker:
https://github.com/videoq-jp/videoq-moodle (public monorepo).

Build with `php scripts/package-plugins.php <output-directory>`. Each archive has
a single `videoq/` root, its own license, version metadata and README. GitHub's
whole-repository ZIP is not an installer. The release workflow publishes checked
GitHub archives; it does not submit them to Marketplace. The candidate packaging now uses local_videoq 0.11.4 / 2026110601. Fresh
installation initializes optional manual settings without asking administrators
to configure a legacy connection. The original lifecycle reports cover 0.11.2;
the DB-update report covers 0.11.3 and the browser-install report covers 0.11.4.
Release maturity remains alpha; support claims currently cover Moodle 5.1/Boost only.

The additional official plugin-CI checks use release 4.5.11, whose downloaded
PHAR was verified against the published SHA-256
`39b5d0d71f5ab88fac1c3f5fc1166b8673bc0a488b29cd8c56a8bca56a8f8bca`.
PHPDoc was checked with `--max-warnings=0` against the packaged English-only
components in writable isolated copies of Moodle and plugin code. Eight missing
method parameter contracts were corrected without changing executable behavior.

## Proposed listing descriptions

### local_videoq — VideoQ course video assistant

Short: Ask questions across your Moodle course videos while watching them in
Moodle, with streamed AI answers and video citations.

Full: VideoQ adds a course-wide assistant beside Moodle video playback. Teachers
enable questions for File activities; additions, replacements and deletions
synchronize to a compatible VideoQ server for transcription and indexing. Moodle
controls enrollment and source playback. VideoQ supplies answers, citations and
answer feedback. Start setup in Moodle by entering a VideoQ HTTPS URL, signing in
to the owning account and approving the connection. Install ltisource_videoq too.
A compatible VideoQ server, owning account and processing allowance are required.
Hosting and AI processing are not included in the GPL plugin. Final service and
pricing disclosure must match the selected listing classification.

### ltisource_videoq — VideoQ LTI integration

Short: LTI 1.3 launch integration for the VideoQ course video assistant. Requires
local_videoq and a compatible VideoQ server.

Full: Install this companion alongside local_videoq and start setup from Moodle's
Connect to VideoQ administration page. Moodle user, course and resource identifiers
are exchanged with the chosen VideoQ server for access control. The plugin is GPL
software; hosting and AI processing are separate deployment requirements. Final
service and pricing disclosure must match the selected listing classification.

Documentation: https://github.com/videoq-jp/videoq-moodle/blob/main/docs/setup.md
Issues: https://github.com/videoq-jp/videoq-moodle/issues

## Verified technical evidence

| Check | Current evidence | Limits |
| --- | --- | --- |
| Official Moodle coding standard | moodlehq/moodle-cs 3.7.0, PHPCS 3.13.6: 0 errors, 0 warnings | Not the entire moodle-plugin-ci suite |
| Official plugin CI 4.5.11 | Both components pass structure and PHPDoc checks; local upgrade savepoints pass | Companion has no upgrade file; other suite commands are not implied |
| Moodle ZIP validator | Both English-only archives accepted by the stock validator | Alpha maturity warnings remain |
| Browser installation | Both ZIPs uploaded and installed through stock Moodle administration; actual versions/tables/key verified | Dedicated loopback PostgreSQL fixture; not official-directory installation |
| PHP tooling and packages | Standalone PHP, installer/dependency/release, HTTP/SSE and RSA tests pass | No paid-AI claim |
| Privacy metadata | Both actual Moodle metadata providers and language identifiers pass; feedback explicitly declared | Full external-data lifecycle review still required |
| PostgreSQL | Fresh lifecycle passes; old-main upgrade preserves teaching data, all three table records and access key | See scope in linked report |
| MySQL 8.4.13 | Actual old-main upgrade, uninstall with source-data retention and fresh candidate reinstall pass | See scope in linked report |

Sanitized reports:

- [PostgreSQL upgrade](evidence/marketplace-postgresql-upgrade.json)
- [MySQL lifecycle](evidence/marketplace-mysql-lifecycle.json)
- [Stock ZIP validation](evidence/marketplace-archive-validation.json)
- [0.11.3 English-only candidate on PostgreSQL and MySQL](evidence/marketplace-current-candidate.json)
- [0.11.4 browser installation](evidence/marketplace-web-installation.json)

Both used Moodle source `a682566412e1d429b95ac8ef4351a518f61b94c5` (5.1.8+,
Build 20261030), pinned PHP 8.3, developer debugging and isolated DBs. The baseline
was rebuilt from actual old-main `2d195b22a14a11365f256b3c5f0c9ca242b93827`
(0.11.0); candidate runtime version is 2026110503 / 0.11.2. Video byte hashes,
record values and key identity were checked in fresh processes. MySQL archive
hashes, image digest and verified utf8mb4_unicode_ci collation are in its report.
Audit DB containers are stopped; original trial sites were not modified.

Installed tests exposed two old XMLDB empty-default diagnostics (`fingerprint`,
`errorcode`). Source defaults were corrected to Moodle's canonical form. Candidate
upgrade/uninstall/reinstall and XMLDB inspection pass without repair diagnostics;
the old baseline is not falsely described as clean.

No-warning coding changes were checked by AST comparison. Literal concatenations
preserve the original SQL bytes; redundant internal guards were removed only from
files individually flagged as definition-only by the official standard. Token
rejection uses Moodle diagnostics with debug display suppressed for OAuth JSON.
Its failure fixture verifies HTTP 401, JSON integrity and no exception disclosure.

The current English-only ZIPs were subsequently installed over those 0.11.2
fixtures on both PostgreSQL and MySQL. Runtime schema/privacy checks and original
course/video preservation passed without developer diagnostics. PostgreSQL also
retained the three original synchronization records and signing key. The MySQL
fixture follows its earlier uninstall/reinstall, so its fresh tables and renewed
key are checked against that lifecycle rather than claimed as original records.
[Hosted CI for the same candidate](https://github.com/videoq-jp/videoq-moodle/actions/runs/42058956148)
also passed. Both isolated DB containers were stopped after verification.

## Browser installer verification

`lab/check-marketplace-web-install.php <archive-directory>` drives native Chrome
through the same PHP WebDriver helper as other browser checks. It requires an
explicit fixture password, external `LTI_LAB_DIR`, owned running fixture container
and exactly `http://127.0.0.1:18881`. A CLI preflight checks the dedicated database
and rejects existing plugin code or installed component versions before upload.
This is development tooling, excluded from both plugin archives.

The checked flow uploads local_videoq then ltisource_videoq, follows stock
validation/dependency/database-upgrade pages, rejects a forced manual setup prompt,
opens Connect to VideoQ and checks installed versions, three tables, initialized
access key and empty optional defaults in a fresh PHP process. The fixture uses
PostgreSQL and synthetic audit data. No production connection was authorized.
Core server recommendations for this HTTP development fixture are recorded as
limits rather than claimed as absent. Alpha warnings are preserved.

Captured evidence, with only synthetic audit identity and no credentials:

- [Connection screen after installation](evidence/images/marketplace-connection.png)
- [Actual ZIP validation screen](evidence/images/marketplace-zip-validation.png)

These are evidence screenshots; course/citation images for the final listing and
actual reviewer access remain to be prepared.

## Repeatable coding check

Development only: no Composer tooling is distributed in Moodle installers.
Use the pinned PHP image from `lab/images/compose.yaml`, as normal CI does.
Manifests live under `.github/moodle-codecheck`; the transitive graph is locked.
Composer 2.11.1 is checked against its pinned official SHA256 before execution.
Package scripts and plugins are disabled. Dependabot tracks the PHP tool graph;
Composer binary/version updates require a reviewed checksum update.

With a fresh work directory outside the checkout:

```sh
php scripts/check-moodle-code.php prepare /tmp/videoq-moodle-codecheck
php scripts/check-moodle-code.php scan /tmp/videoq-moodle-codecheck
```

Normal CI prepares tools with network access, then scans with `--network none`
and read-only source mounts. Any error or warning fails the scan. Local execution
and [hosted CI](https://github.com/videoq-jp/videoq-moodle/actions/runs/42041868560)
of these exact stages passed. Both prepare and offline scan steps were confirmed. Offline
failure tests reject unrelated directories, corrupted cached binaries and stale
manifests before tool execution. This is not a full moodle-plugin-ci run.

## Translation preparation

`package-plugins.php --marketplace` builds English-only official submission
archives; the ordinary development profile retains the existing Japanese UI.
`scripts/package-translations.php` produces a flat, reproducible AMOS ZIP with
72 local_videoq and 6 ltisource_videoq translations. Data-only parsing, exact key
coverage, placeholder checks and archive-content tests pass; bad placeholders
and executable statements are rejected before evaluation. An English string's
leading-space dependency was removed, with spacing supplied by the ARIA label.
No translation contribution, new release or Marketplace upload was performed.
Historical release verification remains unchanged. See [workflow](translations.md).

## Remaining gates

- Make the verified official plugin-CI checks repeatable in CI and run applicable
  Moodle unit checks with both components available.
- Automate the installed-Moodle DB matrix in CI against the final release candidate.
- Complete external data/retention/privacy review, developer-debug functionality
  tests and accessibility checks for the video/chat interface.
- Submit the prepared Japanese AMOS input after component availability, obtain
  acceptance and verify Japanese language-pack delivery. English-only submission
  packages and a checked 78-string import now build in CI; see [translation guide](translations.md).
- Capture sanitized setup, course assistant and citation screenshots.
- Set up reviewer access, settle publisher/listing decisions and final descriptions.
- Reconcile documentation overlap with PR #3 before merging PR #4; select release
  maturity/support versions truthfully and publish reproducible archives.
- Submit both components, address reviewer feedback, publish approved pages and
  verify installation from the actual official listing in a clean Moodle site.

Do not mark the goal complete on ZIP creation, GitHub publication or submission
alone. Approved listings and directory-based installation need direct evidence.
