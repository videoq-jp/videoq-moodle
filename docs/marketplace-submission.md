# Moodle Marketplace submission

Preparation in progress. Neither component has been submitted, approved or
published. Account access, commercial decisions and review are still required.
Passing local/CI checks does not establish official approval.

Current candidates: local_videoq **0.11.6 / 2026110603** and ltisource_videoq
**0.4.2 / 2026110601**. Both remain alpha. Verified support is Moodle 5.1/Boost;
other versions/themes and production capacity are not certified by these checks.

## Official requirements

Rechecked on 2026-11-06. The old Plugins Directory checklist is legacy; use:

- [Quick listing guide](https://moodle.atlassian.net/wiki/external/MzFlM2RkYjM3ZDVhNDgyMGJmYjA2ZjIyMzQ1NDRlYmY)
- [Submission workflow](https://moodle.atlassian.net/wiki/external/MTA2NzQ5NWI0YmVmNDMxMWEzZWEyZjBjM2JmYzcyMDY)
- [Submission guidelines](https://moodle.atlassian.net/wiki/external/ODRhYjAyNTY4NDVmNGJlNjljN2ViMzkwYzdmYmIwMGI)
- [Sales, payments and fees](https://moodle.atlassian.net/wiki/external/MDY5MDE4OGYwMzNmNDYzNjlhNGM3NmJmMzc2ZGJlN2U)

The process is submission → automated checks → review → explicit publication.
Approval alone does not publish a listing. The old version-upload API is unavailable.

## Publisher decisions

- **Account:** Marketplace requires a separate account; a Moodle.org login cannot
  be used directly. The submitter becomes lead maintainer. Choose that identity
  before using [Submit a plugin](https://marketplace.moodle.com/plugins/submit).
  The form required sign-in when checked. No Marketplace sign-in is recorded in
  connected browser profiles; this does not establish whether the owner has an account.
- **Listing classification:** the quick guide treats provider-paid service tiers
  as paid even if one tier is free. Retaining VideoQ's paid cloud offering needs
  a paid-listing decision. Optional self-hosting must not be assumed to make the
  entire integration eligible for free listing. A self-host-only scope needs
  separate eligibility confirmation and must not silently replace cloud support.
- **Fees and sales:** the published schedule states no listing fee. Sales carry a
  20% promotional platform fee in the first year after Marketplace launch, then
  25%, plus Stripe fees. Paid listings can offer free plugin downloads with paid
  cloud access. Prices, Stripe onboarding and Terms of Sale remain publisher
  decisions; none have been configured or accepted.
- **Reviewer access:** provide service access/instructions sufficient to test the
  plugin, such as an isolated VideoQ account. The cited requirements do not
  mandate a publicly hosted Moodle demo. Send credentials privately through the
  review channel, excluding production data, billing/AI-provider keys and signing keys.
- **External data:** establish the configured deployment's retention and data-request
  process. Moodle requests do not automatically export/delete VideoQ history.
  See [data handling](privacy.md).

## Packages and source publication

| Component | Purpose | Dependency | Submission ZIP |
| --- | --- | --- | --- |
| local_videoq | Course videos and assistant interface | Moodle LTI activity | local_videoq.zip |
| ltisource_videoq | LTI launch integration | local_videoq, declared in version.php | ltisource_videoq.zip |

Confirm component-name availability during submission. Canonical development and
public issues remain at https://github.com/videoq-jp/videoq-moodle.

```sh
php scripts/package-plugins.php /tmp/videoq-submission --marketplace
php scripts/package-translations.php /tmp/videoq-amos
php scripts/export-plugin-sources.php /tmp/videoq-source-exports HEAD
```

Use fresh external directories for source exports. Submission ZIPs have one
`videoq/` root, GPL license, metadata and English strings. The ordinary development
profile retains Japanese; the GitHub repository source ZIP is not an installer.
No Composer, Node.js or Python tooling is distributed or required on Moodle.

The source export creates local Git repositories named `moodle-local_videoq` and
`moodle-ltisource_videoq`, with version.php/classes/lang at their root. Committed
Git blobs are used; dirty/untracked files are excluded. Every exported Git blob is
checked against the ZIP, and `source-exports.json` records provenance and hashes.
Repeated exports reproduce commit/tree/package identities. Symlinks and nonempty
output targets are rejected. No remote is configured or pushed.

The submission guide requires plugin files at the repository root and recommends
`moodle-{plugintype}_{pluginname}` names. The canonical monorepo's default branch
does not meet that layout. Publish the prepared root trees to accepted source
locations before submission; whether publication branches are supported still
requires confirmation in the authenticated workflow. Standalone mirrors are
another option. For existing publication repositories, import the new tree as a
new commit while preserving history. No new GitHub repository has been created.

The AMOS output is a flat 81-string Japanese import: 75 local and 6 LTI-source
strings. It is **prepared, not submitted or accepted**. After component approval,
submit through AMOS and verify actual language-pack delivery; see [translations](translations.md).

ZIP builders normalize UTC, a 1980 timestamp, Unix 0644 permissions and stored
compression. Native PHP, the pinned image and hosted builds reproduce the same
hashes. Historical tagged builders/assets remain unchanged. Earlier reports retain
their original hashes; use current matrix/browser/root reports for current ZIPs.

## Draft listing text

Final commercial/service disclosures must match the selected listing classification.

### local_videoq — VideoQ course video assistant

Short: Ask questions across your Moodle course videos while watching them in
Moodle, with streamed AI answers and video citations.

Full: VideoQ adds a course-wide assistant beside Moodle video playback. Teachers
enable questions for File activities; additions, replacements and deletions
synchronize to a compatible VideoQ server for transcription and indexing. Moodle
controls enrollment and source playback. VideoQ supplies answers, citations and
answer feedback. Start setup in Moodle by entering a VideoQ HTTPS URL, signing in
to the owning account and approving the connection. Install ltisource_videoq too.
Moodle cron, a compatible VideoQ server, an owning account and processing allowance
are required. Hosting/AI processing are not included in the GPL plugin download.

### ltisource_videoq — VideoQ LTI integration

Short: LTI 1.3 launch integration for the VideoQ course video assistant. Requires
local_videoq and a compatible VideoQ server.

Full: Install this companion alongside local_videoq and start setup from Moodle's
Connect to VideoQ administration page. Moodle user, course and resource identifiers
are exchanged with the chosen VideoQ server for access control. The plugin is GPL
software; hosting and AI processing are separate deployment requirements.

Documentation: https://github.com/videoq-jp/videoq-moodle/blob/main/docs/setup.md
Issues: https://github.com/videoq-jp/videoq-moodle/issues
The documentation URL must contain the reviewed changes before publication.

## Verification evidence

| Check | Result | Scope/limit |
| --- | --- | --- |
| Official Moodle coding standard | Locked moodle-cs 3.7.0 / PHPCS 3.13.6: zero errors/warnings | Not the whole plugin-CI suite |
| Official plugin-CI 4.5.11 | Structure/PHPDoc checks passed; applicable local savepoints passed | Companion has no upgrade file; other commands are not implied |
| PostgreSQL / MySQL | Actual old-main install/update, privacy requests, uninstall and current reinstall pass | Synthetic source-byte preservation; no decoding/paid-AI claim |
| Browser installation | Both current ZIPs install through stock Moodle administration and open Connect to VideoQ | Loopback HTTP fixture, alpha warnings and core server recommendations recorded |
| Privacy API | Six callbacks per component preserve shared course/file/sync/settings records | External VideoQ requests remain operator-managed |
| Root source exports | Git blobs match all 39 local / 6 companion files; official structure validation passes | Local repositories only; source URLs not yet published/accepted |
| Package/source contracts | Timezone/mode reproducibility, provenance, unsafe-target and dependency/release checks pass | Historical releases preserved |
| Browser assets | JS compiles in native Chrome; source copyright/license checks pass | Course/chat accessibility and full live-service review remain |

Current reports:

- [DB matrix and hosted CI](evidence/marketplace-database-matrix.json)
- [Browser installation](evidence/marketplace-web-installation.json)
- [Root source exports](evidence/marketplace-root-sources.json)
- [Privacy notes and earlier request evidence](evidence/marketplace-privacy-requests.json)
- [Connection screenshot](evidence/images/marketplace-connection.png)
- [ZIP validation screenshot](evidence/images/marketplace-zip-validation.png)

CI's existing required-check name aggregates tooling and both DB results, failing
if any fails or is skipped. Release publishing depends on that gate. Source/image
pins are in `.github/moodle-matrix.json` and `lab/images/compose.yaml`.

Developer reproductions:

```sh
php scripts/check-moodle-code.php prepare /tmp/videoq-codecheck
php scripts/check-moodle-code.php scan /tmp/videoq-codecheck
php lab/check-marketplace-matrix.php pg /tmp/videoq-matrix-pg
php lab/check-marketplace-matrix.php mysql /tmp/videoq-matrix-mysql
```

Use the pinned PHP image as CI does. Matrix orchestration needs PHP CLI with
ZipArchive/POSIX, Docker, Git and tar; these are development requirements only.
Fresh work directories must be outside the checkout. `MATRIX_DOCKER_SUDO=1` selects
`sudo -n docker`; optional `MATRIX_MOODLE_GIT_SOURCE` / `MATRIX_BASELINE_GIT_SOURCE`
reuse committed objects. Runs have private random-password DBs, internal networks
and no published ports; only owned resources are removed. Share `summary.json`,
not the work directory containing environment files/raw logs.

`lab/check-marketplace-web-install.php <archive-directory>` additionally requires
its explicit owned loopback web fixture and password. Preflight rejects unrelated
or already installed sites. Initial installation avoids an optional manual-setup
prompt and checks real versions/tables/key/defaults. Core HTTP/Composer/security
recommendations are recorded as fixture limits, not described as absent.

The manually used plugin-CI PHAR 4.5.11 was checked against its published SHA256:
`39b5d0d71f5ab88fac1c3f5fc1166b8673bc0a488b29cd8c56a8bca56a8f8bca`.
Automating those subcommands is a maintenance follow-up. The guidelines recommend
plugin-CI; Marketplace's own automated checks and review remain mandatory. No
particular unit-test framework requirement was found in the cited guidelines.

## Remaining publication work

1. Settle publisher identity/classification, sign in, and configure any required
   Stripe/prices/Terms of Sale. Confirm source publication locations and component names.
2. Provide reviewer service access, verify external data-request/retention handling,
   and capture course/chat/citation screenshots with functionality/accessibility evidence.
3. Reconcile documentation overlap with PR #3, finish PR #4, select maturity/support
   truthfully and publish reproducible source/package versions.
4. Submit both components, pass automated checks, address review feedback and publish
   approved pages. Prepare/submit Japanese AMOS contributions after component approval.
5. Verify installation from the actual official listing in a clean Moodle site and
   verify accepted Japanese language-pack delivery.

ZIP creation, GitHub publication, submission and approval alone do not complete
the goal. Published listings and directory-based installation need direct evidence.
