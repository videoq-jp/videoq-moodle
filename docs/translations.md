# Marketplace packages and Japanese translation

Official guidance: [plugin translations](https://docs.moodle.org/en/Translating_plugins)
and [AMOS import](https://docs.moodle.org/en/AMOS_manual#Importing_a_file).

## Build separate artifacts

```sh
php scripts/package-plugins.php /tmp/videoq-submission --marketplace
php scripts/package-translations.php /tmp/videoq-amos
```

The submission directory contains the two standard component ZIPs and their
SHA256SUMS, with English-only `lang/en/` contents. All other runtime files match
the development installer. The ordinary builder invocation still includes the
existing Japanese strings for local development. Historical GitHub prereleases
are unchanged; a new official release must use the submission profile after the
remaining review gates. No release or Marketplace upload is performed here.

The translation output contains `amos-ja.zip` and `amos-ja.json`. The ZIP contains
flat files named `local_videoq.php` (75 strings) and `ltisource_videoq.php` (6
strings). It is an AMOS import, not a plugin installer or installable Moodle
language pack. The exporter checks data-only, single-quoted definitions, duplicate
identifiers, exact English/Japanese key coverage and matching Moodle placeholders.

## Contribute after the components become available in AMOS

1. Sign in to https://lang.moodle.org/ and select Japanese and the supported
   Moodle 5.1 branch. Confirm both component identifiers are available; do not
   import against another plugin or an unrelated Moodle branch.
2. Open the staging area and import the two PHP files or the flat import ZIP.
   Inspect all strings and placeholders in the preview.
3. Submit the staged contribution to the Japanese language-pack maintainers,
   unless the account already has commit permission. Record the contribution
   identifiers and wait for acceptance; generating this file does not publish it.
4. After the accepted strings appear in the published Japanese language pack,
   update that pack through Moodle's standard Language packs page and verify the
   connection, assistant and privacy screens in Japanese.

Existing local Japanese strings remain the source for local_videoq;
`translations/ja/ltisource_videoq.php` supplies the companion's translation draft.
Both must stay in sync with the English component files. No translation has been
submitted or approved in AMOS yet. Publisher/translator account access and the
actual contribution/verification remain required.

Plugin and AMOS ZIP builders normalize UTC timestamps, Unix permissions and stored
compression so source permissions and process timezones do not change checksums.
Use the pinned PHP image for release/submission builds. Historical tagged assets
are preserved; earlier candidate hashes may predate this normalization.
