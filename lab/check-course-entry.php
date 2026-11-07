<?php
/** Real installed-Moodle entry/proof lifecycle; no backend, media or AI fixtures. */
declare(strict_types=1);
define('CLI_SCRIPT',true);
if (($argv[1] ?? '') !== '/site') throw new RuntimeException('Use the isolated /site fixture mount');
require '/site/config.php';
if ($CFG->wwwroot !== 'https://marketplace-audit.invalid' || $CFG->dbhost !== 'audit-db' || $CFG->dbname !== 'moodleaudit_upgrade') {
    throw new RuntimeException('Refusing to mutate an unrelated Moodle database');
}
require_once $CFG->dirroot.'/course/lib.php';
require_once $CFG->dirroot.'/course/modlib.php';
require_once $CFG->dirroot.'/user/lib.php';
require_once $CFG->dirroot.'/mod/lti/locallib.php';
$CFG->noemailever = true;
\core\session\manager::set_user(get_admin());
$checks=[];
$check=static function(bool $value,string $label) use (&$checks): void {
    if (!$value) throw new RuntimeException('Course entry check failed: '.$label);
    $checks[]=$label;
};
$rejects=static function(callable $operation,string $label) use ($check): void {
    $denied=false; try {$operation();} catch (Throwable) {$denied=true;}
    $check($denied,$label);
};
$saved=[];
foreach (['typeid','apiurl','servicemode','lessons'] as $key) $saved[$key]=get_config('local_videoq',$key);
$recycle=get_config('tool_recyclebin','coursebinenable');
$courseid=0;
try {
    $suffix=bin2hex(random_bytes(6));
    $course=create_course((object)['fullname'=>'VideoQ empty entry '.$suffix,'shortname'=>'VIDEOQ-EMPTY-'.$suffix,'category'=>1]);
    $courseid=(int)$course->id;
    $context=context_course::instance($courseid);
    $users=[];
    foreach (['student','editingteacher'] as $archetype) {
        $role=$DB->get_record('role',['archetype'=>$archetype],'*',MUST_EXIST);
        $userid=user_create_user((object)['username'=>'videoq-empty-'.$archetype.'-'.$suffix,'firstname'=>'Synthetic','lastname'=>$archetype,
            'email'=>$archetype.'@example.invalid','password'=>'Only-fixture-123!','auth'=>'manual','confirmed'=>1]);
        $manual=enrol_get_plugin('manual');
        $enrol=$DB->get_record('enrol',['courseid'=>$courseid,'enrol'=>'manual']);
        if (!$enrol) $enrol=$DB->get_record('enrol',['id'=>$manual->add_default_instance($course)],'*',MUST_EXIST);
        $enrol->customint1=ENROL_DO_NOT_SEND_EMAIL;
        $DB->set_field('enrol','customint1',ENROL_DO_NOT_SEND_EMAIL,['id'=>$enrol->id]);
        $manual->enrol_user($enrol,$userid,$role->id);
        $users[$archetype]=$DB->get_record('user',['id'=>$userid],'*',MUST_EXIST);
        $roles[$archetype]=(int)$role->id;
    }
    $typeid=lti_add_type((object)['state'=>LTI_TOOL_STATE_CONFIGURED],(object)[
        'lti_typename'=>'Synthetic course entry '.$suffix,'lti_toolurl'=>'https://videoq.example.invalid/api/lti/launch',
        'lti_ltiversion'=>LTI_VERSION_1P3,'lti_coursevisible'=>1,'lti_contentitem'=>0,
        'lti_initiatelogin'=>'https://videoq.example.invalid/api/lti/login',
        'lti_redirectionuris'=>'https://videoq.example.invalid/api/lti/launch','lti_launchcontainer'=>LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS,
    ]);
    set_config('typeid',$typeid,'local_videoq'); set_config('apiurl','https://videoq.example.invalid','local_videoq');
    set_config('servicemode','lti','local_videoq');
    $activity=add_moduleinfo((object)[
        'modulename'=>'lti','module'=>$DB->get_field('modules','id',['name'=>'lti']),'course'=>$courseid,'section'=>0,'visible'=>1,
        'intro'=>'','introformat'=>FORMAT_HTML,'name'=>'Synthetic course entry','typeid'=>$typeid,
        'toolurl'=>'https://videoq.example.invalid/api/lti/launch','launchcontainer'=>LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS,
        'showtitlelaunch'=>0,'showdescriptionlaunch'=>0,'instructorchoicesendname'=>0,'instructorchoicesendemailaddr'=>0,'instructorchoiceacceptgrades'=>0,
    ],$course);
    $entryid=$DB->insert_record('local_videoq_courses',(object)['courseid'=>$courseid,'typeid'=>$typeid,'lticmid'=>$activity->coursemodule,
        'ltiinstance'=>$activity->instance,'enabled'=>1,'generation'=>1,'fingerprint'=>'','status'=>'queued','errorcode'=>'',
        'timemodified'=>time(),'lastsynced'=>0]);
    $manifest=\local_videoq\course_entry::run($courseid,true);
    $check($manifest['kind']==='course' && $manifest['generation']===1 && $manifest['enabled'] && !isset($manifest['file']),'media-free manifest');
    $check(!$DB->record_exists('resource',['course'=>$courseid]),'no dummy video activity');
    $makeproof=static fn()=>\local_videoq\course_access::launch((int)$activity->instance,(int)$users['student']->id)['custom_videoq_access'];
    $proof=$makeproof();
    $resolve=static fn($token=null)=>\local_videoq\course_access::resolve($token??$proof,(string)$users['student']->id,(string)$courseid,(string)$activity->instance);
    $access=$resolve();
    $check($access['resources']===[] && $access['courseEntry']['generation']===1 && $access['permissions']['viewQa']
        && $access['permissions']['manageOwnHistory'] && !$access['permissions']['manageContent'],'fresh empty-scope learner permissions');
    $DB->set_field('lti_types','state',LTI_TOOL_STATE_REJECTED,['id'=>$typeid]);
    $rejects(fn()=>$resolve(),'tool-wide revocation rejects issued proofs');
    $check(\local_videoq\course_access::launch((int)$activity->instance,(int)$users['student']->id)===[],'rejected tool cannot mint an entry proof');
    $rejects(fn()=>\local_videoq\course_entry::configure($courseid,true),'rejected tool cannot configure an entry');
    $DB->set_field('lti_types','state',LTI_TOOL_STATE_CONFIGURED,['id'=>$typeid]);
    $check($resolve()['resources']===[],'tool-wide permission restoration');
    $rejects(fn()=>\local_videoq\materials_service::manifest($courseid,$typeid),'old caller cannot receive course manifests');
    $snapshot=\local_videoq\materials_service::manifest($courseid,$typeid,true);
    $check(count($snapshot['resources'])===1 && $snapshot['resources'][0]['externalId']==='course_'.$courseid,'opted-in service manifest');
    $service=\local_videoq\materials_service::access($courseid,$typeid,(string)$activity->instance,(string)$users['student']->id,true);
    $check($service['resources']===[] && $service['courseEntry']['generation']===1,'authenticated service empty scope');
    $rejects(fn()=>\local_videoq\materials_service::access($courseid,$typeid,(string)$activity->instance,(string)$users['student']->id),'old service access denied');
    $ack=['includeCourseEntries'=>true,'externalId'=>'course_'.$courseid,'generation'=>1,'status'=>'completed'];
    $check(\local_videoq\materials_service::acknowledge($courseid,$typeid,$ack)===['acknowledged'=>true],'course acknowledgement');
    $rejects(fn()=>\local_videoq\materials_service::acknowledge($courseid,$typeid,array_replace($ack,['generation'=>2])),'stale acknowledgement denied');
    \core\session\manager::set_user($users['student']);
    $native=\local_videoq\native_context::resolve($courseid);
    $check($native['subject']===(string)$users['student']->id && $native['resourceLinkId']===(string)$activity->instance
        && $native['scope']['resources']===[] && $native['scope']['permissions']===$access['permissions'],
        'native empty-course actor and current authority');
    // Use Moodle's actual HTML renderer/helpers as well as its template files.
    $PAGE->set_url('/local/videoq/view.php',['course'=>$courseid]);
    $PAGE->set_context($context);
    $renderer=$PAGE->get_renderer('core',null,RENDERER_TARGET_GENERAL);
    foreach (['empty'=>[], 'file'=>[['kind'=>'file','lesson'=>'fixture_source','title'=>'Synthetic material',
            'url'=>'https://marketplace-audit.invalid/mod/resource/view.php?id=1','mime'=>'video/mp4',
            'activityUrl'=>'https://marketplace-audit.invalid/mod/resource/view.php?id=1']]] as $layout=>$catalog) {
        $workspace=new \local_videoq\output\workspace($courseid,$catalog,$catalog?'fixture_source':'',[],true);
        $html=$renderer->render_from_template('local_videoq/workspace',$workspace->export_for_template($renderer));
        $check(str_contains($html,'data-videoq-native="1"') && str_contains($html,'id="videoq-native-form"')
            && !str_contains($html,'<iframe'),$layout.' native workspace through actual Moodle HTML renderer');
    }
    $rejects(fn()=>\local_videoq\course_entry::require_manager($courseid),'learner cannot configure entry');
    \core\session\manager::set_user($users['editingteacher']);
    \local_videoq\course_entry::require_manager($courseid);
    $check(true,'editing teacher course authority');
    assign_capability('local/videoq:viewqa',CAP_PREVENT,$roles['student'],$context->id,true);
    accesslib_clear_all_caches(true);
    $check(!$resolve()['permissions']['viewQa'],'live Q&A capability revocation');
    unassign_capability('local/videoq:viewqa',$roles['student'],$context->id); accesslib_clear_all_caches(true);
    $DB->set_field('user','suspended',1,['id'=>$users['student']->id]);
    $rejects(fn()=>$resolve(),'account suspension');
    $DB->set_field('user','suspended',0,['id'=>$users['student']->id]);
    $enrolment=$DB->get_record('user_enrolments',['enrolid'=>$enrol->id,'userid'=>$users['student']->id],'*',MUST_EXIST);
    $DB->set_field('user_enrolments','status',1,['id'=>$enrolment->id]); accesslib_clear_all_caches(true);
    $rejects(fn()=>$resolve(),'enrolment suspension');
    $DB->set_field('user_enrolments','status',0,['id'=>$enrolment->id]); accesslib_clear_all_caches(true);
    $payload=json_decode(hex2bin(explode('.',$proof)[0]),true); unset($payload['entryGeneration']);
    $encoded=bin2hex(json_encode($payload));
    $unsignedentry=$encoded.'.'.hash_hmac('sha256',$encoded,hex2bin((string)get_config('local_videoq','accesssigningkey')));
    $rejects(fn()=>$resolve($unsignedentry),'known entry cannot use a source-style proof');
    set_coursemodule_visible((int)$activity->coursemodule,0);
    $rejects(fn()=>$resolve(),'hidden entry revokes old proof');
    $hidden=\local_videoq\course_entry::run($courseid,true);
    $check($hidden['enabled'] && !$hidden['deleted'],'hidden state is not a global disable');
    $rejects(fn()=>$resolve($makeproof()),'hidden entry denied to learner with a fresh proof');
    set_coursemodule_visible((int)$activity->coursemodule,1);
    \local_videoq\course_entry::run($courseid,true);
    $fresh=$makeproof(); $check($resolve($fresh)['resources']===[],'visibility restoration');
    \local_videoq\course_entry::configure($courseid,false);
    $rejects(fn()=>$resolve($fresh),'explicit disable revokes an existing proof');
    $check(\local_videoq\course_access::launch((int)$activity->instance,(int)$users['student']->id)===[],'disabled entry cannot mint a proof');
    // A separate legacy File/LTI association must not authorize this entry.
    // This is a proof-association probe, not a processed video/AI fixture.
    $other=add_moduleinfo((object)[
        'modulename'=>'lti','module'=>$DB->get_field('modules','id',['name'=>'lti']),'course'=>$courseid,'section'=>0,'visible'=>1,
        'intro'=>'','introformat'=>FORMAT_HTML,'name'=>'Independent source transport','typeid'=>$typeid,
        'toolurl'=>'https://videoq.example.invalid/api/lti/launch','launchcontainer'=>LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS,
        'showtitlelaunch'=>0,'showdescriptionlaunch'=>0,'instructorchoicesendname'=>0,'instructorchoicesendemailaddr'=>0,
    ],$course);
    $source=add_moduleinfo((object)[
        'modulename'=>'resource','module'=>$DB->get_field('modules','id',['name'=>'resource']),'course'=>$courseid,'section'=>0,'visible'=>1,
        'name'=>'Independent source proof probe','intro'=>'','introformat'=>FORMAT_HTML,'files'=>0,'display'=>0,
        'printintro'=>0,'showsize'=>0,'showtype'=>0,'showdate'=>0,
    ],$course);
    $DB->insert_record('local_videoq_sync',(object)['courseid'=>$courseid,'typeid'=>$typeid,'cmid'=>$source->coursemodule,
        'lticmid'=>$other->coursemodule,'ltiinstance'=>$other->instance,'linkcmid'=>0,'enabled'=>1,'generation'=>1,
        'fingerprint'=>'','status'=>'pending','errorcode'=>'','timemodified'=>time(),'lastsynced'=>0]);
    $check(\local_videoq\course_access::launch((int)$other->instance,(int)$users['student']->id)!==[],'independent source positive control');
    $check(\local_videoq\course_access::launch((int)$activity->instance,(int)$users['student']->id)===[],'disabled entry cannot borrow source authority');
    $DB->set_field('local_videoq_courses','ltiinstance',(int)$other->instance,['id'=>$entryid]);
    $check(\local_videoq\course_access::launch((int)$activity->instance,(int)$users['student']->id)===[],'retired live activity cannot borrow source authority');
    $rejects(fn()=>$resolve($fresh),'retired entry proof denied with another source present');
    $DB->set_field('local_videoq_courses','ltiinstance',(int)$activity->instance,['id'=>$entryid]);
    $disabled=\local_videoq\course_entry::run($courseid,true);
    $check(!$disabled['enabled'] && $DB->record_exists('course',['id'=>$courseid]),'disable retains shared course');
    $DB->set_field('local_videoq_courses','enabled',1,['id'=>$entryid]);
    $DB->execute('UPDATE {local_videoq_courses} SET generation = generation + 1 WHERE id = ?',[$entryid]);
    $fresh=$makeproof();
    set_config('coursebinenable',0,'tool_recyclebin');
    course_delete_module((int)$activity->coursemodule,false);
    $rejects(fn()=>$resolve($fresh),'deleted entry revokes proof');
    $deleted=\local_videoq\course_entry::run($courseid,true);
    $check(!$deleted['enabled'] && $deleted['deleted'] && !isset($deleted['file']),'real module deletion emits a tombstone');
    $DB->delete_records('local_videoq_courses',['id'=>$entryid]);
    $check(\local_videoq\course_access::launch((int)$activity->instance,(int)$users['student']->id)===[],'retired activity cannot mint a proof');
    echo json_encode(['passed'=>true,'database'=>$CFG->dbtype,'checks'=>$checks,
        'limits'=>['Real core activities and permissions; backend discovery/setup and browser launch are verified separately.']],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
} finally {
    \core\session\manager::set_user(get_admin());
    if ($courseid) {
        $DB->delete_records('local_videoq_courses',['courseid'=>$courseid]);
        $DB->delete_records('local_videoq_sync',['courseid'=>$courseid]);
    }
    foreach ($saved as $key=>$value) {
        if ($value===false) unset_config($key,'local_videoq'); else set_config($key,$value,'local_videoq');
    }
    if ($recycle===false) unset_config('coursebinenable','tool_recyclebin'); else set_config('coursebinenable',$recycle,'tool_recyclebin');
}
