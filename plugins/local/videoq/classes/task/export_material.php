<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
namespace local_videoq\task;
defined('MOODLE_INTERNAL') || die();

/** Large media transfer runs outside launch/API requests. URLs never enter task logs. */
final class export_material extends \core\task\adhoc_task {
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->libdir . '/filelib.php');
        $jobid = $this->get_custom_data()->jobid;
        $lock = \core\lock\lock_config::get_lock_factory('local_videoq')->get_lock('export-job-' . $jobid, 0);
        if (!$lock) return;
        try {
            $job = $DB->get_record('local_videoq_exports', ['jobid' => $jobid], '*', MUST_EXIST);
            if (!in_array($job->status, ['queued', 'uploading'], true)) return;
            $type = \local_videoq\service_auth::type();
            if ((int)$type->id !== (int)$job->typeid || $job->timecreated < time() - 1800) throw new \RuntimeException('Expired export');
            $row = $DB->get_record('local_videoq_sync', ['cmid' => $job->cmid, 'courseid' => $job->courseid], '*', MUST_EXIST);
            $manifest = \local_videoq\synchronizer::run((int)$row->id, true);
            if (!$manifest['enabled'] || $manifest['generation'] !== (int)$job->generation) {
                $DB->update_record('local_videoq_exports', (object)['id' => $job->id, 'status' => 'stale', 'uploadurl' => null, 'timemodified' => time()]);
                return;
            }
            \local_videoq\materials_service::destination($job->uploadurl);
            $file = \local_videoq\synchronizer::video_file((int)$job->cmid);
            if (!$file || $file->get_contenthash() !== $manifest['file']['contentHash']) throw new \RuntimeException('Source changed');
            $DB->set_field('local_videoq_exports', 'status', 'uploading', ['id' => $job->id]);
            $curl = new \curl();
            $curl->setHeader(['Content-Type: ' . $file->get_mimetype(), 'Content-Length: ' . $file->get_filesize(), 'Expect:']);
            $temp = $file->copy_content_to_temp('local_videoq');
            try { $curl->put($job->uploadurl, ['file' => $temp], ['CURLOPT_FOLLOWLOCATION' => 0, 'CURLOPT_TIMEOUT' => 1800, 'CURLOPT_USERPWD' => false]); }
            finally { if (is_file($temp)) unlink($temp); }
            $code = (int)($curl->get_info()['http_code'] ?? 0);
            if ($curl->get_errno() || $code < 200 || $code >= 300) throw new \RuntimeException('Transfer failed');
            $DB->update_record('local_videoq_exports', (object)['id' => $job->id, 'status' => 'uploaded', 'uploadurl' => null,
                'bytes' => (int)$file->get_filesize(), 'timemodified' => time()]);
        } catch (\Throwable $error) {
            // Core logs exception messages for failed ad-hoc tasks. Keep signed URLs out.
            if (isset($job)) $DB->update_record('local_videoq_exports', (object)['id' => $job->id, 'status' => 'failed', 'uploadurl' => null, 'timemodified' => time()]);
            mtrace('VideoQ media export failed; synchronization will retry.');
        } finally { $lock->release(); }
    }
}
