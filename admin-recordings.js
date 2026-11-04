/* Recording controls on the training administration page. */
(() => {
    'use strict';
    const buttons = Array.from(document.querySelectorAll('.recordings-button'));
    const modal = document.getElementById('recordings-modal');
    if (!modal || !buttons.length) return;
    const list = document.getElementById('recordings-list');
    const refresh = document.getElementById('recordings-refresh');
    const cache = new Map();
    const detailCache = new Map();
    let activeButton = null;
    let generation = 0;
    let deletionPending = false;

    function deletionNotice(message, failed = false) {
        const notice = document.getElementById('recording-delete-notice');
        notice.textContent = message;
        notice.className = failed ? 'recording-warning' : '';
        notice.hidden = !message;
    }

    async function deleteRecording(button, recording, date) {
        if (deletionPending) return;
        if (!window.confirm(`Delete this recording?\n\n${recording.name}\n${date} (Europe/Brussels)\nRecording ID: ${recording.id}\n\nThis removes the recording from BBB. The training itself will remain.`)) return;
        deletionPending = true;
        refresh.disabled = true;
        list.querySelectorAll('.delete-recording').forEach(control => { control.disabled = true; });
        deletionNotice('Deleting recording…');
        try {
            const data = await request('delete_recording_ajax', {
                ID: button.dataset.trainingId, record_id: recording.id, confirmed: '1'
            });
            if (data.deletedRecordId !== recording.id) throw new Error('The server did not confirm which recording was deleted.');
            // The same meeting may be referenced by more than one training row.
            buttons.forEach(control => {
                const items = cache.get(control.dataset.trainingId);
                if (!items) return;
                const remaining = items.filter(item => item.id !== recording.id);
                cache.set(control.dataset.trainingId, remaining);
                setButton(control, remaining);
            });
            for (const key of detailCache.keys()) if (key.endsWith(':' + recording.id)) detailCache.delete(key);
            deletionPending = false;
            if (activeButton && !modal.hidden) renderRecordings(activeButton);
            deletionNotice('Recording deleted.');
        } catch (error) {
            deletionNotice('Deletion could not be confirmed: ' + error.message + ' Refresh the list before retrying.', true);
        } finally {
            deletionPending = false;
            refresh.disabled = false;
            list.querySelectorAll('.delete-recording').forEach(control => { control.disabled = false; });
        }
    }

    async function request(action, fields) {
        const body = new URLSearchParams({
            action,
            csrf_token: document.querySelector('#training-rows-form [name="csrf_token"]').value
        });
        Object.entries(fields).forEach(([key, value]) => {
            if (Array.isArray(value)) value.forEach(item => body.append(key + '[]', item));
            else body.append(key, value);
        });
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 90000);
        try {
            const response = await fetch(window.location.pathname, {
                method: 'POST', credentials: 'same-origin', body,
                signal: controller.signal, headers: {Accept: 'application/json'}
            });
            let data;
            try { data = await response.json(); }
            catch { throw new Error('The recording request failed. Sign in again if your session has expired.'); }
            if (!response.ok || !data.success) throw new Error(data.message || 'Could not load recordings.');
            return data;
        } finally { window.clearTimeout(timeout); }
    }

    function setButton(button, items, failed = false) {
        button.disabled = !failed && (!items || items.length === 0);
        button.dataset.failed = failed ? '1' : '';
        button.textContent = failed ? 'Recordings · Retry' : items ? `Recordings (${items.length})` : 'Checking…';
        button.title = failed ? 'Availability could not be checked. Click to retry.'
            : items && !items.length ? 'No recordings available for this meeting.' : 'Show meeting recordings';
    }

    async function loadAvailability(selection) {
        selection.forEach(button => setButton(button, null));
        try {
            const data = await request('recordings_list_ajax', {ids: selection.map(button => button.dataset.trainingId)});
            selection.forEach(button => {
                const items = data.trainings[button.dataset.trainingId];
                if (!Array.isArray(items)) { setButton(button, null, true); return; }
                cache.set(button.dataset.trainingId, items);
                setButton(button, items);
            });
            return true;
        } catch {
            selection.forEach(button => setButton(button, null, true));
            return false;
        }
    }

    function node(tag, className, text) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
    }

    function safeUrl(value) {
        try {
            const url = new URL(value);
            return ['https:', 'http:'].includes(url.protocol) ? url.href : null;
        } catch { return null; }
    }

    function link(label, value, download = false) {
        const url = safeUrl(value);
        if (!url) return null;
        const anchor = node('a', 'button secondary', label);
        anchor.href = url;
        anchor.target = '_blank';
        anchor.rel = 'noopener noreferrer';
        if (download) anchor.download = new URL(url).pathname.split('/').pop();
        return anchor;
    }

    function copyButton(label, value, status) {
        const button = node('button', 'secondary', label);
        button.type = 'button';
        button.addEventListener('click', async () => {
            try {
                await copyText(value);
                button.textContent = 'Copied';
                window.setTimeout(() => { button.textContent = label; }, 1800);
            } catch { status.textContent = 'Copy failed. Open the link and copy it from your address bar.'; }
        });
        return button;
    }

    const dateFormat = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Europe/Brussels', weekday: 'short', day: '2-digit',
        month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
    });

    function duration(recording) {
        const formats = recording.formats || [];
        const playback = formats.find(item => item.type === 'presentation') || formats[0];
        if (playback && Number(playback.length) > 0) return `${playback.length} min playback`;
        if (playback) return 'Under 1 min playback';
        const seconds = Math.max(0, Math.round((recording.endTime - recording.startTime) / 1000));
        return seconds ? `${Math.floor(seconds / 60)} min ${seconds % 60} sec session` : 'Duration pending';
    }

    function renderDetails(container, details) {
        container.replaceChildren();
        const status = node('p', 'recording-copy-status');
        status.setAttribute('role', 'status');
        const actions = node('div', 'recording-media-actions');
        const presentation = safeUrl(details.presentationUrl);
        if (presentation) {
            const group = node('div', 'recording-action-group');
            group.append(link('▶ Presentation ↗', presentation), copyButton('Copy presentation link', presentation, status));
            actions.append(group);
        }
        details.videos.forEach((video, index) => {
            const url = safeUrl(video.url);
            if (!url) return;
            const suffix = details.videos.length > 1 ? ` ${index + 1}` : '';
            const group = node('div', 'recording-action-group');
            group.append(link(`↓ Video file${suffix}`, url, true), copyButton(`Copy video file link${suffix}`, url, status));
            actions.append(group);
        });
        container.append(actions);
        const captions = node('div', 'recording-captions');
        captions.append(node('strong', '', 'Captions:'));
        details.captions.forEach(track => {
            const anchor = link((track.label || track.lang || 'Transcript') + ' ↗', track.url);
            if (anchor) captions.append(anchor);
        });
        if (!details.captions.length) captions.append(node('span', 'recording-muted',
            details.warnings.length ? 'No accessible caption links found.' : 'None available'));
        container.append(captions, status);
        details.warnings.forEach(message => container.append(node('p', 'recording-warning', message)));
        if (!presentation && !details.videos.length) container.append(node('p', 'recording-muted', 'Playback is not yet available.'));
    }

    async function loadCardDetails(trainingId, recording, container, token) {
        if (token !== generation) return;
        const key = trainingId + ':' + recording.id;
        try {
            let details = detailCache.get(key);
            if (!details) {
                const data = await request('recording_details_ajax', {ID: trainingId, record_id: recording.id});
                details = data.details;
                if (!details.warnings.length) detailCache.set(key, details);
            }
            if (token !== generation) return;
            renderDetails(container, details);
            if (details.warnings.length) {
                const retry = node('button', 'secondary', 'Retry file links');
                retry.type = 'button';
                retry.addEventListener('click', () => {
                    container.textContent = 'Loading playback and captions…';
                    loadCardDetails(trainingId, recording, container, token);
                });
                container.append(retry);
            }
        } catch (error) {
            if (token !== generation) return;
            container.replaceChildren(node('p', 'recording-warning', error.message));
            const retry = node('button', 'secondary', 'Retry file links');
            retry.type = 'button';
            retry.addEventListener('click', () => {
                container.textContent = 'Loading playback and captions…';
                loadCardDetails(trainingId, recording, container, token);
            });
            container.append(retry);
        }
    }

    function renderRecordings(button) {
        const token = ++generation;
        const items = cache.get(button.dataset.trainingId) || [];
        document.getElementById('recordings-count').textContent = `${items.length} recording${items.length === 1 ? '' : 's'}`;
        list.replaceChildren();
        if (!items.length) { list.append(node('p', 'recording-muted', 'No recordings available for this meeting.')); return; }
        const queue = [];
        items.forEach(recording => {
            const card = node('article', 'recording-card');
            const head = node('div', 'recording-card-head');
            const date = recording.startTime > 0 ? dateFormat.format(new Date(recording.startTime)) : 'Start time unavailable';
            const state = recording.state || (recording.published ? 'published' : 'unpublished');
            head.append(node('h3', '', date), node('span', 'recording-duration', duration(recording)),
                node('span', 'recording-meta', `${recording.participants} participant${recording.participants === 1 ? '' : 's'}`),
                node('span', 'recording-state', state.charAt(0).toUpperCase() + state.slice(1)));
            if (recording.size > 0) head.append(node('span', 'recording-meta', `${(recording.size / 1048576).toFixed(1)} MB total`));
            const remove = node('button', 'danger delete-recording', 'Delete recording');
            remove.type = 'button';
            remove.disabled = deletionPending;
            remove.addEventListener('click', () => deleteRecording(button, recording, date));
            head.append(remove);
            card.append(head);
            const identity = node('div', 'recording-identity');
            identity.append(node('span', 'recording-name', recording.name));
            const id = node('details', 'recording-id');
            id.append(node('summary', '', 'Recording ID'), node('code', '', recording.id));
            identity.append(id);
            card.append(identity);
            const files = node('div', 'recording-files', recording.formats.length
                ? 'Loading playback and captions…' : 'Playback files are not yet available. Refresh after processing completes.');
            files.setAttribute('aria-live', 'polite');
            card.append(files);
            list.append(card);
            if (recording.formats.length) queue.push({recording, files});
        });
        // Keep both API and player requests bounded for trainings with many recordings.
        async function worker() {
            while (queue.length && token === generation) {
                const {recording, files} = queue.shift();
                await loadCardDetails(button.dataset.trainingId, recording, files, token);
            }
        }
        worker(); worker();
    }

    async function refreshActive() {
        if (!activeButton || deletionPending) return;
        deletionNotice('');
        const button = activeButton;
        const token = ++generation;
        refresh.disabled = true;
        list.textContent = 'Checking recordings…';
        const ok = await loadAvailability([button]);
        if (token !== generation || button !== activeButton) return;
        refresh.disabled = false;
        if (ok) {
            for (const key of detailCache.keys()) if (key.startsWith(button.dataset.trainingId + ':')) detailCache.delete(key);
            renderRecordings(button);
        } else {
            document.getElementById('recordings-count').textContent = '';
            list.textContent = 'Recording availability could not be checked. Use Refresh to retry.';
        }
    }

    buttons.forEach(button => button.addEventListener('click', () => {
        activeButton = button;
        const row = button.closest('tr');
        document.getElementById('recordings-title').textContent = 'Recordings — '
            + row.querySelector('input[name$="[training_name]"]').value;
        document.getElementById('recordings-meeting').textContent = 'Meeting ID: '
            + row.querySelector('input[name$="[meeting_id]"]').value;
        refresh.disabled = deletionPending;
        openAdminModal(modal, button);
        if (!deletionPending) deletionNotice('');
        if (button.dataset.failed) refreshActive();
        else renderRecordings(button);
    }));
    refresh.addEventListener('click', refreshActive);
    new MutationObserver(() => {
        if (modal.hidden) { ++generation; activeButton = null; }
    }).observe(modal, {attributes: true, attributeFilter: ['hidden']});
    loadAvailability(buttons);
})();
