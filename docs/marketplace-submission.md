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
GitHub archives; it does not submit them to Marketplace. The candidate packaging now uses local_videoq 0.11.5 / 2026110602
and ltisource_videoq 0.4.2 / 2026110601. Fresh
installation initializes optional manual settings without asking administrators
to configure a legacy connection. The original lifecycle reports cover 0.11.2;
the earlier DB-update report covers 0.11.3; the current browser report covers
0.11.5 / 0.4.2 with normalized ZIPs.
The automated DB matrix now covers both current English-only candidates through
upgrade, uninstall and reinstall on PostgreSQL and MySQL. Native browser
installation was also repeated with the current normalized candidate ZIPs.
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
| Privacy API | Actual metadata/request interfaces and language identifiers pass; six callbacks per component preserve shared records in real Moodle | External VideoQ request handling still requires deployment-specific verification |
| PostgreSQL / MySQL 8.4.13 | Current English-only ZIPs pass actual old-main upgrade, privacy requests, uninstall and fresh reinstall | Synthetic source-byte fixture; no playback or external-AI claim |

Sanitized reports:

- [Current repeatable PostgreSQL/MySQL matrix](evidence/marketplace-database-matrix.json)
- [Earlier PostgreSQL upgrade](evidence/marketplace-postgresql-upgrade.json)
- [MySQL lifecycle](evidence/marketplace-mysql-lifecycle.json)
- [Stock ZIP validation](evidence/marketplace-archive-validation.json)
- [0.11.3 English-only candidate on PostgreSQL and MySQL](evidence/marketplace-current-candidate.json)
- [Current 0.11.5 / 0.4.2 browser installation](evidence/marketplace-web-installation.json)
- [Current Privacy API requests and preservation](evidence/marketplace-privacy-requests.json)

