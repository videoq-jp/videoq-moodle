# Repository extraction

Source: https://github.com/yukiharada1228/videoq

Imported from the working tree on 2026-11-04 (VideoQ base commit
`596eef52dfbbc518760111113da6f1e6fb879358`). The Moodle integration was still
uncommitted in that working tree, so it has no prior per-file Git history to
preserve. The new repository starts with an explicit import commit.

| Former VideoQ path | New path |
|---|---|
| `integrations/moodle/local/videoq` | `plugins/local/videoq` |
| `integrations/moodle/ltisource/videoq` | `plugins/ltisource/videoq` |
| `scripts/lti/moodle` | `lab` |
| `scripts/lti/moodle/package-plugins.mjs` | `scripts/package-plugins.mjs` |
| Full Moodle deployment instructions | `docs/setup.md`, `docs/setup.ja.md` |

LTI and synchronization API handlers, registration/credential storage, migrations,
account-owned connection controls, AI/RAG, storage and worker code remain in
VideoQ. At extraction these corresponding API changes were also uncommitted;
the base commit alone does not contain them. A compatible VideoQ build/deployment
is required until they are published by the application repository. Moodle plugins
depend on their HTTPS contracts, not source imports.
The lab is an optional integration development dependency on a separately built
VideoQ checkout. It does not create a production dependency on this repository.

No runtime credentials, uploaded videos, database snapshots, provider settings or
private acceptance artifacts were imported. Public lab fixtures remain confined
to the loopback-only Compose environment. Existing licensing is preserved.
