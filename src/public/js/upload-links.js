/* Readable URL labels and canonical clipboard values are kept separate. */
(function (root) {
    'use strict';
    const urlKinds = new Set(['url', 'thumbnail_url']);
    function canonical(value, kind) {
        value = String(value ?? '');
        if (kind === 'html') {
            const entities = { '&lt;': '<', '&gt;': '>', '&amp;': '&', '&quot;': '"', '&#039;': "'", '&#39;': "'" };
            return value.replace(/&(?:lt|gt|amp|quot|#039|#39);/g, entity => entities[entity]);
        }
        if (urlKinds.has(kind)) {
            try { const u = new URL(value); if (['http:', 'https:'].includes(u.protocol)) return u.href; } catch (_) {}
        }
        return value;
    }
    function display(value, kind) {
        if (!urlKinds.has(kind)) return value;
        try {
            // Preserve percent literals and reserved separators; only improve the label.
            return decodeURI(value.replace(/%25/gi, '%2525'))
                .replace(/[\u0000-\u001f\u007f-\u009f\u202a-\u202e\u2066-\u2069]/g, char => encodeURIComponent(char));
        } catch (_) { return value; }
    }
    function append(container, links) {
        const thumb = links.thumbnail_url ? String(links.thumbnail_url) : '';
        for (const [kind, input] of Object.entries(links)) {
            const panel = Array.from(container.querySelectorAll('[data-tab]')).find(el => el.getAttribute('data-tab') === kind);
            if (!panel) continue;
            const row = document.createElement('div'); row.className = 'flex items-center gap-2 mt-1 min-w-0';
            if (thumb) {
                try {
                    const u = new URL(thumb, root.location.href);
                    if (['http:', 'https:'].includes(u.protocol)) {
                        const image = document.createElement('img'); image.src = u.href; image.alt = '缩略图';
                        image.className = 'w-10 h-10 rounded object-cover bg-surface-2 shrink-0';
                        image.addEventListener('error', () => { image.style.display = 'none'; }); row.append(image);
                    }
                } catch (_) {}
            }
            const value = canonical(input, kind);
            const text = document.createElement('p');
            text.className = 'min-w-0 flex-1 truncate select-all bg-surface-2 hover:bg-surface-3 text-ink-2 rounded px-2 py-1 cursor-pointer';
            text.setAttribute('data-link-value', value); text.textContent = display(value, kind); text.title = text.textContent;
            row.append(text); panel.append(row);
        }
    }
    function copyAll(container, kind) {
        const panel = Array.from(container.querySelectorAll('[data-tab]')).find(el => el.getAttribute('data-tab') === kind);
        return panel ? Array.from(panel.querySelectorAll('p')).map(el => el.getAttribute('data-link-value') ?? el.textContent).join('\r\n') : '';
    }
    root.LskyUploadLinks = { canonical, display, append, copyAll };
})(window);
