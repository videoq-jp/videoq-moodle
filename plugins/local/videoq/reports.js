// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.
// SPDX-License-Identifier: GPL-3.0-or-later.

/**
 * Opens course usage reports in the existing private tool frame.
 *
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function() {
    'use strict';
    function init() {
        const workspace = document.querySelector('.local-videoq-workspace[data-videoq-origin]');
        const root = document.getElementById('videoq-course-reports');
        if (!workspace || !root) return;
        const frame = workspace.querySelector('#videoq-questions');
        const panel = workspace.querySelector('.local-videoq-questions');
        const origin = workspace.dataset.videoqOrigin;
        const details = root.querySelector('details');
        const status = root.querySelector('[role="status"]');
        const initialTitle = frame.dataset.videoqBaseTitle || (frame.dataset.videoqBaseTitle = frame.title);
        let bridge = null, available = false, draft = false, opened = false, everOpened = false;
        function display(open, notify = true) {
            if (open) workspace.dispatchEvent(new CustomEvent('videoq:tool-panel', { detail: 'reports' }));
            const changed = opened !== open;
            opened = open;
            panel.classList.toggle('local-videoq-reports-open', open);
            details.open = open;
            if (open || workspace.dataset.videoqActiveTool === 'reports') {
                frame.title = open ? root.dataset.heading : initialTitle;
                frame.setAttribute('tabindex', open ? '0' : '-1');
                if (open) { frame.removeAttribute('aria-hidden'); workspace.dataset.videoqActiveTool = 'reports'; }
                else { frame.setAttribute('aria-hidden', 'true'); delete workspace.dataset.videoqActiveTool; }
            }
            if (changed && notify && bridge) frame.contentWindow.postMessage({ type: 'videoq:reports-ui', version: 1,
                channel: bridge.channel, videoId: bridge.videoId, open }, origin);
        }
        details.addEventListener('toggle', () => { if (details.open) everOpened = true; display(details.open && (available || draft)); });
        workspace.addEventListener('videoq:tool-panel', event => { if (event.detail !== 'reports' && opened) display(false); });
        window.addEventListener('message', event => {
            if (event.source !== frame.contentWindow || event.origin !== origin) return;
            const data = event.data;
            if (!data || data.version !== 1) return;
            if (data.type === 'videoq:ready' && typeof data.channel === 'string' && /^[a-f0-9]{32}$/.test(data.channel)
                    && Number.isSafeInteger(data.videoId) && data.videoId > 0) {
                if (bridge?.channel === data.channel) return;
                display(false, false); available = false; draft = false; root.hidden = true; status.textContent = '';
                bridge = data.features?.reports === true ? data : null;
                return;
            }
            if (bridge && data.type === 'videoq:reports-close' && data.channel === bridge.channel && data.videoId === bridge.videoId) {
                display(false);
                workspace.querySelector('#videoq-native-question')?.focus();
                return;
            }
            if (!bridge || data.type !== 'videoq:reports-availability' || data.channel !== bridge.channel || data.videoId !== bridge.videoId
                    || typeof data.available !== 'boolean') return;
            available = data.available;
            draft = data.draft === true;
            root.hidden = !available && !everOpened;
            details.hidden = !available && !draft;
            status.textContent = !available && everOpened ? root.dataset.unavailable : '';
            if (!available && !draft) display(false);
        });
        frame.contentWindow?.postMessage({ type: 'videoq:hello', version: 1 }, origin);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
