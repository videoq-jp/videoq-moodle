/**
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * SPDX-License-Identifier: GPL-3.0-or-later.
 */
// Match the native course viewer, keeping Moodle's components and theme tokens.
(() => {
    const init = () => document.querySelectorAll('.local-videoq-workspace').forEach(root => {
        const viewer = root.closest('.local-videoq-viewer');
        const narrow = window.matchMedia('(max-width: 1023px)');
        const details = root.querySelector('[data-videoq-details]');
        const main = root.querySelector('.local-videoq-main');
        const media = root.querySelector('[data-videoq-media]');
        const toggle = root.querySelector('[data-videoq-player-toggle]');
        const detailsTab = root.querySelector('[data-videoq-side-tab="details"]');
        if (!viewer || !detailsTab) return;
        let side = 'ai', expanded = true;
        function choose(group, name, focus = false) {
            const selected = root.querySelector(`[data-videoq-${group}-tab="${name}"]`);
            if (!selected || selected.hidden) return;
            if (group === 'side') side = name;
            root.querySelectorAll(`[data-videoq-${group}-tab]`).forEach(tab => {
                const active = tab === selected;
                tab.classList.toggle('active', active); tab.setAttribute('aria-selected', String(active));
                tab.setAttribute('aria-pressed', String(active)); tab.tabIndex = active ? 0 : -1;
            });
            root.querySelectorAll(`[data-videoq-${group}-pane]`).forEach(pane => {
                pane.hidden = pane.dataset[`videoq${group[0].toUpperCase() + group.slice(1)}Pane`] !== name;
            });
            if (group === 'main' && name === 'qa') root.querySelector('#videoq-course-qa').open = true;
            if (focus) selected.focus();
        }
        for (const group of ['main','side']) {
            const buttons = root.querySelectorAll(`[data-videoq-${group}-tab]`);
            buttons.forEach((button, index) => {
                button.setAttribute('role','tab');
                button.id = `videoq-${group}-tab-${index}`;
                const name = button.dataset[`videoq${group[0].toUpperCase() + group.slice(1)}Tab`];
                const pane = root.querySelector(`[data-videoq-${group}-pane="${name}"]`);
                pane.id = `videoq-${group}-panel-${index}`; pane.setAttribute('role','tabpanel');
                pane.setAttribute('aria-labelledby',button.id); button.setAttribute('aria-controls',pane.id);
                button.parentElement.setAttribute('role','tablist');
                button.addEventListener('click', () => choose(group,name));
                button.addEventListener('keydown', event => {
                    const available = [...buttons].filter(tab => !tab.hidden), current = available.indexOf(button);
                    const target = {ArrowRight:available[(current + 1) % available.length],ArrowLeft:available[(current - 1 + available.length) % available.length],Home:available[0],End:available.at(-1)}[event.key];
                    if (!target) return;
                    event.preventDefault();choose(group,target.dataset[`videoq${group[0].toUpperCase() + group.slice(1)}Tab`],true);
                });
            });
        }
        function setExpanded(value) {
            expanded = value;
            media.hidden = narrow.matches && !expanded;
            toggle.setAttribute('aria-expanded',String(expanded));
            toggle.textContent = toggle.dataset[expanded ? 'hide' : 'show'];
        }
        function arrange() {
            detailsTab.hidden = !narrow.matches;
            (narrow.matches ? root.querySelector('[data-videoq-side-pane="details"]') : main).append(details);
            if (!narrow.matches && side === 'details') side = 'ai';
            choose('side',side); setExpanded(expanded);
        }
        function fit() { viewer.style.setProperty('--videoq-viewer-height', (window.visualViewport?.height || window.innerHeight) + 'px'); }
        toggle.addEventListener('click', () => setExpanded(!expanded));
        root.addEventListener('focusin', event => { if (event.target.id === 'videoq-native-question' && narrow.matches) setExpanded(false); });
        root.addEventListener('videoq:reveal-player', () => {setExpanded(true);main.scrollTop = 0;});
        narrow.addEventListener('change',arrange);
        window.addEventListener('resize',fit);window.visualViewport?.addEventListener('resize',fit);
        window.addEventListener('pagehide', () => {narrow.removeEventListener('change',arrange);window.removeEventListener('resize',fit);window.visualViewport?.removeEventListener('resize',fit);},{once:true});
        choose('main','overview'); arrange();fit();
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
