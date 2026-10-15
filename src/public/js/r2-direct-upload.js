(function (root) {
    'use strict';
    const CHUNK_SIZE = 50 * 1024 * 1024;
    const CONCURRENCY = 6;
    const cancelledError = () => Object.assign(new Error('已取消直传，可重选相同文件续传'), { cancelled: true });
    const get = key => { try { return root.localStorage.getItem(key); } catch (_) { return null; } };
    const set = (key, value) => { try { root.localStorage.setItem(key, value); } catch (_) {} };
    const remove = key => { try { root.localStorage.removeItem(key); } catch (_) {} };

    async function hashes(file, cancelled, progress) {
        if (!root.hashwasm || !root.WebAssembly) throw new Error('浏览器不支持上传校验，请升级浏览器后重试');
        const [md5, sha1, partHash] = await Promise.all([
            root.hashwasm.createMD5(), root.hashwasm.createSHA1(), root.hashwasm.createMD5()
        ]);
        md5.init(); sha1.init(); partHash.init();
        const parts = [];
        let partBytes = 0;
        // Read one MiB at a time; never create six 50MiB ArrayBuffers on an iPhone.
        for (let offset = 0; offset < file.size;) {
            if (cancelled()) throw cancelledError();
            const end = Math.min(file.size, offset + 1024 * 1024, offset + CHUNK_SIZE - partBytes);
            const bytes = new Uint8Array(await file.slice(offset, end).arrayBuffer());
            md5.update(bytes); sha1.update(bytes); partHash.update(bytes);
            partBytes += bytes.byteLength;
            offset = end;
            if (partBytes === CHUNK_SIZE || offset === file.size) {
                parts.push({ size: partBytes, md5: partHash.digest('hex') });
                partHash.init(); partBytes = 0;
            }
            progress(Math.floor(offset / file.size * 100));
            // Yield to repaint and cancellation while scanning a large file.
            if (offset % (8 * 1024 * 1024) === 0) await new Promise(resolve => setTimeout(resolve, 0));
        }
        return { md5: md5.digest('hex'), sha1: sha1.digest('hex'), parts };
    }

    function put(signature, blob, active, cancelled, progress) {
        return new Promise((resolve, reject) => {
            if (cancelled()) return reject(cancelledError());
            const xhr = new XMLHttpRequest();
            active.add(xhr);
            xhr.open('PUT', signature.url);
            xhr.timeout = 180000;
            xhr.setRequestHeader('Content-MD5', signature.md5);
            const fail = (message, retryable, expired = false) => {
                active.delete(xhr);
                reject(Object.assign(new Error(message), { retryable, expired }));
            };
            xhr.upload.onprogress = e => { if (e.lengthComputable) progress(Math.min(blob.size, e.loaded)); };
            xhr.onload = () => {
                active.delete(xhr);
                if (xhr.status >= 200 && xhr.status < 300) return resolve();
                // Refresh a presigned URL instead of retrying the same expired signature.
                fail(`R2 HTTP ${xhr.status}：分片上传失败`, [408, 429].includes(xhr.status) || xhr.status >= 500, xhr.status === 403);
            };
            xhr.onerror = () => fail('无法连接图片存储，请检查网络或联系管理员检查跨域设置', true);
            xhr.ontimeout = () => fail('R2 分片上传超时，请重试续传', true);
            xhr.onabort = () => { active.delete(xhr); reject(cancelledError()); };
            // Deliberately omit site CSRF headers and cookies on cross-origin R2 PUTs.
            xhr.send(blob);
        });
    }

    async function run(file, options) {
        const { request, form, strategy, info, progress, active, cancelled } = options;
        info('上传中…0%');
        const digest = await hashes(file, cancelled, () => {});
        const strategyId = strategy();
        const key = 'lsky-r2-direct-v1:' + options.url + ':' + strategyId + ':' + file.name + ':' + file.size + ':' + digest.sha1;
        const oldId = get(key);
        const metadata = { name: file.name, size: file.size, md5: digest.md5, sha1: digest.sha1,
            strategy_id: strategyId, parts_json: JSON.stringify(digest.parts) };
        let response;
        try { response = await request('', form({ ...metadata, id: oldId })); }
        catch (e) {
            if (!oldId || !['已过期', '任务已取消或文件校验失败'].some(reason => e.message.includes(reason))) throw e;
            remove(key); response = await request('', form(metadata));
        }
        const task = response.data;
        set(key, task.id);
        if (task.result) { remove(key); return task.result; }
        if (!task.uploaded) {
            const received = new Set(task.received);
            const sent = digest.parts.map((part, i) => received.has(i) ? part.size : 0);
            const draw = () => progress(Math.min(99, Math.floor(sent.reduce((a, b) => a + b, 0) / file.size * 100)));
            draw();
            await root.LskyChunkUpload.workers(digest.parts.map((_, i) => i).filter(i => !received.has(i)), async index => {
                const blob = file.slice(index * CHUNK_SIZE, Math.min(file.size, (index + 1) * CHUNK_SIZE));
                let signature = task.parts[index];
                let refreshed = false;
                await root.LskyChunkUpload.retry(async () => {
                    try { await put(signature, blob, active, cancelled, loaded => { sent[index] = loaded; draw(); }); }
                    catch (error) {
                        if (error.expired && !refreshed) {
                            refreshed = true;
                            signature = (await request('/' + task.id + '/sign', form({ index }))).data;
                            await put(signature, blob, active, cancelled, loaded => { sent[index] = loaded; draw(); });
                        } else throw error;
                    }
                }, cancelled);
                sent[index] = blob.size; draw();
            }, cancelled, CONCURRENCY);
        }
        progress(99);
        info('上传中…99%');
        const result = await request('/' + task.id + '/complete', form({}), null, 0);
        remove(key);
        return result;
    }

    root.LskyR2Direct = { run, hashes, put, CHUNK_SIZE, CONCURRENCY };
})(typeof window === 'undefined' ? globalThis : window);
