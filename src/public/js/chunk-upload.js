/* 50 MiB parts, three workers, disk-backed resumable uploads. */
(function (root) {
    'use strict';
    const CHUNK_SIZE = 50 * 1024 * 1024;
    const CONCURRENCY = 6;
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

    async function retry(operation, isCancelled, delay = sleep) {
        for (let attempt = 0; ; attempt++) {
            if (isCancelled()) throw Object.assign(new Error('已取消上传'), { cancelled: true });
            try { return await operation(); }
            catch (error) {
                if (error.cancelled || !error.retryable || attempt >= 3 || isCancelled()) throw error;
                await delay(1000 * (2 ** attempt));
            }
        }
    }

    async function workers(indices, operation, isCancelled, concurrency = CONCURRENCY) {
        let cursor = 0;
        let error;
        await Promise.all(Array.from({ length: Math.min(concurrency, indices.length) }, async () => {
            while (!error && !isCancelled()) {
                const position = cursor++;
                if (position >= indices.length) return;
                try { await operation(indices[position]); }
                catch (e) { error = error || e; }
            }
        }));
        if (error) throw error;
        if (isCancelled()) throw Object.assign(new Error('已取消上传'), { cancelled: true });
    }

    function xhrRequest(url, body, csrf, active, progress, timeout = 120000) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            active.add(xhr);
            xhr.open('POST', url);
            xhr.timeout = timeout;
            xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
            xhr.setRequestHeader('Accept', 'application/json');
            const fail = (message, retryable, cancelled = false) => {
                active.delete(xhr);
                reject(Object.assign(new Error(message), { retryable, cancelled }));
            };
            xhr.upload.onprogress = event => {
                if (progress && event.lengthComputable) progress(event.loaded, event.total);
            };
            xhr.onload = () => {
                active.delete(xhr);
                if (xhr.status < 200 || xhr.status >= 300) {
                    const message = xhr.status === 413 ? 'HTTP 413：上传分片被代理或服务器大小限制拦截'
                        : xhr.status === 419 ? '登录令牌已过期，请刷新页面后重选文件续传'
                        : `HTTP ${xhr.status}：上传请求失败`;
                    return fail(message, [408, 429].includes(xhr.status) || xhr.status >= 500);
                }
                let response;
                try { response = JSON.parse(xhr.responseText); }
                catch (_) { return fail('服务器返回了非 JSON 内容，请检查代理和登录状态', false); }
                if (!response.status) return fail(response.message || '上传失败', false);
                resolve(response);
            };
            xhr.onerror = () => fail('网络连接中断，请点击重试继续上传', true);
            xhr.ontimeout = () => fail('上传请求超时，请点击重试继续上传', true);
            xhr.onabort = () => fail('已取消上传，可重选相同文件续传', false, true);
            xhr.send(body);
        });
    }

    async function fingerprint(file) {
        // Hash bounded samples, not a 512 MiB ArrayBuffer, to protect mobile browser memory.
        if (!root.crypto || !root.crypto.subtle) throw new Error('大文件续传需要 HTTPS 安全连接');
        const sampleSize = 1024 * 1024;
        const samples = await Promise.all([
            file.slice(0, sampleSize).arrayBuffer(),
            file.slice(Math.max(0, file.size - sampleSize)).arrayBuffer()
        ]);
        const bytes = new Uint8Array(samples[0].byteLength + samples[1].byteLength);
        bytes.set(new Uint8Array(samples[0]));
        bytes.set(new Uint8Array(samples[1]), samples[0].byteLength);
        const digest = await root.crypto.subtle.digest('SHA-256', bytes);
        return Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, '0')).join('')
            + ':' + file.size + ':' + file.lastModified;
    }

    function storageGet(key) { try { return root.localStorage.getItem(key); } catch (_) { return null; } }
    function storageSet(key, value) { try { root.localStorage.setItem(key, value); } catch (_) {} }
    function storageRemove(key) { try { root.localStorage.removeItem(key); } catch (_) {} }

    function create($picker, options) {
        // File concurrency is independent of the per-file part workers. Zero means unlimited.
        const configured = Number(options.fileConcurrency ?? 3);
        const limit = Number.isInteger(configured) && configured >= 0 ? (configured || Infinity) : 3;
        let activeFiles = 0;
        const pending = [];
        function drain() {
            while (activeFiles < limit && pending.length) {
                const job = pending.shift();
                activeFiles++;
                Promise.resolve().then(job.run).then(job.resolve, job.reject).finally(() => {
                    activeFiles--;
                    drain();
                });
            }
        }
        function schedule(run) {
            return new Promise((resolve, reject) => {
                pending.push({ run, resolve, reject });
                drain();
            });
        }
        return function attach(data) {
            const file = data.files[0];
            let cancelled = false;
            let running = false;
            const active = new Set();
            data.abort = function () {
                cancelled = true;
                active.forEach(xhr => xhr.abort());
            };
            data.submit = function () {
                if (running) return data._chunkPromise;
                const direct = Boolean(options.directUrl && options.useDirect && options.useDirect());
                running = true;
                cancelled = false;
                const run = async () => {
                    let key;
                    const request = (suffix, form, progress, timeout) => retry(
                        () => xhrRequest((direct ? options.directUrl : options.url) + suffix, form, options.csrf, active, progress, timeout),
                        () => cancelled);
                    const info = text => data.$preview.find('.upload-info').text(text);
                    const form = entries => {
                        const body = new FormData();
                        Object.entries(entries).forEach(([name, value]) => {
                            if (value !== undefined && value !== null && value !== '') body.append(name, value);
                        });
                        return body;
                    };
                    try {
                        if (cancelled) return;
                        data.$preview.find('[data-operate="upload"]').hide();
                        info('上传中…0%');
                        if (direct) {
                            const result = await root.LskyR2Direct.run(file, {
                                url: options.directUrl, request, form, strategy: options.strategy, info, active,
                                cancelled: () => cancelled,
                                progress: (percent) => {
                                    info(`上传中…${percent}%`);
                                    data.$preview.find('.upload-progress').css('width', percent + '%');
                                }
                            });
                            $picker.fileupload('option', 'done').call($picker[0], null, { ...data, result });
                            return;
                        }
                        const print = await fingerprint(file);
                        const strategy = options.strategy();
                        key = 'lsky-chunks-v1:' + options.url + ':' + strategy + ':' + file.name + ':' + print;
                        const metadata = { name: file.name, size: file.size, fingerprint: print, strategy_id: strategy };
                        const oldId = storageGet(key);
                        let start;
                        try { start = await request('', form({ ...metadata, id: oldId })); }
                        catch (e) {
                            if (!oldId || !e.message.includes('已过期')) throw e;
                            storageRemove(key);
                            start = await request('', form(metadata));
                        }
                        const state = start.data;
                        storageSet(key, state.id);
                        let result = state.result;
                        if (!result) {
                            const count = Math.ceil(file.size / CHUNK_SIZE);
                            const received = new Set(state.received);
                            const sent = Array.from({ length: count }, (_, index) => received.has(index)
                                ? Math.min(CHUNK_SIZE, file.size - index * CHUNK_SIZE) : 0);
                            const renderProgress = () => {
                                const percent = Math.min(99, Math.floor(sent.reduce((a, b) => a + b, 0) / file.size * 100));
                                info(`上传中…${percent}%`);
                                data.$preview.find('.upload-progress').css('width', percent + '%');
                            };
                            renderProgress();
                            await workers(Array.from({ length: count }, (_, i) => i).filter(i => !received.has(i)),
                                async index => {
                                    const offset = index * CHUNK_SIZE;
                                    const blob = file.slice(offset, Math.min(file.size, offset + CHUNK_SIZE));
                                    const body = form({ index });
                                    body.append('file', blob, 'chunk.bin');
                                    await request('/' + state.id + '/part', body, (loaded, total) => {
                                        sent[index] = Math.min(blob.size, Math.floor(blob.size * loaded / total));
                                        renderProgress();
                                    });
                                    sent[index] = blob.size;
                                    renderProgress();
                                }, () => cancelled);
                            info('上传中…99%');
                            // No browser timeout for server-side processing; retries return cached completion.
                            result = await request('/' + state.id + '/complete', form({}), null, 0);
                        }
                        storageRemove(key);
                        $picker.fileupload('option', 'done').call($picker[0], null, { ...data, result });
                    } catch (error) {
                        if (!cancelled) {
                            // Render before invoking plugin callbacks: a callback error must not leave a stale "confirming" state.
                            info(error.message || '上传失败，请重试');
                            data.$preview.find('[data-operate="upload"]').show();
                            const fail = $picker.fileupload('option', 'fail');
                            try {
                                if (typeof fail === 'function') fail.call($picker[0], null,
                                    { ...data, errorThrown: error.message, jqXHR: { status: 0, responseJSON: { message: error.message } } });
                            } catch (_) { /* The inline failure message and retry button remain available. */ }
                            info(error.message || '上传失败，请重试');
                        }
                    } finally { running = false; }
                };
                data._chunkPromise = schedule(run);
                return data._chunkPromise;
            };
        };
    }

    root.LskyChunkUpload = { create, retry, workers, CHUNK_SIZE, CONCURRENCY };
})(typeof window === 'undefined' ? globalThis : window);
