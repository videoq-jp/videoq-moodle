/**
 * @package local_videoq
 * @copyright 2026 VideoQ contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * SPDX-License-Identifier: GPL-3.0-or-later.
 */
// All media and playlist access is checked by Moodle and VideoQ on each request.
(() => {
    const init = () => {
        if (!window.require) return;
        window.require(['core/ajax'], Ajax => {
            document.querySelectorAll('[data-videoq-native]').forEach(root => {
                const config = JSON.parse(root.dataset.videoqConfig);
                const player = root.querySelector('#videoq-player');
                const youtube = root.querySelector('[data-videoq-youtube]');
                const file = root.querySelector('[data-videoq-file]');
                const playlist = root.querySelector('[data-videoq-playlist]');
                const status = root.querySelector('[data-videoq-player-status]');
                let videos = [], selected = null, epoch = 0, youtubeUrl = null, pending = false, pendingSeconds, seekSequence = 0;
                function highlight() {
                    playlist.querySelectorAll('button').forEach(button => {
                        const active = Number(button.dataset.videoId) === selected;
                        button.classList.toggle('active', active);
                        button.setAttribute('aria-current', active ? 'true' : 'false');
                    });
                }
                function seek(seconds) {
                    if (!Number.isFinite(seconds) || seconds < 0) return;
                    const sequence = ++seekSequence, seekEpoch = epoch;
                    if (youtubeUrl) {
                        const url = new URL(youtubeUrl);
                        url.searchParams.set('start', String(Math.floor(seconds)));
                        url.searchParams.set('autoplay', '1');
                        youtube.querySelector('iframe').src = url.href;
                    } else {
                        const resume = () => {
                            if (epoch !== seekEpoch || sequence !== seekSequence || pending) return;
                            player.currentTime = Math.min(seconds, player.duration || seconds);
                            // Match VideoQ: a citation seeks and plays; denied autoplay leaves native controls available.
                            void player.play().catch(() => {});
                        };
                        if (player.readyState) resume();
                        else player.addEventListener('loadedmetadata', resume, {once: true});
                    }
                }
                async function select(id, seconds) {
                    if (!videos.some(video => video.videoId === id)) return;
                    root.dispatchEvent(new CustomEvent('videoq:reveal-player'));
                    if (selected === id) {
                        if (pending) { if (Number.isFinite(seconds)) pendingSeconds = seconds; }
                        else seek(seconds);
                        return;
                    }
                    const request = ++epoch;
                    const hasMedia = !!youtubeUrl || player.hasAttribute('src');
                    selected = id; highlight();
                    pending = true; pendingSeconds = seconds;
                    player.pause();
                    // A provider frame cannot be paused through the native file API.
                    // Stop it while the next video's fresh authorization is pending.
                    if (youtubeUrl) { youtube.replaceChildren(); youtubeUrl = null; youtube.hidden = true; }
                    root.querySelector('.local-videoq-main').setAttribute('aria-busy', 'true');
                    status.textContent = hasMedia ? '' : config.copy.connecting || '…';
                    root.dispatchEvent(new CustomEvent('videoq:selection', {detail: {videoId: id}}));
                    try {
                        const result = await Ajax.call([{methodname: 'local_videoq_request_learning', args: {
                            courseid: config.courseid, lesson: '', operation: 'playback', payload: JSON.stringify({videoId: id})
                        }}])[0];
                        if (request !== epoch) return;
                        if (result.status !== 200) throw new Error();
                        const media = JSON.parse(result.body), url = new URL(media.url);
                        if (url.protocol !== 'https:') throw new Error();
                        if (!['youtube', 'file'].includes(media.kind)) throw new Error();
                        player.removeAttribute('src'); player.load(); youtube.replaceChildren(); youtubeUrl = null;
                        file.hidden = youtube.hidden = true;
                        root.querySelector('[data-videoq-video-title]').textContent = media.title || '';
                        root.querySelector('[data-videoq-description]').textContent = media.description || '';
                        if (media.kind === 'youtube') {
                            if (url.origin !== 'https://www.youtube-nocookie.com' || !/^\/embed\/[A-Za-z0-9_-]{11}$/.test(url.pathname)) throw new Error();
                            const frame = document.createElement('iframe');
                            frame.src = youtubeUrl = url.href;
                            frame.title = media.title;
                            frame.allow = 'autoplay; fullscreen; encrypted-media; picture-in-picture';
                            frame.allowFullscreen = true;
                            youtube.append(frame); youtube.hidden = false;
                        } else if (media.kind === 'file') { player.src = url.href; file.hidden = false; }
                        else throw new Error();
                        pending = false;
                        root.querySelector('.local-videoq-main').removeAttribute('aria-busy');
                        status.textContent = ''; seek(pendingSeconds); pendingSeconds = undefined;
                    } catch {
                        if (request !== epoch) return;
                        selected = null; highlight();
                        pending = false; pendingSeconds = undefined;
                        player.removeAttribute('src'); player.load(); youtube.replaceChildren(); youtubeUrl = null;
                        file.hidden = youtube.hidden = true;
                        root.querySelector('.local-videoq-main').removeAttribute('aria-busy');
                        status.textContent = config.copy.connectionError || 'Video unavailable';
                    }
                }
                function update(items) {
                    videos = items;
                    playlist.replaceChildren();
                    videos.forEach((video, index) => {
                        const button = document.createElement('button');
                        button.type = 'button'; button.className = 'list-group-item list-group-item-action';
                        button.dataset.videoId = video.videoId;
                        button.textContent = `${index + 1}. ${video.title}`;
                        button.addEventListener('click', () => { void select(video.videoId); });
                        playlist.append(button);
                    });
                    if (!videos.some(video => video.videoId === selected)) {
                        ++epoch; selected = null; pending = false; pendingSeconds = undefined;
                        root.querySelector('.local-videoq-main').removeAttribute('aria-busy');
                        player.pause(); player.removeAttribute('src'); player.load();
                        youtube.replaceChildren(); youtubeUrl = null; file.hidden = youtube.hidden = true;
                        if (videos.length) void select(videos[0].videoId);
                    }
                    highlight();
                    root.dispatchEvent(new CustomEvent('videoq:selection', {detail: {videoId: selected}}));
                }
                root.addEventListener('videoq:materials', event => update(event.detail.videos));
                root.addEventListener('videoq:seek-citation', event => {
                    void select(event.detail.videoId, event.detail.seconds);
                    root.querySelector('.local-videoq-main').scrollIntoView({block: 'nearest', behavior: 'smooth'});
                });
                root.addEventListener('videoq:read-playback', event => {
                    event.detail.playback = !pending && selected && !youtubeUrl && player.readyState ? {videoId: selected, seconds: player.currentTime, duration: player.duration} : null;
                });
                update(JSON.parse(root.dataset.videoqLessons));
            });
        });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
