<?php
// Local interoperability lab only.
unset($CFG);
global $CFG;
$CFG = new stdClass();
$CFG->dbtype = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost = 'db';
$CFG->dbname = 'moodle';
$CFG->dbuser = 'postgres';
$CFG->dbpass = 'lti-lab-only';
$CFG->prefix = 'mdl_';
$CFG->wwwroot = 'https://moodle.localhost:18443';
$CFG->dataroot = '/var/www/moodledata';
$CFG->admin = 'admin';
$CFG->directorypermissions = 0777;
$CFG->sslproxy = true;
$CFG->site_is_public = false;
$CFG->passwordpolicy = false;
$CFG->pathtophp = '/usr/local/bin/php';
// Permit the isolated lab's private-network TLS proxy for LTI requests.
// Keep an IP rule enabled so Moodle pins Docker DNS results with CURLOPT_RESOLVE;
// otherwise modern libcurl resolves every *.localhost name to its own loopback.
$CFG->curlsecurityblockedhosts = '0.0.0.0';
$CFG->curlsecurityallowedport = '18443';
require_once(__DIR__ . '/lib/setup.php');
