export async function ollamaChatJson(opts: {
  baseUrl: string;
  model: string;
  system: string;
  user: string;
  timeoutMs?: number;
}): Promise<string> {
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

    const data = (await res.json()) as { message?: { content?: string } };
    const content = data?.message?.content;
    if (!content) throw new Error('Ollama response missing message.content');
    return content.trim();
  } catch (e: any) {
    if (e?.name === 'AbortError') {
      throw new Error(`Ollama request timed out after ${timeoutMs}ms for model ${opts.model}`);
    }
    throw e;
  } finally {
    clearTimeout(t);
  }
}
