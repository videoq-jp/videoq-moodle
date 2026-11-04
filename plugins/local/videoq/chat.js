// Native core_message templates and theme; the LTI frame keeps authentication and API access private.
(function() {
    'use strict';
    function init() {
        const workspace = document.querySelector('.local-videoq-workspace[data-videoq-origin]');
        const root = document.getElementById('videoq-native-chat');
        if (!workspace || !root) return;
        const frame = workspace.querySelector('#videoq-questions');
        const origin = workspace.dataset.videoqOrigin;
        const form = root.querySelector('form');
        const controls = root.querySelector('fieldset');
        const input = root.querySelector('[data-region="send-message-txt"]');
        const send = root.querySelector('[data-action="send-message"]');
        const status = root.querySelector('[role="status"]');
        const messages = root.querySelector('[role="log"]');
        const empty = root.querySelector('#videoq-native-empty');
        input.id = 'videoq-native-question';
        send.id = 'videoq-native-send';
        let bridge = null;
        let connected = false;
        let pending = null;
        const shownQuestions = new Set();
        const shownAnswers = new Set();
        const answerBodies = new Map();
        const ratings = new Map();
        const initialTimer = window.setTimeout(() => {
            if (!connected) {
                // Older/course-only LTI tools can keep their existing visible interface.
                root.hidden = true;
                frame.classList.remove('local-videoq-transport');
                frame.removeAttribute('aria-hidden'); frame.removeAttribute('tabindex');
            }
        }, 15000);
        function post(type, payload) {
            if (!bridge) return;
            frame.contentWindow.postMessage({ type, version: 1, channel: bridge.channel, videoId: bridge.videoId, ...payload }, origin);
        }
        function scrollLatest() { requestAnimationFrame(() => { messages.scrollTop = messages.scrollHeight; }); }
        const resize = new ResizeObserver(scrollLatest);
        resize.observe(messages);
        function setBusy(busy) {
            controls.disabled = busy;
            messages.setAttribute('aria-busy', String(busy));
            send.querySelector('[data-region="send-icon-container"]').classList.toggle('hidden', busy);
            send.querySelector('[data-region="loading-icon-container"]').classList.toggle('hidden', !busy);
        }
        function inline(parent, text) {
            for (const part of text.split(/(\*\*[^*\n]+\*\*|`[^`\n]+`|\n)/g)) {
                if (part === '\n') parent.append(document.createElement('br'));
                else if (part.startsWith('**') && part.endsWith('**') && part.length > 4) {
                    const strong = document.createElement('strong'); strong.textContent = part.slice(2, -2); parent.append(strong);
                } else if (part.startsWith('`') && part.endsWith('`') && part.length > 2) {
                    const code = document.createElement('code'); code.textContent = part.slice(1, -1); parent.append(code);
                } else parent.append(document.createTextNode(part));
            }
        }
        function richText(parent, text) {
            for (const block of text.split(/\n{2,}/)) {
                const lines = block.split('\n');
                if (lines.every(line => /^\s*[-*]\s+/.test(line))) {
                    const list = document.createElement('ul');
                    for (const line of lines) { const item = document.createElement('li'); inline(item, line.replace(/^\s*[-*]\s+/, '')); list.append(item); }
                    parent.append(list);
                } else {
                    const heading = /^#{1,3} /.test(block) && lines.length === 1;
                    const paragraph = document.createElement(heading ? 'h5' : 'p');
                    inline(paragraph, heading ? block.replace(/^#{1,3} /, '') : block);
                    parent.append(paragraph);
                }
            }
        }
        function message(kind) {
            empty.hidden = true;
            const template = root.querySelector('#videoq-native-' + kind + '-template');
            const element = template.content.firstElementChild.cloneNode(true);
            // Core's bulk-delete selection is for human messages; AI answers are read-only here.
            element.classList.remove('clickable');
            element.classList.add('flex-shrink-0', kind);
            element.removeAttribute('role'); element.removeAttribute('aria-checked'); element.removeAttribute('tabindex');
            element.dataset.messageId = 'videoq-' + crypto.randomUUID();
            element.querySelector('[data-region="time-created"]').textContent = new Intl.DateTimeFormat(document.documentElement.lang, { hour: '2-digit', minute: '2-digit' }).format(new Date());
            messages.append(element);
            return element.querySelector('[data-region="text-container"]');
        }
        function citation(source) {
            const value = source.start_time;
            const validTime = typeof value === 'string' && /^\d{2,3}:[0-5]\d:[0-5]\d(?:[.,]\d+)?$/.test(value);
            const allowed = bridge && (source.video_id === bridge.videoId || bridge.videos?.some(video => video.videoId === source.video_id));
            const element = document.createElement(validTime && allowed ? 'button' : 'span');
            const label = source.title + (value ? ' ' + value : '');
            element.title = label;
            element.textContent = validTime ? '(' + value.split(/[.,]/)[0].replace(/^00:/, '').replace(/^0(\d:)/, '$1') + ')' : source.title;
            if (element.tagName === 'BUTTON') {
                element.type = 'button'; element.className = 'btn btn-link p-0 align-baseline citation';
                element.setAttribute('aria-label', label + root.dataset.seekSuffix);
                element.dataset.videoId = String(source.video_id);
                element.addEventListener('click', () => {
                    const target = bridge.videos?.find(video => video.videoId === source.video_id);
                    if (target) {
                        const seconds = source.start_time.replace(',', '.').split(':').reduce((total, value) => total * 60 + Number(value), 0);
                        workspace.dispatchEvent(new CustomEvent('videoq:seek-citation', { detail: { videoId: target.videoId, lesson: target.lesson, seconds } }));
                    } else post('videoq:open-source', { source: { video_id: source.video_id, start_time: source.start_time } });
                });
            } else element.className = 'text-muted';
            return element;
        }
        function answer(value, existing) {
            if (!value || !Array.isArray(value.segments) || !Array.isArray(value.sources)) return;
            const body = existing || message('answer');
            body.replaceChildren();
            const sources = new Map(value.sources.map(source => [source.id, source]));
            for (const segment of value.segments) {
                if (typeof segment.text !== 'string' || !Array.isArray(segment.sourceIds)) continue;
                richText(body, segment.text);
                const target = body.lastElementChild || body;
                for (const id of new Set(segment.sourceIds)) {
                    const source = sources.get(id);
                    if (source) target.append(document.createTextNode(' '), citation(source));
                }
            }
            return body;
        }
        function feedback(responseId, body, value) {
            const template = root.querySelector('#videoq-native-feedback-template');
            const row = template.content.firstElementChild.cloneNode(true);
            const state = { row, value, busy: false };
            ratings.set(responseId, state);
            for (const button of row.querySelectorAll('button')) {
                const rating = button.dataset.feedback;
                button.setAttribute('aria-pressed', String(value === rating));
                button.classList.toggle('active', value === rating);
                button.addEventListener('click', () => {
                    if (state.busy) return;
                    state.busy = true;
                    for (const control of row.querySelectorAll('button')) control.disabled = true;
                    post('videoq:feedback', { targetRequestId: responseId, feedback: state.value === rating ? null : rating });
                });
            }
            body.closest('[data-region="message"]').append(row);
        }
        form.addEventListener('submit', event => {
            event.preventDefault();
            const text = input.value.trim();
            if (!bridge || controls.disabled || pending || !text || text.length > 4000) return;
            pending = Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
            setBusy(true);
            // Take the playhead at submission, rather than relying on queued cross-frame updates.
            const snapshot = { playback: null };
            workspace.dispatchEvent(new CustomEvent('videoq:read-playback', { detail: snapshot }));
            post('videoq:question', { text, requestId: pending, playback: snapshot.playback });
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && event.keyCode !== 229) {
                event.preventDefault(); form.requestSubmit();
            }
        });
        window.addEventListener('message', event => {
            if (event.source !== frame.contentWindow || event.origin !== origin) return;
            const data = event.data;
            if (!data || data.version !== 1) return;
            if (data.type === 'videoq:unavailable') {
                window.clearTimeout(initialTimer);
                status.textContent = typeof data.text === 'string' ? data.text : root.dataset.connectionError;
                status.classList.add('text-danger'); setBusy(true); return;
            }
            if (data.type === 'videoq:ready' && typeof data.channel === 'string' && /^[a-f0-9]{32}$/.test(data.channel)
                    && Number.isSafeInteger(data.videoId) && data.videoId > 0) {
                bridge = data;
                post('videoq:ui', { renderer: 'moodle' });
                return;
            }
            if (!bridge || data.type !== 'videoq:chat' || data.channel !== bridge.channel || data.videoId !== bridge.videoId) return;
            if (data.event === 'history' && Array.isArray(data.turns)) {
                for (const turn of data.turns.slice(-50)) {
                    if (!turn || typeof turn.id !== 'string' || !/^[a-f0-9]{32}$/.test(turn.id)
                            || typeof turn.question !== 'string' || turn.question.length > 4000 || shownQuestions.has(turn.id)) continue;
                    shownQuestions.add(turn.id); inline(message('question'), turn.question);
                    const body = answer(turn.answer);
                    if (body) {
                        shownAnswers.add(turn.id); answerBodies.set(turn.id, body);
                        if (turn.feedbackAvailable) feedback(turn.id, body, turn.feedback);
                    }
                }
                scrollLatest(); return;
            }
            if (data.event === 'feedback' && typeof data.targetRequestId === 'string') {
                const rating = ratings.get(data.targetRequestId);
                if (!rating || !(data.feedback === null || data.feedback === 'good' || data.feedback === 'bad')) return;
                rating.value = data.feedback; rating.busy = false;
                for (const button of rating.row.querySelectorAll('button')) {
                    button.disabled = false;
                    button.setAttribute('aria-pressed', String(button.dataset.feedback === data.feedback));
                    button.classList.toggle('active', button.dataset.feedback === data.feedback);
                }
                rating.row.querySelector('[role="status"]').textContent = data.ok ? '' : data.text;
                return;
            }
            if (data.requestId && data.requestId !== pending) return;
            window.clearTimeout(initialTimer);
            if (data.event === 'question' && data.requestId && typeof data.text === 'string' && !shownQuestions.has(data.requestId)) {
                shownQuestions.add(data.requestId); inline(message('question'), data.text); scrollLatest();
            } else if (data.event === 'answer-update' && data.requestId && !shownAnswers.has(data.requestId)) {
                const body = answer(data.answer, answerBodies.get(data.requestId));
                if (body) answerBodies.set(data.requestId, body);
                scrollLatest();
            } else if (data.event === 'answer' && data.requestId && !shownAnswers.has(data.requestId)) {
                shownAnswers.add(data.requestId);
                const body = answer(data.answer, answerBodies.get(data.requestId));
                if (body) { answerBodies.set(data.requestId, body); if (data.feedbackAvailable) feedback(data.requestId, body, data.feedback); }
                input.value = ''; input.dispatchEvent(new Event('input', { bubbles: true })); scrollLatest();
            } else if (data.event === 'status' && typeof data.busy === 'boolean') {
                connected = true;
                status.textContent = typeof data.text === 'string' ? data.text : '';
                status.classList.toggle('text-danger', data.kind === 'error');
                setBusy(data.busy);
                if (!data.busy) { const restoreFocus = pending !== null; pending = null; if (restoreFocus) input.focus({ preventScroll: true }); scrollLatest(); }
            }
        });
        window.addEventListener('pagehide', () => { window.clearTimeout(initialTimer); resize.disconnect(); }, { once: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
