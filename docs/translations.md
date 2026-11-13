# Japanese translations

The active component is `local_videoq` only. English strings are in `plugins/local/videoq/lang/en/local_videoq.php`; the ordinary GitHub ZIP includes the reviewed Japanese strings in `lang/ja`.

Official Marketplace installers omit non-English strings and use Moodle language packs. Run `php scripts/package-translations.php OUTPUT_DIRECTORY` to prepare a flat `local_videoq.php` AMOS import ZIP and its provenance metadata. This is a translation submission artifact, not a second Moodle plugin installer. Preparation does not submit or approve the translation.

The tests validate string identifiers, placeholders, data-only PHP contents and reproducible ZIPs. Translation tooling no longer exports the retired LTI helper.
