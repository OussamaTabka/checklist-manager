import 'dotenv/config';
function readEnv(name, fallback) {
    const raw = process.env[name];
    if (raw == null) {
        return fallback;
    }
    const value = raw.trim();
    if (!value || value.toLowerCase() === 'undefined' || value.toLowerCase() === 'null') {
        return fallback;
    }
    return value;
}
function readEnvBoolean(name, fallback) {
    const raw = process.env[name];
    if (raw == null) {
        return fallback;
    }
    const value = raw.trim().toLowerCase();
    if (!value || value === 'undefined' || value === 'null') {
        return fallback;
    }
    if (['1', 'true', 'yes', 'on'].includes(value)) {
        return true;
    }
    if (['0', 'false', 'no', 'off'].includes(value)) {
        return false;
    }
    return fallback;
}
function readEnvInt(name, fallback) {
    const raw = process.env[name];
    if (raw == null) {
        return fallback;
    }
    const parsed = Number(raw);
    if (!Number.isFinite(parsed)) {
        return fallback;
    }
    return Math.max(0, Math.floor(parsed));
}
export const config = {
    ollamaBaseUrl: readEnv('OLLAMA_BASE_URL', 'http://localhost:11434'),
    ollamaModel: readEnv('OLLAMA_MODEL', 'qwen2.5:7b'),
    ollamaFallbackModel: readEnv('OLLAMA_FALLBACK_MODEL', 'llama3.2:3b'),
    generationEngine: readEnv('GENERATION_ENGINE', 'playwright-models'),
    runHeadless: readEnvBoolean('RUN_HEADLESS', false),
    runSlowMoMs: readEnvInt('RUN_SLOW_MO_MS', 300),
    runHoldOpenMs: readEnvInt('RUN_HOLD_OPEN_MS', 12000),
    ollamaTimeoutMs: Number(process.env.OLLAMA_TIMEOUT_MS ?? '180000'),
    ollamaMinConfidence: Math.min(1, Math.max(0, Number(process.env.OLLAMA_MIN_CONFIDENCE ?? '0.6'))),
    orchestratorDir: readEnv('ORCHESTRATOR_DIR', '../playwright-orchestrator'),
    targetBaseUrl: readEnv('TARGET_BASE_URL', 'http://host.docker.internal:5173'),
};
//# sourceMappingURL=config.js.map