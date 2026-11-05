# Repository operations

The target home is `videoq-jp/videoq-moodle`. Transfer the existing repository,
rather than creating a copy, to preserve its history, releases and pull requests.
Update local remotes after transfer. GitHub redirects existing repository URLs;
historical evidence links can remain intact.

## Dependency updates and CI

Dependabot checks `lab/images/compose.yaml` every Monday at 09:00 JST. It opens
grouped image-update PRs; PHP stays on 8.3 and major image upgrades need a separate
compatibility change. This single manifest supplies both labs and CI, including
the restored-site lab. Every image is pinned by digest. No Composer packages or
third-party GitHub actions are installed, so empty Composer/action update jobs
are unnecessary. The existing PHP-only tooling requirement is retained.

CI checks PRs, main, tags, merge groups and a weekly schedule. Installer, syntax,
PHP contracts, native browser assets and both resolved Compose configurations
must pass. Verification is cancellable when superseded; publishing is serialized
and reconciles existing immutable release assets. Older tags without the image
manifest use the workflow revision's validated image for release recovery.

Dependabot version updates do not constitute PHP static analysis or container
vulnerability scanning. CodeQL does not cover PHP; no unsupported PHP CodeQL job
or application-specific cloud deployment is copied from VideoQ.

## GitHub settings

Enable Dependabot alerts/security updates, secret scanning/push protection and
private vulnerability reporting. `SECURITY.md` describes private reports.
Dependabot alerts apply only to supported dependency-graph coverage; they are
not a substitute for checking the selected container images or Moodle releases.

The main ruleset in `.github/main-ruleset.json` requires a PR and the actual
GitHub Actions check, blocks force pushes/deletion and has no bypass actors.
It permits a single maintainer to work without requiring another reviewer.
Apply it in repository settings or the GitHub REST API after checking that the
named CI job exists. Keep squash merges, delete merged branches and retain
human approval of dependency changes. No bot private key is required.

## Transfer verification

Check the new owner, visibility, administrator access, open PR numbers, default
branch SHA, ruleset and security settings. There are currently no repository
secrets, variables, webhooks or Pages deployment to migrate. Recheck these before
an actual transfer rather than assuming that inventory stays empty.

Use the existing tag workflow to publish verified installers. Repository transfer
does not deploy VideoQ or activate the unmerged Moodle connection feature.