The earlier manual DB reports used Moodle source `a682566412e1d429b95ac8ef4351a518f61b94c5` (5.1.8+,
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

The 0.11.3 English-only ZIPs were subsequently installed over those 0.11.2
fixtures on both PostgreSQL and MySQL. Runtime schema/privacy checks and original
course/video preservation passed without developer diagnostics. PostgreSQL also
retained the three original synchronization records and signing key. The MySQL
fixture follows its earlier uninstall/reinstall, so its fresh tables and renewed
key are checked against that lifecycle rather than claimed as original records.
[Hosted CI for the same candidate](https://github.com/videoq-jp/videoq-moodle/actions/runs/42058956148)
also passed. Both isolated DB containers were stopped after verification.

## Repeatable installed-Moodle matrix

```sh
php lab/check-marketplace-matrix.php pg /tmp/videoq-marketplace-pg
php lab/check-marketplace-matrix.php mysql /tmp/videoq-marketplace-mysql
```

Requires PHP CLI with ZipArchive/POSIX, Docker, Git and tar on the development/CI
host. Use fresh, empty directories outside the checkout. `MATRIX_DOCKER_SUDO=1`
selects `sudo -n docker` where needed. Optional `MATRIX_MOODLE_GIT_SOURCE` and
`MATRIX_BASELINE_GIT_SOURCE` reuse local Git objects: committed archives are used,
never uncommitted files. Otherwise the pinned official repositories are fetched.
These are development requirements, not plugin runtime requirements.

Source revisions are fixed in `.github/moodle-matrix.json`; PHP, PostgreSQL and
MySQL image digests are in the shared Dependabot manifest. Each run creates a
separate internal network and random-password DB with no published ports. Only
its labelled resources are removed afterward. Unsafe/nonempty targets are
rejected before orchestration. `summary.json` is sanitized; the whole work
directory also holds private environment files and raw logs and must not be shared.

The runner installs actual old-main code, seeds shared course/file/sync state,
updates to current English-only ZIPs, executes schema/privacy/preservation checks,
uninstalls the companion before local, and reinstalls fresh plugin state. Both
local DB runs passed at local_videoq 2026110602 and ltisource_videoq 2026110601.
Synthetic file bytes exercise preservation, not video decoding/playback. The old
baseline's known XMLDB repairs are distinct from candidate diagnostics, which are
checked and absent. Reports recorded before ZIP normalization retain earlier hashes. Use the current
matrix/browser reports for canonical archive hashes; runtime file contents are unchanged.

CI runs both DB jobs after tooling. The existing required-check name aggregates
all three results with `if: always()` and fails when tooling or either DB fails
or is skipped. Release publishing depends on that gate. Historical three-image
release manifests remain supported; the current manifest also pins MySQL 8.4.
The [hosted matrix run](https://github.com/videoq-jp/videoq-moodle/actions/runs/42068103034)
passed both DB jobs and the required gate. Both printed ZIP hashes match the
locally generated archives from native PHP and the pinned image exactly.

## Privacy API audit

The metadata-only providers lacked the request interfaces required for
external-only components by the [current Moodle FAQ](https://moodledev.io/docs/5.1/apis/subsystems/privacy/faq).
Both now implement plugin and userlist request providers. They report no local
personal contexts/users and do not delete shared source material for an individual
request. local_videoq also declares its link to Moodle core's administrator
session, configuration audit and restore-task records. No external deletion is
claimed. See [administrator data-handling notes](privacy.md).

Actual installed-Moodle tests call all six request methods with system, course and
module contexts and nonempty approved requests. Course, file, synchronization and
settings records stay identical. Separate old-main preservation checks also
confirm original source video bytes, signing key and all three synchronization
records survive this candidate update. Schema/metadata inspection runs with
developer debugging and produces no diagnostics.

Normal CI now fetches only the needed real Moodle Privacy API classes at the
pinned official commit and checks metadata, language keys, interface contracts,
empty context results and nonempty export/deletion requests with networking disabled.
The exact [hosted run](https://github.com/videoq-jp/videoq-moodle/actions/runs/42063284963)
passed. Both current English-only components also passed the official plugin-CI
PHPDoc check again with `--max-warnings=0`. Full DB Privacy API tests remain the
separate isolated fixture check.

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

## Reproducible ZIPs

Cross-environment matrix checks exposed different ZIP hashes despite identical
runtime files. Builders now normalize process timezone (UTC), the 1980 timestamp,
Unix file permissions (0644) and stored compression. The small uncompressed
archives avoid compressor-version differences. Both plugin and AMOS builders are
covered by regression tests with three process timezones and source file modes.

Native PHP and the pinned PHP image now produce identical current plugin ZIP
hashes. All 39 local-plugin and 6 LTI-source file contents match the earlier
candidate byte for byte. The matrix builds archives in that same pinned image;
only extraction uses host PHP. The current normalized ZIPs were uploaded and
installed again through Moodle's browser installer, including version/table/key
and optional-setting checks.

Historical tagged builders/assets are unchanged. Earlier evidence preserves its
original ZIP hashes; it must not be mistaken for the new normalized artifact.

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
73 local_videoq and 6 ltisource_videoq translations. Data-only parsing, exact key
coverage, placeholder checks and archive-content tests pass; bad placeholders
and executable statements are rejected before evaluation. An English string's
leading-space dependency was removed, with spacing supplied by the ARIA label.
No translation contribution, new release or Marketplace upload was performed.
Historical release verification remains unchanged. See [workflow](translations.md).

## Remaining gates

- Make the verified official plugin-CI checks repeatable in CI and run applicable
  Moodle unit checks with both components available.
- Verify the deployment-specific external data-request/retention process, and
  complete developer-debug functionality and video/chat accessibility checks.
- Submit the prepared Japanese AMOS input after component availability, obtain
  acceptance and verify Japanese language-pack delivery. English-only submission
  packages and a checked 79-string import now build in CI; see [translation guide](translations.md).
- Capture sanitized course assistant and citation screenshots; the current setup
  and ZIP-validation evidence is already available.
- Set up reviewer access, settle publisher/listing decisions and final descriptions.
- Reconcile documentation overlap with PR #3 before merging PR #4; select release
  maturity/support versions truthfully and publish reproducible archives.
- Submit both components, address reviewer feedback, publish approved pages and
  verify installation from the actual official listing in a clean Moodle site.

Do not mark the goal complete on ZIP creation, GitHub publication or submission
alone. Approved listings and directory-based installation need direct evidence.
