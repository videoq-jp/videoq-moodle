<?php
// Separate database, files and identity; never reuses the source site's bindings.
unset($CFG); global $CFG; $CFG = new stdClass();
$CFG->dbtype = 'pgsql'; $CFG->dblibrary = 'native'; $CFG->dbhost = 'db';
$CFG->dbname = 'moodle_restore_check'; $CFG->dbuser = 'postgres'; $CFG->dbpass = 'lti-lab-only';
$CFG->prefix = 'mdl_'; $CFG->wwwroot = 'https://restore.moodle.localhost:18444';
$CFG->dataroot = '/var/www/moodledata'; $CFG->admin = 'admin';
$CFG->directorypermissions = 0777; $CFG->sslproxy = true; $CFG->site_is_public = false;
$CFG->passwordpolicy = false; $CFG->noemailever = true; $CFG->pathtophp = '/usr/local/bin/php';
$CFG->curlsecurityblockedhosts = '0.0.0.0'; $CFG->curlsecurityallowedport = '18443';
require_once(__DIR__ . '/lib/setup.php');
