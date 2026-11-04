<?php
// CLI-only negative test control for the isolated Moodle lab.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://moodle.localhost:18443' || $CFG->dbhost !== 'db') {
    throw new Exception('Use only the isolated LTI lab Moodle');
}
require_once($CFG->dirroot . '/course/lib.php');
$lesson = \local_videoq\lesson::find('lms-videoq');
$key = match ($argv[1] ?? '') { 'video' => 'videoCmid', 'lti' => 'ltiCmid', default => throw new Exception('Expected video or lti') };
if (!in_array($argv[2] ?? '', ['0', '1'], true)) throw new Exception('Expected 0 or 1');
set_coursemodule_visible($lesson[$key], (int)$argv[2]);
echo "Updated lab activity visibility\n";
