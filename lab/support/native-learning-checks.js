// Exercises production native UI handlers with explicit Moodle/API substitutes.
(async function() {
    const f = nativeLearningFixture, c = f.copy, checks = [];
    const check = (condition, label) => {if (!condition) throw new Error(label); checks.push(label);};
    const wait = async condition => {const until = Date.now() + 7000; while (!condition()) {if (Date.now() > until) throw new Error('Timed out: ' + condition.toString()); await new Promise(r => setTimeout(r, 20));}};
    const byId = id => document.getElementById('videoq-' + id);
    const button = (root, label) => [...root.querySelectorAll('button')].find(b => b.textContent.trim() === label);
    const input = (node, value) => {node.value = value; node.dispatchEvent(new Event('input', {bubbles: true}));};
    try {
        await wait(() => f.calls.some(call => call.args.operation === 'tags'));
        const config = JSON.parse(document.querySelector('.local-videoq-workspace').dataset.videoqConfig);
        check(Object.keys(config).sort().join(',') === 'copy,courseid,lesson,streamUrl', 'Page bootstrap contains public presentation data only');
        check(document.querySelectorAll('iframe').length === 0, 'File and empty-course pages have no application or authentication iframe');
        if (nativeFixtureConfig.empty) {
            check(byId('native-question').disabled, 'Empty course disables AI questions');
            check(!document.querySelector('.local-videoq-video'), 'Empty course needs no fake video anchor');
            byId('course-qa').open = true;
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
            byId('course-materials').open = true;
            await wait(() => byId('material-cues').textContent.includes('Owned transcript'));
            check(byId('material-cues').querySelector('button'), 'Transcript is read and sought natively');
            byId('course-tags').open = true;
            await wait(() => !byId('tags-create').hidden && !byId('tags-create').disabled);
            byId('tags-create').click();
            await wait(() => byId('tags-name'));
            input(byId('tags-name'), 'Owned course tag'); byId('tags-editor').requestSubmit();
            await wait(() => byId('tags-editor').hidden && f.tags.length === 1);
            check(byId('tags-vocabulary').textContent.includes('Owned course tag'), 'Course tag creation uses a native Moodle form');
            button(byId('tags-materials'), c.tags.assign).click();
            await wait(() => byId('tags-fields').querySelector('input[type="checkbox"]'));
            const checkbox = byId('tags-fields').querySelector('input[type="checkbox"]');
            checkbox.checked = true; checkbox.dispatchEvent(new Event('change', {bubbles: true})); byId('tags-editor').requestSubmit();
            await wait(() => byId('tags-editor').hidden && f.tags[0].videoIds.includes(1));
            check(f.tags[0].videoIds.includes(1), 'Native tag assignment updates the material');
            byId('tags-create').click(); await wait(() => byId('tags-name'));
            input(byId('tags-name'), 'Unsaved tag draft');
            byId('material-management').open = true;
            await wait(() => !byId('management-description').readOnly);
            input(byId('management-description'), 'Owned changed description'); f.failWrite = true;
            byId('management-form').requestSubmit();
            await wait(() => !byId('management-save').disabled);
            check(byId('management-description').value === 'Owned changed description', 'Lost write response preserves the teacher draft');
            byId('management-form').requestSubmit();
            await wait(() => byId('management-status').textContent === c.management.saved);
            const writes = f.calls.filter(call => call.args.operation === 'management' && call.input.action === 'update');
            check(writes.length === 2 && writes[0].input.operationId === writes[1].input.operationId && f.writes === 1,
                'Uncertain teacher write retries reuse one operation identity');
            input(byId('management-description'), 'Conflict draft'); f.conflictWrite = true; byId('management-form').requestSubmit();
            await wait(() => byId('management-status').textContent === c.management.conflict);
            check(byId('management-description').value === 'Conflict draft', 'Revision conflict preserves the teacher draft');
            f.conflictWrite = false; f.denyManage = true;
            byId('material-refresh').click();
            await wait(() => byId('management-description').readOnly);
            check(byId('management-description').value === 'Conflict draft' && !byId('material-management').hidden,
                'Revoked teacher permission keeps the visible draft read-only');
            check(byId('tags-name').value === 'Unsaved tag draft' && byId('tags-name').readOnly && byId('tags-save').disabled,
                'Revoked teacher permission preserves and locks the tag draft');
            check(!button(byId('tags-materials'), c.tags.open).disabled, 'Tag browsing remains available after write permission is revoked');
            byId('course-qa').open = true;
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
            byId('course-reports').open = true;
            await wait(() => byId('reports-content').querySelector('table'));
            byId('reports-export').click();
            await wait(() => f.downloads.some(item => item.name.endsWith('.csv')));
            const download = f.downloads.find(item => item.name.endsWith('.csv'));
            const csv = await (await fetch(download.url)).text();
            check(csv.startsWith('date,questions,good,bad,unrated\n') && csv.split('\n').length >= 3, 'Native report exports valid aggregate CSV rows');
            byId('personal-history').open = true;
            await wait(() => button(byId('personal-history-content'), c.personal.refresh)?.disabled === false);
            button(byId('personal-history-content'), c.personal.refresh).click();
            await wait(() => button(byId('personal-history-content'), c.personal.open)?.disabled === false);
            button(byId('personal-history-content'), c.personal.open).click();
            await wait(() => button(byId('personal-history-content'), c.personal.export)?.disabled === false);
            button(byId('personal-history-content'), c.personal.export).click();
            await wait(() => f.downloads.some(item => item.name.endsWith('.ndjson')));
            const archive = f.downloads.find(item => item.name.endsWith('.ndjson'));
            const exported = (await (await fetch(archive.url)).text()).trimEnd().split('\n').map(line => JSON.parse(line));
            check(exported[0].type === 'conversation' && exported[1].type === 'turn', 'Personal conversation export produces valid NDJSON');
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
