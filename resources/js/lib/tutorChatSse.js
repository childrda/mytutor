/**
 * POST /api/chat and parse Server-Sent Events (data: JSON lines).
 * Event payloads: { type, data } — e.g. thinking, agent_start, text_delta,
 * agent_end, action, done, error.
 *
 * @param {object} options
 * @param {string} options.url
 * @param {object} options.body
 * @param {AbortSignal} [options.signal]
 * @param {(evt: { type: string, data?: unknown }) => void} options.onEvent
 */

/**
 * Read the XSRF-TOKEN cookie that Laravel sets on every page load.
 * window.axios reads this automatically via withXSRFToken=true.
 * Raw fetch() does not — it must be added manually.
 */
function getXsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export async function streamTutorChat({ url, body, signal, onEvent }) {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'text/event-stream',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': getXsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
        signal,
    });

    if (!res.ok) {
        const text = await res.text();
        try {
            const j = JSON.parse(text);
            const msg = j.error || j.message;
            throw new Error(typeof msg === 'string' && msg !== '' ? msg : text || res.statusText);
        } catch (e) {
            if (e instanceof SyntaxError) {
                throw new Error(text || res.statusText);
            }
            throw e;
        }
    }

    const reader = res.body?.getReader();
    if (!reader) {
        throw new Error('No response body');
    }

    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { done, value } = await reader.read();
        if (done) {
            break;
        }
        buffer += decoder.decode(value, { stream: true });

        let sep;
        while ((sep = buffer.indexOf('\n\n')) !== -1) {
            const block = buffer.slice(0, sep);
            buffer = buffer.slice(sep + 2);
            for (const line of block.split('\n')) {
                const trimmed = line.trim();
                if (trimmed === '' || trimmed.startsWith(':')) {
                    continue;
                }
                if (trimmed.startsWith('data:')) {
                    const json = trimmed.slice(5).trim();
                    if (json === '[DONE]') {
                        return;
                    }
                    try {
                        const evt = JSON.parse(json);
                        if (evt && typeof evt.type === 'string') {
                            onEvent(evt);
                        }
                    } catch {
                        /* ignore malformed chunk */
                    }
                }
            }
        }
    }
}
