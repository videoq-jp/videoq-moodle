// Actual production player: fresh Moodle AJAX playback, playlist and citation contracts.
(async () => {
    const checks = [], f = window.playerFixture, media = f.root.querySelector('video');
    const check = (value, label) => {if (!value) throw new Error(label); checks.push(label);};
    const wait = async predicate => {const end = Date.now() + 5000; while (!predicate()) {
        if (Date.now() > end) throw new Error('Player condition timed out'); await new Promise(resolve => setTimeout(resolve, 20));
    }};
    try {
        await wait(() => f.read()?.videoId === 1);
        check(f.calls[0].methodname === 'local_videoq_request_learning' && f.calls[0].args.operation === 'playback'
            && f.calls[0].args.lesson === '', 'Playback uses Moodle core AJAX with a video ID and course anchor');
        check(f.read().duration === 120, 'Native file reports authorized playback duration');
        check(media.paused, 'Initial page load does not autoplay');
        f.delayed = true;
        f.root.dispatchEvent(new CustomEvent('videoq:seek-citation', {detail: {videoId: 2, seconds: 37}}));
        await wait(() => f.pending.length);
        check(f.root.querySelector('[data-videoq-player-status]').textContent === ''
            && !f.root.querySelector('[data-videoq-file]').hidden, 'Cross-video citation retains the player without reconnecting the learning system');
        check(f.read() === null, 'Pending video never reports the previous media as the new video');
        const pendingCalls = f.calls.length;
        f.root.dispatchEvent(new CustomEvent('videoq:seek-citation', {detail: {videoId: 2, seconds: 38}}));
        check(f.calls.length === pendingCalls, 'Repeated pending citation reuses the authorized playback request');
        f.pending.shift()(); f.delayed = false;
        await wait(() => f.root.querySelector('iframe'));
        const frame = f.root.querySelector('iframe');
        check(new URL(frame.src).searchParams.get('start') === '38', 'Cross-video citation applies the latest timestamp after authorization completes');
        check(new URL(frame.src).searchParams.get('autoplay') === '1' && frame.allow.includes('autoplay'),
            'Provider citations request playback with delegated autoplay permission');
        check(new URL(frame.src).origin === 'https://www.youtube-nocookie.com', 'Provider embed uses the checked privacy origin');
        check(f.read() === null, 'Provider position is not invented without a provider API');
        f.root.dispatchEvent(new CustomEvent('videoq:seek-citation', {detail: {videoId: 2, seconds: 38}}));
        check(new URL(frame.src).searchParams.get('start') === '38', 'Citation seeks the currently authorized provider video');
        window.postMessage({type: 'videoq:seek', videoId: 2, seconds: 75}, '*');
        await new Promise(resolve => setTimeout(resolve, 30));
        check(new URL(frame.src).searchParams.get('start') === '38', 'Window messages cannot issue native seek commands');
        f.select(1); await wait(() => f.read()?.videoId === 1);
        check(!f.root.querySelector('iframe'), 'Switching to a file removes the provider frame');
        f.root.dispatchEvent(new CustomEvent('videoq:seek-citation', {detail: {videoId: 1, seconds: 12}}));
        check(f.read().seconds === 12 && !media.paused, 'File citation seeks and resumes paused media');
        f.root.dispatchEvent(new CustomEvent('videoq:seek-citation', {detail: {videoId: 1, seconds: 14}}));
        check(f.read().seconds === 14 && !media.paused, 'Playing media continues after a same-video citation');
        const count = f.calls.length;
        f.root.dispatchEvent(new CustomEvent('videoq:seek-citation', {detail: {videoId: 999, seconds: 12}}));
        check(f.calls.length === count && f.read().videoId === 1, 'Unknown video citations never fetch playback');
        f.delayed = true;
        f.root.dispatchEvent(new CustomEvent('videoq:seek-citation', {detail: {videoId: 3, seconds: 24.75}}));
        await wait(() => f.pending.length);
        check(f.root.querySelector('[data-videoq-player-status]').textContent === '' && f.read() === null,
            'File-to-file citation waits locally without reconnecting or reporting stale context');
        f.pending.shift()(); f.delayed = false;
        await wait(() => f.read()?.videoId === 3);
        check(f.read().seconds === 24.75 && !media.paused, 'File-to-file citation preserves the requested fractional timestamp');
        f.delayed = true; f.select(2); await wait(() => f.pending.length);
        f.send([]); f.pending.shift()(); await new Promise(resolve => setTimeout(resolve, 30));
        check(!f.root.querySelector('iframe') && f.read() === null && !f.root.querySelector('[data-videoq-playlist]').children.length,
            'Revocation clears playback and a late response cannot restore it');
        f.delayed = false; f.send([{videoId: 1, lesson: '1', title: 'Restored'}]);
        await wait(() => f.read()?.videoId === 1);
        f.readyState = 0;
        f.root.dispatchEvent(new CustomEvent('videoq:seek-citation', {detail: {videoId: 1, seconds: 20}}));
        f.root.dispatchEvent(new CustomEvent('videoq:seek-citation', {detail: {videoId: 1, seconds: 30}}));
        f.readyState = 2; media.dispatchEvent(new Event('loadedmetadata'));
        check(media.currentTime === 30 && !media.paused, 'Metadata readiness plays only the latest citation target');
        f.readyState = 0; media.pause();
        f.root.dispatchEvent(new CustomEvent('videoq:seek-citation', {detail: {videoId: 1, seconds: 40}}));
        f.send([]); f.readyState = 2; media.dispatchEvent(new Event('loadedmetadata'));
        check(media.paused, 'Revoked media cannot resume from a stale metadata event');
        f.send([{videoId: 1, lesson: '1', title: 'Restored'}]);
        await wait(() => f.read()?.videoId === 1);
        f.rejectPlay = true;
        f.root.dispatchEvent(new CustomEvent('videoq:seek-citation', {detail: {videoId: 1, seconds: 50}}));
        await new Promise(resolve => setTimeout(resolve, 30));
        check(media.currentTime === 50 && media.paused && f.read()?.videoId === 1,
            'Denied autoplay preserves the seek and usable player without failing authorization');
        check(!f.errors.length, 'Player events finish without execution errors');
        window.videoqPlayerResult = {passed: true, checks};
    } catch (error) {window.videoqPlayerResult = {passed: false, checks, error: error.message, errors: f.errors};}
})();
