<?php
// Actual archive restore in a fresh Moodle database and independent LTI installation.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
if ($CFG->wwwroot !== 'https://restore.moodle.localhost:18444' || $CFG->dbname !== 'moodle_restore_check' ||
        get_config('local_videoq','servicemode') !== 'lti') throw new RuntimeException('Connected disposable restored site only');
require_once($CFG->dirroot.'/course/lib.php'); \core\session\manager::set_user(get_admin());
$component='local_videoq_cross_check'; $state=json_decode((string)get_config($component,'fixture'),true); $action=$argv[1]??'status';
if ($action==='restore') {
    if ($state) throw new RuntimeException('Cross-site fixture exists');
    require_once($CFG->dirroot.'/backup/util/includes/restore_includes.php');
    $run=bin2hex(random_bytes(12));$directory='videoq-cross-'.$run;$path=make_backup_temp_directory($directory);
    $packer=get_file_packer('application/vnd.moodle.backup');
    if (!$packer->extract_to_pathname('/tmp/videoq-cross-site.mbz',$path)) throw new RuntimeException('Archive extraction failed');
    $courseid=restore_dbops::create_new_course('Cross-site acceptance '.$run,'vq-cross-'.$run,1);
    $state=['run'=>$run,'course'=>(int)$courseid,'typeid'=>(int)get_config('local_videoq','typeid')];
    set_config('fixture',json_encode($state),$component);
    $rc=new restore_controller($directory,$courseid,backup::INTERACTIVE_NO,backup::MODE_GENERAL,$USER->id,backup::TARGET_NEW_COURSE);
    $rc->get_plan()->get_setting('users')->set_value(false);
    if (!$rc->execute_precheck()&&!empty($rc->get_precheck_results()['errors'])) throw new RuntimeException('Cross-site precheck failed');
    $rc->execute_plan();$rc->destroy();fulldelete($path);unlink('/tmp/videoq-cross-site.mbz');
    $rows=$DB->get_records('local_videoq_sync',['courseid'=>$courseid]);
    if (count($rows)!==1) throw new RuntimeException('Unexpected restored opt-ins');
    $source=reset($rows);if(!$source->enabled || (int)$source->typeid!==$state['typeid'])throw new RuntimeException('Destination connection not used');
    $state['source']=(int)$source->cmid;$state['shortname']=get_course($courseid)->shortname;
    set_config('fixture',json_encode($state),$component);
    foreach($DB->get_records('url',['course'=>$courseid]) as $url) {
        if(str_contains($url->externalurl,'https://moodle.localhost:18443'))throw new RuntimeException('Source site link survived');
    }
}
if (!$state) throw new RuntimeException('No cross-site fixture');
if ($action==='delete') {
    $course=$DB->get_record('course',['id'=>$state['course']]);
    if($course){if($course->shortname!==$state['shortname'])throw new RuntimeException('Cross-site course identity changed');delete_course($course->id,false);}
}
if ($action==='cleanup') {
    if($DB->record_exists('course',['id'=>$state['course']]))throw new RuntimeException('Delete cross-site course first');
    $row=$DB->get_record('local_videoq_sync',['courseid'=>$state['course']]);
    if($row&&$row->status!=='disabled')throw new RuntimeException('Wait for cross-site deletion acknowledgement');
    $DB->delete_records('local_videoq_exports',['courseid'=>$state['course']]);$DB->delete_records('local_videoq_sync',['courseid'=>$state['course']]);
    unset_all_config_for_plugin($component);
}
if((int)get_config('local_videoq','typeid')!==$state['typeid'])throw new RuntimeException('Destination connection changed');
echo json_encode($state,JSON_THROW_ON_ERROR);
