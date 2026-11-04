<?php
// SPDX-License-Identifier: GPL-3.0-or-later.
namespace local_videoq\task;
defined('MOODLE_INTERNAL') || die();
final class synchronize extends \core\task\scheduled_task {
    public function get_name() { return get_string('synchronize', 'local_videoq'); }
    public function execute() {
        global $DB;
        if (get_config('local_videoq', 'servicemode') === 'lti') {
            $DB->execute('UPDATE {local_videoq_exports} SET status = ?, uploadurl = NULL, timemodified = ? WHERE timecreated < ? AND status IN (?, ?)',
                ['failed', time(), time() - 3600, 'queued', 'uploading']);
            $DB->delete_records_select('local_videoq_exports', 'timemodified < ? AND status IN (?, ?, ?)', [time() - 86400, 'uploaded', 'failed', 'stale']);
            $DB->delete_records_select('local_videoq_assertions', 'expires < ?', [time() - 120]);
            return;
        }
        if (!get_config('local_videoq', 'apiurl') || !get_config('local_videoq', 'synctoken')) return;
        $rows = $DB->get_records_select('local_videoq_sync', 'enabled = 1 OR status <> ?', ['disabled'], 'lastsynced ASC, id ASC');
        foreach ($rows as $row) {
            try { \local_videoq\synchronizer::run((int)$row->id); }
            catch (\Throwable $error) {
                // No HTTP body, provider diagnostic, URL signature or credential
                // is written to Moodle logs or teacher-visible status.
                $DB->set_field('local_videoq_sync', 'status', 'error', ['id' => $row->id]);
                $DB->set_field('local_videoq_sync', 'errorcode', 'SYNC_FAILED', ['id' => $row->id]);
                mtrace('VideoQ synchronization failed for activity ' . $row->cmid . '; will retry.');
            }
        }
    }
}
