// Exercises production native UI handlers with explicit Moodle/API substitutes.
(async function() {
    const f = nativeLearningFixture, c = f.copy, checks = [];
    const check = (condition, label) => {if (!condition) throw new Error(label); checks.push(label);};
    const wait = async condition => {const until = Date.now() + 7000; while (!condition()) {if (Date.now() > until) throw new Error('Timed out: ' + condition.toString()); await new Promise(r => setTimeout(r, 20));}};
    const byId = id => document.getElementById('videoq-' + id);
    const button = (root, label) => [...root.querySelectorAll('button')].find(b => b.textContent.trim() === label);
    const input = (node, value) => {node.value = value; node.dispatchEvent(new Event('input', {bubbles: true}));};
    try {
        await wait(() => f.calls.some(call => call.args.operation === 'conversations'));
        const config = JSON.parse(document.querySelector('.local-videoq-workspace').dataset.videoqConfig);
        check(Object.keys(config).sort().join(',') === 'copy,courseid,lesson,streamUrl', 'Page bootstrap contains public presentation data only');
        check(document.querySelectorAll('iframe').length === 0, 'File and empty-course pages have no application or authentication iframe');
        if (nativeFixtureConfig.empty) {
            check(byId('native-question').disabled, 'Empty course disables AI questions');
            check(!document.querySelector('.local-videoq-video'), 'Empty course needs no fake video anchor');
            document.querySelector('[data-videoq-main-tab=qa]').click();
            await wait(() => button(byId('qa-content'), c.qa.ask));
            check(!byId('course-qa').hidden, 'Empty course retains native course Q&A');
        } else {
            await wait(() => !byId('native-question').disabled);
            input(byId('native-question'), 'Owned question'); byId('native-form').requestSubmit();
            await wait(() => !byId('native-question').disabled && f.streams.length === 1);
            check(byId('native-question').value === '', 'Successful streamed question clears its draft');
            check(byId('native-messages').textContent.includes('Owned answer <img'), 'Model HTML remains literal text');
            check(!window.nativeUnsafe && !byId('native-messages').querySelector('img[src=x]'), 'Model text cannot insert HTML');
            const stream = f.streams[0];
            check(new URL(stream.url).origin === location.origin && stream.values.sesskey === M.cfg.sesskey && !stream.options.headers,
                'Browser stream uses Moodle session and sesskey without VideoQ authorization');
            byId('native-messages').querySelector('.citation').click();
            check(f.seeks.at(-1)?.videoId === 1 && f.seeks.at(-1)?.seconds === 12, 'Answer citation seeks the local authorized player');
            byId('native-messages').querySelector('[data-feedback="good"]').click();
            await wait(() => f.calls.some(call => call.args.operation === 'feedback'));
            check(f.calls.find(call => call.args.operation === 'feedback').input.feedback === 'good', 'Feedback uses Moodle AJAX');
            check(!byId('course-materials').getClientRects().length, 'Transcript has no visible course tab');
            check(!byId('material-management').getClientRects().length, 'Course editing remains in VideoQ');
            document.querySelector('[data-videoq-side-tab=videos]').click();
            check(!document.querySelector('[data-videoq-side-pane=videos]').hidden, 'Native playlist has its own tab');
            document.querySelector('[data-videoq-side-tab=ai]').click();
            document.querySelector('[data-videoq-main-tab=qa]').click();
            await wait(() => button(byId('qa-content'), c.qa.ask));
            button(byId('qa-content'), c.qa.ask).click();
            await wait(() => byId('qa-input-title'));
            input(byId('qa-input-title'), 'Owned course question'); input(byId('qa-input-body'), 'Owned course body');
            byId('qa-input-body').closest('form').requestSubmit();
            await wait(() => byId('qa-content').textContent.includes('Owned course body'));
            check(f.questions.length === 1, 'Course Q&A creates a native Moodle form question');
            const vote = [...byId('qa-content').querySelectorAll('button')].find(b => b.textContent.startsWith(c.qa.vote + ' ('));
            vote.click(); await wait(() => f.questions[0].voted);
            check(f.questions[0].votes === 1, 'Course Q&A vote uses current Moodle operation');
            check(!document.querySelector('[data-videoq-new]') && !document.querySelector('[data-videoq-history]'),
                'Core parity: no learner new-conversation or personal-history buttons');
            document.querySelector('[data-course-history-tab]').click();
            await wait(() => document.querySelector('[data-course-history-content]').textContent.includes('learner@example.com'));
            check(byId('native-chat').hidden, 'Owner course history replaces chat as in the native viewer');
            document.querySelector('[data-course-history-csv]').click();
            await wait(() => f.downloads.some(item => item.name.endsWith('.csv')));
            const archive = f.downloads.find(item => item.name.endsWith('.csv'));
            check((await (await fetch(archive.url)).text()).includes('asked_by_email'), 'Owner exports native question/answer CSV');
            document.querySelector('[data-course-chat-tab]').click();
            check(!byId('native-chat').hidden, 'Returning to chat preserves the active conversation');
            f.failStream = true; input(byId('native-question'), 'Keep failed question'); byId('native-form').requestSubmit();
            await wait(() => !byId('native-question').disabled && f.streams.length === 2);
            check(byId('native-question').value === 'Keep failed question', 'Failed answer preserves its question draft');
            check(f.streams[1].aborted, 'Failed or completed answer reading closes its browser stream');
        }
        check(f.calls.every(call => call.method === 'local_videoq_request_learning' && !('subject' in call.args) && !('ownerId' in call.args)),
            'Browser selects operations without selecting actor, owner or upstream URL');
        check(!document.querySelector('[data-videoq-origin]') && !document.querySelector('#videoq-questions'), 'No old private-frame handshake remains');
        check(document.documentElement.scrollWidth <= innerWidth, 'Native panels fit a narrow viewport');
        check(f.errors.length === 0, 'Native browser handlers have no execution errors: ' + f.errors.join(';'));
        window.nativeLearningResult = {passed: true, checks};
    } catch (error) {window.nativeLearningResult = {passed: false, checks, error: error.message, browserErrors: f.errors};}
})();
