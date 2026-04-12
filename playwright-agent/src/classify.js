import { z } from 'zod';
import { ollamaChatJson } from './ollamaClient.js';
const ScenarioSchema = z.object({
    scenario: z.enum(['dashboard', 'checklists', 'projects', 'users', 'login', 'unknown']),
    confidence: z.number().min(0).max(1).optional(),
});
function extractFirstJsonObject(text) {
    const start = text.indexOf('{');
    const end = text.lastIndexOf('}');
    if (start === -1 || end === -1 || end <= start)
        return null;
    return text.slice(start, end + 1);
}
export async function classifyItem(opts) {
    const system = [
        'You are a strict classifier.',
        'Return ONLY a single JSON object. No markdown. No explanations.',
        'Allowed scenarios: dashboard, checklists, projects, users, login, unknown.',
        'If you are not sure, return unknown.',
        'Use singular and plural synonyms equally.',
        'If text mixes multiple feature areas and no dominant intent is clear, return unknown.',
        'Map login/auth/session/sign-in/reset-password to login.',
        'Map checklist/checklists/items/test case catalog management to checklists.',
        'Map project/projects/version/release to projects.',
        'Map user/users/admin/roles/permissions/account management to users.',
        'Map dashboard/home/summary/stats/widgets to dashboard.',
        'If the item is mainly API/network/security validation (JSON schema, request/response, headers, CORS, rate limiting, status/error codes) and not a clear UI-page interaction, return unknown; exception: explicit login/authentication flow checks should map to login.',
    ].join('\n');
    const examples = [
        { text: 'Open projects list and validate release version is visible', scenario: 'projects' },
        { text: 'Verify users table and role permissions can be viewed', scenario: 'users' },
        { text: 'Check checklists page and checklist items ordering', scenario: 'checklists' },
        { text: 'Login with valid credentials and verify session', scenario: 'login' },
        { text: 'Open dashboard and verify summary widgets', scenario: 'dashboard' },
        { text: 'Validate project and user sections in one flow', scenario: 'unknown' },
    ];
    const user = JSON.stringify({
        title: opts.title,
        description: opts.description ?? '',
        context: opts.context ?? {},
        examples,
        task: 'Classify this test item into one scenario.',
        output: { scenario: 'dashboard|checklists|projects|users|login|unknown', confidence: 0.0 },
    }, null, 2);
    const raw = await ollamaChatJson({
        baseUrl: opts.baseUrl,
        model: opts.model,
        timeoutMs: opts.timeoutMs ?? 180_000,
        system,
        user,
    });
    const jsonText = extractFirstJsonObject(raw) ?? raw;
    let parsedJson;
    try {
        parsedJson = JSON.parse(jsonText);
    }
    catch {
        return { scenario: 'unknown', confidence: 0 };
    }
    const parsed = ScenarioSchema.safeParse(parsedJson);
    if (parsed.success) {
        const { scenario, confidence } = parsed.data;
        return confidence === undefined ? { scenario } : { scenario, confidence };
    }
    // hard fallback
    return { scenario: 'unknown', confidence: 0 };
}
//# sourceMappingURL=classify.js.map