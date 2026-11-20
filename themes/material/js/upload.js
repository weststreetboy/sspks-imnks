(function () {
    'use strict';
    var translations = window.SSPKS_I18N || {};
    function t(key, fallback) { return typeof translations[key] === 'string' ? translations[key] : fallback; }
    function setupUploadPanel() {
        var panel = document.querySelector('[data-upload-panel]');
        if (!panel) { return; }
        var form = panel.querySelector('[data-upload-form]');
        var tokenInput = panel.querySelector('[data-upload-token]');
        var fileInput = panel.querySelector('[data-upload-file]');
        var button = panel.querySelector('[data-upload-button]');
        var status = panel.querySelector('[data-upload-status]');
        var percent = panel.querySelector('[data-upload-percent]');
        var progress = panel.querySelector('[data-upload-progress]');
        var nextText = panel.querySelector('[data-upload-next]');
        var indexLink = panel.querySelector('[data-upload-index-link]');
        var endpoint = panel.getAttribute('data-endpoint');

        function resetPercent() { progress.value = 0; percent.textContent = '0%'; }
        function setDisabled(state) {
            button.disabled = state;
            button.setAttribute('aria-busy', state ? 'true' : 'false');
        }

        function send(file, token) {
            return new Promise(function (resolve, reject) {
                var xhr = new XMLHttpRequest();
                var body = new FormData();
                body.append('spk', file);
                xhr.open('POST', endpoint, true);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-SSpkS-Token', token);
                xhr.responseType = 'json';
                xhr.upload.onprogress = function (event) {
                    if (!event.lengthComputable) { return; }
                    var value = Math.min(99, Math.round(event.loaded / event.total * 100));
                    progress.value = value;
                    percent.textContent = value + '%';
                };
                xhr.onload = function () {
                    var data = xhr.response;
                    if (!data) {
                        try { data = JSON.parse(xhr.responseText); } catch (error) { data = null; }
                    }
                    if (xhr.status === 401 || xhr.status === 403) {
                        reject(t('auth_invalid', 'The upload password is incorrect or not configured.'));
                        return;
                    }
                    if (xhr.status === 429) {
                        reject(t('auth_rate_limited', 'Too many password failures. Wait one minute before trying again.'));
                        return;
                    }
                    if (!data) { reject(t('upload_failed', 'Upload failed. Try again later.')); return; }
                    if (xhr.status < 200 || xhr.status >= 300 || data.ok !== true) {
                        reject(data.message || t('upload_failed', 'Upload failed. Try again later.'));
                        return;
                    }
                    resolve(data);
                };
                xhr.onerror = function () { reject(t('upload_failed', 'Upload failed. Try again later.')); };
                xhr.send(body);
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var token = tokenInput.value.trim();
            var file = fileInput.files && fileInput.files[0];
            if (!token || !file) { return; }
            if (!/.spk$/i.test(file.name)) { status.textContent = t('upload_bad_type', 'Only .spk files are accepted.'); return; }
            nextText.hidden = true;
            indexLink.hidden = true;
            resetPercent();
            setDisabled(true);
            status.textContent = t('upload_submit', 'Uploading…') + ' ' + file.name;
            send(file, token).then(function (data) {
                progress.value = 100;
                percent.textContent = '100%';
                status.textContent = data.message || t('upload_ok', 'Uploaded.');
                if (data.next) {
                    nextText.textContent = data.next;
                    nextText.hidden = false;
                }
                if (indexLink) {
                    if (data.indexUrl) { indexLink.setAttribute('href', data.indexUrl); }
                    indexLink.hidden = false;
                }
                form.reset();
            }).catch(function (message) {
                resetPercent();
                status.textContent = String(message);
            }).then(function () {
                tokenInput.value = '';
                setDisabled(false);
            });
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', setupUploadPanel, { once: true }); }
    else { setupUploadPanel(); }
}());
