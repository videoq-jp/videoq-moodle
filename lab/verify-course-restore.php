<?php
declare(strict_types=1);
require __DIR__ . '/support/runtime.php';
use function VideoQLab\{guard, rows, docker, container, ensure, report};
guard();
$before = rows('SELECT id,file,status FROM videos ORDER BY id');
$merge = in_array('--merge', $argv, true);
$output = docker(['exec', container(), 'php', '/lab/course-restore-fixture.php', ...($merge ? ['--merge'] : [])]);
// Moodle backup progress may precede the final machine-readable result.
$offset = strrpos($output, '{"passed":');
ensure($offset !== false, 'No course restore result');
$result = json_decode(substr($output, $offset), true, 32, JSON_THROW_ON_ERROR);
ensure(($result['passed'] ?? false) === true, 'Course restore failed');
ensure(rows('SELECT id,file,status FROM videos ORDER BY id') === $before, 'Existing videos changed');
report($merge ? 'php-course-merge' : 'php-course-restore', $result + ['existingVideosPreserved' => true, 'mergeExistingCourse' => $merge]);
