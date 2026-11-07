// Contract checks use the actual player.js with direct Moodle material and seek events.
(async function() {
    const checks = [], fixture = window.playerFixture;
    const check = (condition, label) => { if (!condition) throw new Error(label); checks.push(label); };
    const wait = async predicate => {
        const deadline = Date.now() + 5000;
        while (!predicate()) { if (Date.now() > deadline) throw new Error('Owned player condition timed out'); await new Promise(resolve => setTimeout(resolve, 20)); }
    };
    try {
        await wait(() => fixture.records.length === 1);
        const ready = {
            videos: [{ videoId: 1, lesson: 'file' }, { videoId: 2, lesson: 'youtube', sourceGeneration: 7 }] };
        fixture.send(ready);
        await wait(() => fixture.read()?.videoId === 2);
        check(fixture.read().duration === 120, 'current URL player reports authorized playback');
        const first = fixture.records[0];
        const providerUrl = new URL(first.requestedSource);
        check(first.requestedReferrerPolicy === 'strict-origin-when-cross-origin'
            && providerUrl.origin === 'https://www.youtube.com' && providerUrl.searchParams.get('origin') === location.origin
            && providerUrl.searchParams.get('enablejsapi') === '1', 'provider identifies only the Moodle site origin');
        fixture.root.dispatchEvent(new CustomEvent('videoq:seek-citation', { detail: { videoId: 2, lesson: 'youtube', seconds: 38 } }));
        check(first.position === 38 && first.plays === 1, 'native citation seeks and plays the provider');
        const seek = { type: 'videoq:seek', version: 1, channel: 'a'.repeat(32), videoId: 2, lesson: 'youtube', seconds: 75 };
        window.postMessage(seek, location.origin);
        window.postMessage({...seek, channel: 'b'.repeat(32)}, '*');
        await new Promise(resolve => setTimeout(resolve, 100));
        check(first.position === 38, 'old window-message commands cannot seek the native player');
        fixture.root.dispatchEvent(new CustomEvent('videoq:seek-citation', {detail: {videoId: 2, lesson: 'youtube', seconds: 75}}));
        await wait(() => first.position === 75);
        check(first.plays === 2, 'authorized local Moodle seek works');
        first.options.events.onAutoplayBlocked();
        check(fixture.read()?.videoId === 2 && fixture.root.querySelector('[role="status"]').textContent.includes('press Play'),
            'blocked scripted playback keeps seeking available and explains Play');
        const bounds = first.frame.getBoundingClientRect();
        check(bounds.width >= 200 && bounds.height >= 200, 'provider viewport meets minimum dimensions');
        window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true }));
        check(first.destroyed && fixture.read() === null, 'cached navigation stops the old provider and playback state');
        window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
        await wait(() => fixture.read()?.videoId === 2 && fixture.read()?.seconds === 75);
        check(fixture.records.at(-1).plays === 0, 'cached navigation restores position without automatic playback');
        const restored = fixture.records.at(-1);
        fixture.select('file');
        await wait(() => fixture.read()?.videoId === 1);
        check(restored.destroyed && fixture.root.querySelector('[data-videoq-youtube]').hidden, 'switch to File destroys the old provider');
        fixture.root.dispatchEvent(new CustomEvent('videoq:seek-citation', { detail: { videoId: 1, lesson: 'file', seconds: 12 } }));
        check(fixture.read().seconds === 12, 'existing File seek remains available');
        fixture.delayed = true;
        const pendingIndex = fixture.records.length;
        fixture.select('youtube');
        await wait(() => fixture.pending.length === 1);
        const late = fixture.records[pendingIndex];
        late.options.events.onError();
        check(fixture.read() === null && fixture.root.querySelector('select').value === 'youtube',
            'early provider failure leaves a usable source selector');
        fixture.select('file');
        await wait(() => fixture.read()?.videoId === 1);
        fixture.pending.shift()();
        check(late.destroyed && fixture.read().videoId === 1, 'late provider ready cannot replace the selected File');
        fixture.delayed = false;
        fixture.select('youtube');
        await wait(() => fixture.read()?.videoId === 2);
        const unproven = fixture.records.at(-1);
        fixture.send({ ...ready, videos: undefined });
        await wait(() => fixture.read() === null && unproven.destroyed);
        check(unproven.destroyed, 'URL playback context requires a current matching material grant');
        fixture.send(ready);
        await wait(() => fixture.read()?.videoId === 2);
        check(fixture.records.at(-1) !== unproven, 'restored matching scope can reopen the provider');
        fixture.records.at(-1).options.events.onError();
        check(fixture.read() === null && fixture.root.querySelector('[role="status"]').textContent.includes('provider failed'),
            'provider failure suppresses playback state');
        fixture.send({ ...ready, videos: [{ videoId: 1, lesson: 'file' }, { videoId: 3, lesson: 'youtube', sourceGeneration: 8 }] });
        await wait(() => fixture.read()?.videoId === 1);
        check(fixture.root.querySelector('select').options.length === 1, 'stale URL catalog cannot use a new provider generation');
        check(fixture.root.querySelector('[role="status"]').textContent.includes('source changed'), 'stale catalog explains the required reload');
        check(document.documentElement.scrollWidth <= innerWidth, 'narrow player has no horizontal overflow');
        check(fixture.errors.length === 0, 'no browser execution errors');
        window.videoqPlayerResult = { passed: true, checks };
    } catch (error) { window.videoqPlayerResult = { passed: false, checks, error: error.message }; }
})();
