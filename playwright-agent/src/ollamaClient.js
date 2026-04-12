export async function ollamaChatJson(opts) {
    const timeoutMs = opts.timeoutMs ?? 180_000;
    const controller = new AbortController();
    const t = setTimeout(() => controller.abort(), timeoutMs);
    try {
        const res = await fetch(`${opts.baseUrl}/api/chat`, {
            method: 'POST',
            headers: { 'content-type': 'application/json' },
            body: JSON.stringify({
                model: opts.model,
                stream: false,
                messages: [
                    { role: 'system', content: opts.system },
                    { role: 'user', content: opts.user },
                ],
            }),
            signal: controller.signal,
        });
        if (!res.ok) {
            const text = await res.text().catch(() => '');
            throw new Error(`Ollama /api/chat failed: ${res.status} ${res.statusText} ${text}`);
        }
        const data = (await res.json());
        const content = data?.message?.content;
        if (!content)
            throw new Error('Ollama response missing message.content');
        return content.trim();
    }
    catch (e) {
        if (e?.name === 'AbortError') {
            throw new Error(`Ollama request timed out after ${timeoutMs}ms for model ${opts.model}`);
        }
        throw e;
    }
    finally {
        clearTimeout(t);
    }
}
//# sourceMappingURL=ollamaClient.js.map