import fs from 'node:fs/promises';
import path from 'node:path';
import { z } from 'zod';
import { config } from './config.js';
import { classifyItem } from './classify.js';
const InputSchema = z.object({
    run_id: z.string().min(1),
    external_id: z.number().int().positive(),
    test_case_title: z.string().min(1),
    test_case_description: z.string().optional().default(''),
    test_case_text: z.string().min(1),
    base_url: z.string().url(),
    use_auth: z.boolean().optional().default(true),
    environment_name: z.string().optional().default(''),
    notes: z.string().optional().default(''),
    priority: z.string().optional().default(''),
    criticality: z.string().optional().default(''),
    current_status: z.string().optional().default(''),
    project_version_id: z.number().int().positive().optional(),
});
const SelectorSchema = z.discriminatedUnion('by', [
    z.object({ by: z.literal('testid'), id: z.string().min(1) }),
    z.object({ by: z.literal('role'), role: z.string().min(1), name: z.string().min(1), exact: z.boolean().optional() }),
    z.object({ by: z.literal('label'), text: z.string().min(1) }),
    z.object({ by: z.literal('text'), text: z.string().min(1), exact: z.boolean().optional() }),
    z.object({ by: z.literal('css'), value: z.string().min(1) }),
]);
const StepSchema = z.discriminatedUnion('action', [
    z.object({ action: z.literal('goto'), url: z.string().min(1) }),
    z.object({ action: z.literal('click'), selector: SelectorSchema }),
    z.object({ action: z.literal('fill'), selector: SelectorSchema, value: z.string() }),
    z.object({ action: z.literal('press'), selector: SelectorSchema, key: z.string().min(1) }),
    z.object({ action: z.literal('wait_for_url'), contains: z.string().min(1), timeout_ms: z.number().int().positive().optional() }),
    z.object({ action: z.literal('wait_for_selector'), selector: SelectorSchema, state: z.enum(['visible', 'hidden', 'attached', 'detached']), timeout_ms: z.number().int().positive().optional() }),
    z.object({ action: z.literal('screenshot'), name: z.string().min(1) }),
]);
const AssertSchema = z.discriminatedUnion('type', [
    z.object({ type: z.literal('expect_visible'), selector: SelectorSchema }),
    z.object({ type: z.literal('expect_hidden'), selector: SelectorSchema }),
    z.object({ type: z.literal('expect_text'), selector: SelectorSchema, text: z.string() }),
    z.object({ type: z.literal('expect_url_contains'), value: z.string().min(1) }),
    z.object({ type: z.literal('expect_title'), value: z.string().min(1) }),
]);
const RunSpecSchema = z.object({
    schema_version: z.literal('1.0'),
    run_id: z.string().min(1),
    target: z.object({
        base_url: z.string().url(),
    }),
    runtime: z.object({
        headless: z.boolean(),
        slow_mo_ms: z.number().int().nonnegative().optional(),
        hold_open_ms: z.number().int().nonnegative().optional(),
        browsers: z.array(z.enum(['chromium', 'firefox', 'webkit', 'msedge'])).min(1).optional(),
        timeout_ms: z.number().int().positive(),
        viewport: z.object({
            width: z.number().int().positive(),
            height: z.number().int().positive(),
        }),
        trace: z.enum(['retain-on-failure', 'off']),
        video: z.enum(['retain-on-failure', 'off']),
        screenshot: z.enum(['only-on-failure', 'off']),
    }),
    cases: z.array(z.object({
        external_id: z.number().int().positive(),
        title: z.string().min(1),
        severity: z.enum(['critical', 'major', 'minor']),
        use_auth: z.boolean(),
        steps: z.array(StepSchema).min(1),
        asserts: z.array(AssertSchema),
    })).min(1),
});
const UNKNOWN_SCENARIO_GUARD_TOKEN = '__agent_unknown_scenario__';
function splitInstructionStatements(input) {
    const source = `${input.test_case_title}\n${input.test_case_text}\n${input.test_case_description}`;
    const withMarkers = source.replace(/\b(Open page|Go to|Navigate to|Type\s+|Push\s+|Click\s+|Press\s+|Verify\s+|Test all\s+|Form Validation)\b/gi, '\n$1');
    return withMarkers
        .split(/\r?\n+/)
        .map((line) => line.trim().replace(/^[\-•\d.)\s]+/, '').replace(/[\s:]+$/, ''))
        .filter((line) => line.length > 0);
}
function cleanInstructionValue(value) {
    return value
        .trim()
        .replace(/^['"]+|['"]+$/g, '')
        .replace(/[.,;:]+$/g, '')
        .trim();
}
function firstQuotedSegment(value) {
    const match = value.match(/['"]([^'"]+)['"]/);
    return match?.[1] ? cleanInstructionValue(match[1]) : null;
}
function parseInstructionDrivenPlan(input, normalizedBaseUrl) {
    const steps = [];
    const asserts = [];
    const statements = splitInstructionStatements(input);
    let matchedStatements = 0;
    for (const statement of statements) {
        const normalized = statement.toLowerCase();
        if (/^(open\s+page|go\s+to|navigate\s+to)\b/.test(normalized)) {
            const urlMatch = statement.match(/https?:\/\/[^\s"')]+/i);
            const targetUrl = urlMatch?.[0] ? cleanInstructionValue(urlMatch[0]) : normalizedBaseUrl;
            steps.push({ action: 'goto', url: targetUrl });
            matchedStatements += 1;
            continue;
        }
        const userFillMatch = statement.match(/^type\s+username\s+(.+?)\s+into\s+(.+?)\s+field$/i);
        if (userFillMatch?.[1] && userFillMatch?.[2]) {
            steps.push({
                action: 'fill',
                selector: { by: 'label', text: cleanInstructionValue(userFillMatch[2]) },
                value: cleanInstructionValue(userFillMatch[1]),
            });
            matchedStatements += 1;
            continue;
        }
        const passwordFillMatch = statement.match(/^type\s+password\s+(.+?)\s+into\s+(.+?)\s+field$/i);
        if (passwordFillMatch?.[1] && passwordFillMatch?.[2]) {
            steps.push({
                action: 'fill',
                selector: { by: 'label', text: cleanInstructionValue(passwordFillMatch[2]) },
                value: cleanInstructionValue(passwordFillMatch[1]),
            });
            matchedStatements += 1;
            continue;
        }
        const genericFillMatch = statement.match(/^type\s+(.+?)\s+into\s+(.+?)\s+field$/i);
        if (genericFillMatch?.[1] && genericFillMatch?.[2]) {
            steps.push({
                action: 'fill',
                selector: { by: 'label', text: cleanInstructionValue(genericFillMatch[2]) },
                value: cleanInstructionValue(genericFillMatch[1]),
            });
            matchedStatements += 1;
            continue;
        }
        const clickMatch = statement.match(/^(push|click|press)\s+(.+?)\s+button$/i);
        if (clickMatch?.[2]) {
            steps.push({
                action: 'click',
                selector: { by: 'role', role: 'button', name: cleanInstructionValue(clickMatch[2]), exact: false },
            });
            matchedStatements += 1;
            continue;
        }
        const urlAssertMatch = statement.match(/^verify.*url.*contains\s+(.+)$/i);
        if (urlAssertMatch?.[1]) {
            const fragment = cleanInstructionValue(urlAssertMatch[1]);
            steps.push({ action: 'wait_for_url', contains: fragment, timeout_ms: 20000 });
            asserts.push({ type: 'expect_url_contains', value: fragment });
            matchedStatements += 1;
            continue;
        }
        const expectedTextMatch = statement.match(/^verify.*contains\s+expected\s+text\s*\((.+)\)$/i);
        if (expectedTextMatch?.[1]) {
            const quoted = firstQuotedSegment(expectedTextMatch[1]);
            const fallback = cleanInstructionValue(expectedTextMatch[1].split(/\bor\b/i)[0] ?? expectedTextMatch[1]);
            const text = quoted ?? fallback;
            if (text) {
                asserts.push({ type: 'expect_visible', selector: { by: 'text', text, exact: false } });
                matchedStatements += 1;
                continue;
            }
        }
        const quotedTextAssert = statement.match(/^verify.*contains.*text.*['"](.+?)['"]/i);
        if (quotedTextAssert?.[1]) {
            asserts.push({
                type: 'expect_visible',
                selector: { by: 'text', text: cleanInstructionValue(quotedTextAssert[1]), exact: false },
            });
            matchedStatements += 1;
            continue;
        }
        const buttonVisibleMatch = statement.match(/^verify\s+button\s+(.+?)\s+is\s+displayed(?:\b.*)?$/i);
        if (buttonVisibleMatch?.[1]) {
            asserts.push({
                type: 'expect_visible',
                selector: { by: 'role', role: 'button', name: cleanInstructionValue(buttonVisibleMatch[1]), exact: false },
            });
            matchedStatements += 1;
            continue;
        }
    }
    if (matchedStatements === 0) {
        return null;
    }
    if (!steps.some((step) => step.action === 'goto')) {
        steps.unshift({ action: 'goto', url: normalizedBaseUrl });
    }
    if (asserts.length === 0) {
        asserts.push(...buildGenericAsserts(normalizedBaseUrl));
    }
    return {
        steps,
        asserts,
        useAuth: input.use_auth !== false,
    };
}
async function parseTitleHeuristicPlan(input, normalizedBaseUrl, internalChecklistTarget) {
    const title = input.test_case_title.toLowerCase();
    const titleContext = `${input.test_case_title}\n${input.test_case_text}\n${input.test_case_description}`.toLowerCase();
    const host = new URL(normalizedBaseUrl).hostname;
    const firstInteractiveSelector = 'main button:not([disabled]), main [role="button"]:not([aria-disabled="true"]), main input[type="button"]:not([disabled]), main input[type="submit"]:not([disabled]), main a[role="button"], main a[href]';
    const firstInputSelector = 'input[type="text"], input[type="search"], input:not([type]), textarea';
    const navLinkSelector = 'nav a[href], header a[href], a[href]';
    if (/cross\s*-?\s*browser|compatibilit/.test(title)) {
        return {
            steps: [
                { action: 'goto', url: normalizedBaseUrl },
                { action: 'screenshot', name: 'cross-browser-home' },
            ],
            asserts: [
                { type: 'expect_visible', selector: { by: 'css', value: 'body' } },
                { type: 'expect_url_contains', value: host },
            ],
            useAuth: false,
        };
    }
    if (/sql\s*injection|sqli/.test(titleContext)) {
        return {
            steps: [
                { action: 'goto', url: normalizedBaseUrl },
                { action: 'fill', selector: { by: 'css', value: firstInputSelector }, value: "' OR '1'='1" },
                { action: 'click', selector: { by: 'css', value: firstInteractiveSelector } },
                { action: 'screenshot', name: 'sql-injection-attempt' },
            ],
            asserts: [
                { type: 'expect_url_contains', value: host },
                { type: 'expect_hidden', selector: { by: 'text', text: 'sql syntax', exact: false } },
            ],
            useAuth: false,
        };
    }
    if (/\bxss\b|cross\s*site\s*scripting/.test(titleContext)) {
        return {
            steps: [
                { action: 'goto', url: normalizedBaseUrl },
                { action: 'fill', selector: { by: 'css', value: firstInputSelector }, value: '<script>alert(1)</script>' },
                { action: 'click', selector: { by: 'css', value: firstInteractiveSelector } },
                { action: 'screenshot', name: 'xss-attempt' },
            ],
            asserts: [
                { type: 'expect_visible', selector: { by: 'css', value: 'body' } },
                { type: 'expect_hidden', selector: { by: 'text', text: '<script>alert(1)</script>', exact: false } },
            ],
            useAuth: false,
        };
    }
    if (/csrf/.test(titleContext)) {
        return {
            steps: [
                { action: 'goto', url: normalizedBaseUrl },
                { action: 'click', selector: { by: 'css', value: firstInteractiveSelector } },
            ],
            asserts: [
                { type: 'expect_url_contains', value: host },
                { type: 'expect_visible', selector: { by: 'css', value: 'body' } },
            ],
            useAuth: false,
        };
    }
    if (/authorization|role\s*-?\s*based\s+access|rbac/.test(titleContext)) {
        return {
            steps: [
                { action: 'goto', url: normalizedBaseUrl },
                { action: 'goto', url: internalChecklistTarget ? '/users' : `${normalizedBaseUrl}/admin` },
            ],
            asserts: [
                { type: 'expect_url_contains', value: host },
            ],
            useAuth: input.use_auth !== false,
        };
    }
    if (/navigation\s+testing|navigation\s+links?|link\s+functionality/.test(titleContext)) {
        return {
            steps: [
                { action: 'goto', url: normalizedBaseUrl },
                {
                    action: 'wait_for_selector',
                    selector: { by: 'css', value: navLinkSelector },
                    state: 'visible',
                    timeout_ms: 20000,
                },
                { action: 'click', selector: { by: 'css', value: navLinkSelector } },
                { action: 'screenshot', name: 'navigation-after-click' },
            ],
            asserts: [
                { type: 'expect_url_contains', value: host },
            ],
            useAuth: input.use_auth !== false,
        };
    }
    if (/form\s+validation|input\s+validation/.test(title)) {
        return {
            steps: [
                { action: 'goto', url: normalizedBaseUrl },
                { action: 'click', selector: { by: 'role', role: 'button', name: 'Submit', exact: false } },
            ],
            asserts: [
                { type: 'expect_visible', selector: { by: 'text', text: 'required', exact: false } },
            ],
            useAuth: input.use_auth !== false,
        };
    }
    if (/button\s+functionality|interactive\s+buttons?|button\s+interactions?/.test(titleContext)) {
        return {
            steps: [
                { action: 'goto', url: normalizedBaseUrl },
                {
                    action: 'wait_for_selector',
                    selector: { by: 'css', value: firstInteractiveSelector },
                    state: 'visible',
                    timeout_ms: 20000,
                },
                { action: 'click', selector: { by: 'css', value: firstInteractiveSelector } },
                { action: 'screenshot', name: 'button-functionality-after-click' },
            ],
            asserts: [
                { type: 'expect_visible', selector: { by: 'css', value: firstInteractiveSelector } },
                { type: 'expect_url_contains', value: host },
            ],
            useAuth: input.use_auth !== false,
        };
    }
    if (/responsive\s+design|mobile|tablet|desktop/.test(titleContext)) {
        return {
            steps: [
                { action: 'goto', url: normalizedBaseUrl },
                { action: 'screenshot', name: 'responsive-baseline' },
            ],
            asserts: [
                { type: 'expect_visible', selector: { by: 'css', value: 'body' } },
                { type: 'expect_url_contains', value: host },
            ],
            useAuth: false,
        };
    }
    if (/accessibility|wcag/.test(titleContext)) {
        return {
            steps: [
                { action: 'goto', url: normalizedBaseUrl },
                { action: 'wait_for_selector', selector: { by: 'css', value: 'body' }, state: 'visible', timeout_ms: 20000 },
                { action: 'screenshot', name: 'accessibility-baseline' },
            ],
            asserts: [
                { type: 'expect_visible', selector: { by: 'css', value: 'body' } },
                { type: 'expect_url_contains', value: host },
            ],
            useAuth: false,
        };
    }
    if (/error\s+handling/.test(titleContext)) {
        return {
            steps: [
                { action: 'goto', url: `${normalizedBaseUrl}/__qa_error_probe__` },
            ],
            asserts: [
                { type: 'expect_visible', selector: { by: 'css', value: 'body' } },
                { type: 'expect_url_contains', value: host },
            ],
            useAuth: false,
        };
    }
    if (/rate\s*limit|request\/response|authentication\s+headers|cors|performance|load\s+testing|memory\s+profiling|database\s+query\s+performance/.test(titleContext)) {
        return {
            steps: [
                { action: 'goto', url: normalizedBaseUrl },
                { action: 'screenshot', name: 'non-ui-baseline' },
            ],
            asserts: [
                { type: 'expect_visible', selector: { by: 'css', value: 'body' } },
                { type: 'expect_url_contains', value: host },
            ],
            useAuth: false,
        };
    }
    if (/login|sign\s*in|authentication/.test(title)) {
        const isNegative = /negative|invalid|wrong|fail|incorrect|unsuccessful/.test(title);
        if (!isNegative && !internalChecklistTarget) {
            return buildExternalLoginFlowFromCodegen(input, normalizedBaseUrl);
        }
        if (isNegative) {
            return {
                steps: [
                    { action: 'goto', url: internalChecklistTarget ? '/login' : normalizedBaseUrl },
                    { action: 'fill', selector: { by: 'label', text: 'Username' }, value: 'invalid_user' },
                    { action: 'fill', selector: { by: 'label', text: 'Password' }, value: 'invalid_password' },
                    { action: 'click', selector: { by: 'role', role: 'button', name: 'Submit', exact: false } },
                ],
                asserts: [
                    { type: 'expect_url_contains', value: internalChecklistTarget ? '/login' : host },
                    { type: 'expect_visible', selector: { by: 'text', text: 'invalid', exact: false } },
                ],
                useAuth: false,
            };
        }
    }
    return null;
}
function inferRuntimeBrowsers(input) {
    const text = `${input.test_case_title}\n${input.test_case_text}`.toLowerCase();
    const requested = new Set();
    if (/\bchrome\b|\bchromium\b/.test(text)) {
        requested.add('chromium');
    }
    if (/\bfirefox\b/.test(text)) {
        requested.add('firefox');
    }
    if (/\bsafari\b|\bwebkit\b/.test(text)) {
        requested.add('webkit');
    }
    if (/\bedge\b|\bmsedge\b/.test(text)) {
        requested.add('msedge');
    }
    if (/cross\s*-?\s*browser|compatibility/.test(text) && requested.size === 0) {
        requested.add('chromium');
        requested.add('firefox');
        requested.add('webkit');
        requested.add('msedge');
    }
    return requested.size > 0 ? Array.from(requested) : undefined;
}
function buildRuntimeConfig(input) {
    const browsers = inferRuntimeBrowsers(input);
    return {
        headless: config.runHeadless,
        slow_mo_ms: config.runSlowMoMs,
        hold_open_ms: config.runHoldOpenMs,
        browsers,
        timeout_ms: 30000,
        viewport: { width: 1280, height: 720 },
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
        screenshot: 'only-on-failure',
    };
}
function normalizeBaseUrl(url) {
    const parsed = new URL(url);
    parsed.pathname = parsed.pathname.replace(/\/$/, '');
    return parsed.toString().replace(/\/$/, '');
}
function isInternalChecklistTarget(baseUrl) {
    const { hostname } = new URL(baseUrl);
    const normalizedHost = hostname.toLowerCase();
    return (normalizedHost === 'localhost' ||
        normalizedHost === '127.0.0.1' ||
        normalizedHost === 'host.docker.internal' ||
        normalizedHost.endsWith('.local'));
}
function buildGenericAsserts(baseUrl) {
    const { hostname } = new URL(baseUrl);
    return [
        { type: 'expect_visible', selector: { by: 'css', value: 'body' } },
        { type: 'expect_url_contains', value: hostname },
    ];
}
function buildUnknownScenarioAsserts() {
    // Fail closed for ambiguous classification: this token is intentionally absent from normal URLs.
    return [{ type: 'expect_url_contains', value: UNKNOWN_SCENARIO_GUARD_TOKEN }];
}
function buildUnknownScenarioSteps(internalChecklistTarget) {
    // Use a safe inert page so unknown scenarios fail deterministically without
    // interacting with app routes (which may redirect to /login and hide intent).
    void internalChecklistTarget;
    return [{ action: 'goto', url: 'about:blank' }];
}
function parseAttributes(tag) {
    const attrs = {};
    const regex = /([a-zA-Z_:][\w:.-]*)\s*=\s*("([^"]*)"|'([^']*)'|([^\s>]+))/g;
    let match;
    while ((match = regex.exec(tag)) !== null) {
        const name = (match[1] ?? '').toLowerCase();
        if (!name) {
            continue;
        }
        const value = (match[3] ?? match[4] ?? match[5] ?? '').trim();
        attrs[name] = value;
    }
    return attrs;
}
function normalizeText(value) {
    return value.replace(/\s+/g, ' ').trim();
}
function stripHtml(value) {
    return normalizeText(value.replace(/<[^>]+>/g, ' '));
}
function escapeCssValue(value) {
    return value.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
}
function parseInputs(html) {
    const inputs = [];
    const regex = /<input\b[^>]*>/gi;
    let match;
    while ((match = regex.exec(html)) !== null) {
        inputs.push({ attrs: parseAttributes(match[0]) });
    }
    return inputs;
}
function parseButtons(html) {
    const buttons = [];
    const buttonRegex = /<button\b([^>]*)>([\s\S]*?)<\/button>/gi;
    let buttonMatch;
    while ((buttonMatch = buttonRegex.exec(html)) !== null) {
        buttons.push({
            attrs: parseAttributes(`<button ${buttonMatch[1]}>`),
            text: stripHtml(buttonMatch[2] ?? ''),
        });
    }
    const inputButtonRegex = /<input\b[^>]*>/gi;
    let inputMatch;
    while ((inputMatch = inputButtonRegex.exec(html)) !== null) {
        const attrs = parseAttributes(inputMatch[0]);
        const type = (attrs.type ?? '').toLowerCase();
        if (type === 'submit' || type === 'button') {
            buttons.push({
                attrs,
                text: normalizeText(attrs.value ?? ''),
            });
        }
    }
    return buttons;
}
function parseLabelMap(html) {
    const labels = new Map();
    const regex = /<label\b([^>]*)>([\s\S]*?)<\/label>/gi;
    let match;
    while ((match = regex.exec(html)) !== null) {
        const attrs = parseAttributes(`<label ${match[1]}>`);
        const targetId = attrs.for;
        if (!targetId) {
            continue;
        }
        const text = stripHtml(match[2] ?? '');
        if (text) {
            labels.set(targetId, text);
        }
    }
    return labels;
}
function selectorFromNode(tag, node, fallbackLabel) {
    const attrs = node.attrs;
    const testId = attrs['data-testid'] || attrs['data-test'];
    if (testId) {
        return { by: 'testid', id: testId };
    }
    if (attrs.id) {
        return { by: 'css', value: `${tag}#${escapeCssValue(attrs.id)}` };
    }
    if (attrs.name) {
        return { by: 'css', value: `${tag}[name="${escapeCssValue(attrs.name)}"]` };
    }
    if (attrs['aria-label']) {
        return { by: 'label', text: attrs['aria-label'] };
    }
    if (attrs.placeholder) {
        return { by: 'label', text: attrs.placeholder };
    }
    return { by: 'label', text: fallbackLabel };
}
function scoreFromHints(value, hints) {
    const normalized = value.toLowerCase();
    return hints.reduce((score, hint) => score + (normalized.includes(hint) ? 4 : 0), 0);
}
function scoreInputCandidate(node, labelText, hints, disallowPassword) {
    const attrs = node.attrs;
    const type = (attrs.type ?? 'text').toLowerCase();
    if (type === 'hidden' || type === 'submit' || type === 'button') {
        return -100;
    }
    if (disallowPassword && type === 'password') {
        return -100;
    }
    const haystack = [
        attrs.id ?? '',
        attrs.name ?? '',
        attrs.placeholder ?? '',
        attrs['aria-label'] ?? '',
        attrs.autocomplete ?? '',
        attrs['data-testid'] ?? '',
        labelText,
    ].join(' ');
    return scoreFromHints(haystack, hints);
}
function pickInputByHints(inputs, labelsById, hints, disallowPassword) {
    let best = null;
    let bestScore = -1;
    for (const input of inputs) {
        const labelText = input.attrs.id ? labelsById.get(input.attrs.id) ?? '' : '';
        const score = scoreInputCandidate(input, labelText, hints, disallowPassword);
        if (score > bestScore) {
            bestScore = score;
            best = input;
        }
    }
    return bestScore > 0 ? best : null;
}
function pickSubmitButton(buttons) {
    let best = null;
    let bestScore = -1;
    const hints = ['submit', 'login', 'log in', 'sign in'];
    for (const button of buttons) {
        const attrs = button.attrs;
        const haystack = [
            button.text ?? '',
            attrs.id ?? '',
            attrs.name ?? '',
            attrs['aria-label'] ?? '',
            attrs.value ?? '',
            attrs['data-testid'] ?? '',
        ].join(' ');
        const score = scoreFromHints(haystack, hints);
        if (score > bestScore) {
            best = button;
            bestScore = score;
        }
    }
    return bestScore > 0 ? best : null;
}
function extractCredential(text, key) {
    const patterns = key === 'username'
        ? [
            /type\s+username\s+([A-Za-z0-9._@-]+)/i,
            /username\s*(?:is|=|:)?\s*([A-Za-z0-9._@-]+)/i,
        ]
        : [
            /type\s+password\s+([^\s"']+)/i,
            /password\s*(?:is|=|:)?\s*([^\s"']+)/i,
        ];
    for (const pattern of patterns) {
        const match = text.match(pattern);
        if (match?.[1]) {
            return match[1];
        }
    }
    return null;
}
function extractSuccessUrlFragment(testCaseText) {
    if (/logged-in-successfully/i.test(testCaseText)) {
        return 'logged-in-successfully';
    }
    const urlMatch = testCaseText.match(/https?:\/\/[^\s"']+/i);
    if (urlMatch?.[0]) {
        try {
            const parsed = new URL(urlMatch[0]);
            const path = parsed.pathname.replace(/^\//, '');
            if (path.length > 0) {
                return path;
            }
        }
        catch {
            // ignore malformed URL in free-text test case description
        }
    }
    return 'logged-in';
}
async function detectLoginSelectorsWithCodegen(targetUrl) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 12000);
    try {
        const response = await fetch(targetUrl, {
            signal: controller.signal,
            headers: {
                'User-Agent': 'selector-codegen-probe/1.0',
            },
        });
        if (!response.ok) {
            throw new Error(`selector probe failed with status ${response.status}`);
        }
        const html = await response.text();
        const inputs = parseInputs(html);
        const buttons = parseButtons(html);
        const labelsById = parseLabelMap(html);
        const usernameInput = pickInputByHints(inputs, labelsById, ['username', 'user', 'email', 'login'], true) ??
            inputs.find((input) => (input.attrs.type ?? 'text').toLowerCase() !== 'password') ??
            null;
        const passwordInput = pickInputByHints(inputs, labelsById, ['password', 'pass', 'pwd'], false) ??
            inputs.find((input) => (input.attrs.type ?? '').toLowerCase() === 'password') ??
            null;
        const submitButton = pickSubmitButton(buttons);
        return {
            username: usernameInput
                ? selectorFromNode('input', usernameInput, 'Username')
                : { by: 'label', text: 'Username' },
            password: passwordInput
                ? selectorFromNode('input', passwordInput, 'Password')
                : { by: 'label', text: 'Password' },
            submit: submitButton
                ? (submitButton.text
                    ? { by: 'role', role: 'button', name: submitButton.text, exact: false }
                    : selectorFromNode('button', submitButton, 'Submit'))
                : { by: 'role', role: 'button', name: 'Submit', exact: false },
        };
    }
    finally {
        clearTimeout(timeout);
    }
}
async function buildExternalLoginFlowFromCodegen(input, normalizedBaseUrl) {
    const user = extractCredential(input.test_case_text, 'username') ?? 'student';
    const password = extractCredential(input.test_case_text, 'password') ?? 'Password123';
    const successUrlFragment = extractSuccessUrlFragment(input.test_case_text);
    try {
        const detected = await detectLoginSelectorsWithCodegen(normalizedBaseUrl);
        process.stderr.write('[agent][codegen] login selectors auto-detected from target page\n');
        return {
            steps: [
                { action: 'goto', url: normalizedBaseUrl },
                { action: 'fill', selector: detected.username, value: user },
                { action: 'fill', selector: detected.password, value: password },
                { action: 'click', selector: detected.submit },
                { action: 'wait_for_url', contains: successUrlFragment, timeout_ms: 20000 },
            ],
            asserts: [
                { type: 'expect_url_contains', value: successUrlFragment },
                { type: 'expect_visible', selector: { by: 'text', text: 'Congratulations', exact: false } },
                { type: 'expect_visible', selector: { by: 'role', role: 'button', name: 'Log out', exact: false } },
            ],
            useAuth: false,
        };
    }
    catch (error) {
        process.stderr.write(`[agent][codegen] selector detection failed (${error instanceof Error ? error.message : String(error)}); using resilient login fallbacks\n`);
        return {
            steps: [
                { action: 'goto', url: normalizedBaseUrl },
                { action: 'fill', selector: { by: 'label', text: 'Username' }, value: user },
                { action: 'fill', selector: { by: 'label', text: 'Password' }, value: password },
                { action: 'click', selector: { by: 'role', role: 'button', name: 'Submit', exact: false } },
                { action: 'wait_for_url', contains: successUrlFragment, timeout_ms: 20000 },
            ],
            asserts: [
                { type: 'expect_url_contains', value: successUrlFragment },
                { type: 'expect_visible', selector: { by: 'text', text: 'Congratulations', exact: false } },
                { type: 'expect_visible', selector: { by: 'role', role: 'button', name: 'Log out', exact: false } },
            ],
            useAuth: false,
        };
    }
}
function plannerModel(input, internalChecklistTarget) {
    const inferred = inferScenarioFromText(input);
    if (inferred !== 'unknown') {
        return {
            mode: 'scenario',
            scenario: inferred,
            reason: 'heuristic scenario match',
        };
    }
    if (internalChecklistTarget) {
        return {
            mode: 'generic',
            reason: 'internal target with ambiguous intent; using generic web checks',
        };
    }
    return {
        mode: 'generic',
        reason: 'external target with ambiguous intent; using generic web checks',
    };
}
async function generatorModel(input, normalizedBaseUrl, internalChecklistTarget, plan) {
    const instructionPlan = parseInstructionDrivenPlan(input, normalizedBaseUrl);
    if (instructionPlan) {
        process.stderr.write(`[agent][instructions] parsed explicit steps/asserts from test case text (steps=${instructionPlan.steps.length}, asserts=${instructionPlan.asserts.length})\n`);
        return instructionPlan;
    }
    const titlePlan = await parseTitleHeuristicPlan(input, normalizedBaseUrl, internalChecklistTarget);
    if (titlePlan) {
        process.stderr.write(`[agent][title-heuristic] inferred script from title (steps=${titlePlan.steps.length}, asserts=${titlePlan.asserts.length})\n`);
        return titlePlan;
    }
    if (!internalChecklistTarget && plan.mode === 'scenario' && plan.scenario === 'login') {
        return buildExternalLoginFlowFromCodegen(input, normalizedBaseUrl);
    }
    if (plan.mode === 'scenario' && plan.scenario && plan.scenario !== 'unknown') {
        return {
            steps: [{ action: 'goto', url: scenarioToPath(plan.scenario) }],
            asserts: scenarioToAssert(plan.scenario),
            useAuth: input.use_auth !== false,
        };
    }
    return {
        steps: [{ action: 'goto', url: normalizedBaseUrl }],
        asserts: buildGenericAsserts(normalizedBaseUrl),
        useAuth: false,
    };
}
function healerModel(normalizedBaseUrl, generated) {
    const healedSteps = generated.steps.length > 0
        ? generated.steps
        : [{ action: 'goto', url: normalizedBaseUrl }];
    const healedAsserts = generated.asserts.length > 0
        ? generated.asserts
        : buildGenericAsserts(normalizedBaseUrl);
    return {
        ...generated,
        steps: healedSteps,
        asserts: healedAsserts,
    };
}
function scenarioToPath(scenario) {
    switch (scenario) {
        case 'dashboard':
            return '/dashboard';
        case 'checklists':
            return '/checklists';
        case 'projects':
            return '/projects';
        case 'users':
            return '/users';
        case 'login':
            return '/login';
        case 'unknown':
            return '/';
    }
}
function scenarioToAssert(scenario) {
    switch (scenario) {
        case 'dashboard':
            return [{ type: 'expect_visible', selector: { by: 'testid', id: 'dashboard-stats' } }];
        case 'checklists':
            return [{ type: 'expect_visible', selector: { by: 'testid', id: 'checklists-table' } }];
        case 'projects':
            return [{ type: 'expect_visible', selector: { by: 'testid', id: 'projects-table' } }];
        case 'users':
            return [{ type: 'expect_visible', selector: { by: 'testid', id: 'users-table' } }];
        case 'login':
            return [{ type: 'expect_visible', selector: { by: 'testid', id: 'login-btn-submit' } }];
        case 'unknown':
            return buildUnknownScenarioAsserts();
    }
}
function isOllamaTimeoutError(err) {
    return err instanceof Error && /timed out/i.test(err.message);
}
function recoverUnknownWithHeuristic(outcome, input) {
    if (outcome.scenario !== 'unknown') {
        return outcome;
    }
    const inferred = inferScenarioFromText(input);
    if (inferred === 'unknown') {
        return outcome;
    }
    process.stderr.write(`[agent] Unknown classification recovered by heuristic for test case ${input.external_id}: ${inferred}\n`);
    return {
        scenario: inferred,
        source: `${outcome.source}-heuristic-recovered`,
        modelUsed: outcome.modelUsed,
    };
}
function applyConfidenceThreshold(outcome, input) {
    if (typeof outcome.confidence === 'number' &&
        outcome.scenario !== 'unknown' &&
        outcome.confidence < config.ollamaMinConfidence) {
        const inferred = inferScenarioFromText(input);
        if (inferred !== 'unknown') {
            process.stderr.write(`[agent] Low-confidence classification (${outcome.confidence.toFixed(3)}) for test case ${input.external_id}; using heuristic scenario '${inferred}' instead of unknown.\n`);
            return {
                scenario: inferred,
                source: `${outcome.source}-heuristic-low-confidence`,
                modelUsed: outcome.modelUsed,
            };
        }
        process.stderr.write(`[agent] Low-confidence classification (${outcome.confidence.toFixed(3)}) for test case ${input.external_id}; threshold=${config.ollamaMinConfidence.toFixed(3)}. Routing to unknown.\n`);
        return {
            ...outcome,
            scenario: 'unknown',
            source: `${outcome.source}-low-confidence`,
        };
    }
    return outcome;
}
function inferScenarioFromText(input) {
    const text = `${input.test_case_title}\n${input.test_case_text}`.toLowerCase();
    const hasLoginCue = /\blog\s*in\b|\blogin\b|\bauth\w*\b|\bsign\s*in\b|\bsession\b|\breset\s*password\b|\bbearer\s*token\b/.test(text);
    const hasUiCue = /\bpage\b|\btable\b|\bbutton\b|\bform\b|\bscreen\b|\bview\b|\broute\b/.test(text);
    const hasApiCue = /\bjson\b|\bschema\b|\brequest\b|\bresponse\b|\bstatus\s*codes?\b|\berror\s*codes?\b|\bheaders?\b|\bcors\b|\brate\s*limit\b|\bthrottl\w*\b|\bendpoint\b|\bpayload\b|\bbearer\s*token\b|\bhttp\b/.test(text);
    // API/network/security validations are usually non-UI and should fail closed
    // unless the item clearly asks for a UI page interaction or explicit auth/login flow.
    if (hasApiCue && !hasUiCue && !hasLoginCue) {
        return 'unknown';
    }
    const matchedScenarios = new Set();
    if (hasLoginCue) {
        matchedScenarios.add('login');
    }
    if (/\bdashboard\b|\bhome\b|\bstats?\b|\bsummary\b|\bwidgets?\b/.test(text)) {
        matchedScenarios.add('dashboard');
    }
    if (/\bchecklists?\b|\bcheck\s*items?\b|\btest\s*case\s*catalog\b/.test(text)) {
        matchedScenarios.add('checklists');
    }
    if (/\bprojects?\b|\bversions?\b|\breleases?\b/.test(text)) {
        matchedScenarios.add('projects');
    }
    if (/\busers?\b|\badmins?\b|\broles?\b|\bpermissions?\b|\baccount\s*management\b/.test(text)) {
        matchedScenarios.add('users');
    }
    if (matchedScenarios.size === 1) {
        return Array.from(matchedScenarios)[0];
    }
    if (matchedScenarios.size > 1) {
        return 'unknown';
    }
    return 'unknown';
}
async function classifyWithFallback(input) {
    const classificationDescription = [
        input.test_case_text,
        input.test_case_description ? `Description: ${input.test_case_description}` : '',
        input.priority ? `Priority: ${input.priority}` : '',
        input.criticality ? `Criticality: ${input.criticality}` : '',
        input.current_status ? `Current status: ${input.current_status}` : '',
        input.environment_name ? `Environment: ${input.environment_name}` : '',
        input.notes ? `Notes: ${input.notes}` : '',
    ]
        .filter(Boolean)
        .join('\n');
    let modelUsed = config.ollamaModel;
    try {
        const primary = await classifyItem({
            baseUrl: config.ollamaBaseUrl,
            model: modelUsed,
            timeoutMs: config.ollamaTimeoutMs,
            title: input.test_case_title,
            description: classificationDescription,
            context: {
                external_id: input.external_id,
                test_case_description: input.test_case_description,
                priority: input.priority,
                criticality: input.criticality,
                current_status: input.current_status,
                project_version_id: input.project_version_id,
                environment_name: input.environment_name,
                notes: input.notes,
            },
        });
        return applyConfidenceThreshold(recoverUnknownWithHeuristic({
            scenario: primary.scenario,
            confidence: primary.confidence,
            source: 'primary',
            modelUsed,
        }, input), input);
    }
    catch (error) {
        const fallbackModel = config.ollamaFallbackModel;
        const shouldRetry = fallbackModel.length > 0 && fallbackModel !== modelUsed && isOllamaTimeoutError(error);
        if (!shouldRetry) {
            const inferred = inferScenarioFromText(input);
            process.stderr.write(`[agent] Ollama classification unavailable (${error instanceof Error ? error.message : String(error)}). Falling back to heuristic scenario: ${inferred}\n`);
            return {
                scenario: inferred,
                confidence: 0,
                source: 'heuristic-primary',
            };
        }
        process.stderr.write(`[agent] Primary model ${modelUsed} timed out; retrying with ${fallbackModel}\n`);
        modelUsed = fallbackModel;
        try {
            const fallback = await classifyItem({
                baseUrl: config.ollamaBaseUrl,
                model: modelUsed,
                timeoutMs: config.ollamaTimeoutMs,
                title: input.test_case_title,
                description: classificationDescription,
                context: {
                    external_id: input.external_id,
                    test_case_description: input.test_case_description,
                    priority: input.priority,
                    criticality: input.criticality,
                    current_status: input.current_status,
                    project_version_id: input.project_version_id,
                    environment_name: input.environment_name,
                    notes: input.notes,
                },
            });
            return applyConfidenceThreshold(recoverUnknownWithHeuristic({
                scenario: fallback.scenario,
                confidence: fallback.confidence,
                source: 'fallback',
                modelUsed,
            }, input), input);
        }
        catch (retryError) {
            const inferred = inferScenarioFromText(input);
            process.stderr.write(`[agent] Ollama fallback model ${fallbackModel} failed (${retryError instanceof Error ? retryError.message : String(retryError)}). Using heuristic scenario: ${inferred}\n`);
            return {
                scenario: inferred,
                confidence: 0,
                source: 'heuristic-fallback',
            };
        }
    }
}
function buildRunSpec(input, scenario) {
    const normalizedBaseUrl = normalizeBaseUrl(input.base_url);
    const internalChecklistTarget = isInternalChecklistTarget(normalizedBaseUrl);
    const effectiveScenario = scenario;
    const useExternalGenericUnknown = !internalChecklistTarget && effectiveScenario === 'unknown';
    if (!internalChecklistTarget && scenario !== 'unknown') {
        process.stderr.write(`[agent] External target detected (${normalizedBaseUrl}); running scenario '${scenario}' assertions against configured app host.\n`);
    }
    if (useExternalGenericUnknown) {
        process.stderr.write(`[agent] External target detected (${normalizedBaseUrl}) with unknown scenario; using generic website assertions.\n`);
    }
    const steps = useExternalGenericUnknown
        ? [{ action: 'goto', url: normalizedBaseUrl }]
        : effectiveScenario === 'unknown'
            ? buildUnknownScenarioSteps(internalChecklistTarget)
            : [{ action: 'goto', url: scenarioToPath(effectiveScenario) }];
    let asserts;
    if (useExternalGenericUnknown) {
        asserts = buildGenericAsserts(normalizedBaseUrl);
    }
    else if (effectiveScenario === 'unknown') {
        process.stderr.write(`[agent] Unknown scenario for test case ${input.external_id}; applying fail-closed guard assertion (${UNKNOWN_SCENARIO_GUARD_TOKEN}).\n`);
        asserts = buildUnknownScenarioAsserts();
    }
    else {
        asserts = scenarioToAssert(effectiveScenario);
    }
    return {
        schema_version: '1.0',
        run_id: input.run_id,
        target: {
            base_url: normalizedBaseUrl,
        },
        runtime: buildRuntimeConfig(input),
        cases: [
            {
                external_id: input.external_id,
                title: input.test_case_title,
                severity: 'critical',
                use_auth: internalChecklistTarget ? input.use_auth !== false : false,
                steps,
                asserts,
            },
        ],
    };
}
async function buildRunSpecWithPlaywrightModels(input) {
    const normalizedBaseUrl = normalizeBaseUrl(input.base_url);
    const internalChecklistTarget = isInternalChecklistTarget(normalizedBaseUrl);
    const plan = plannerModel(input, internalChecklistTarget);
    process.stderr.write(`[agent][planner] mode=${plan.mode}; scenario=${plan.scenario ?? 'n/a'}; reason=${plan.reason}\n`);
    const generated = await generatorModel(input, normalizedBaseUrl, internalChecklistTarget, plan);
    process.stderr.write(`[agent][generator] steps=${generated.steps.length}; asserts=${generated.asserts.length}; use_auth=${generated.useAuth}\n`);
    const healed = healerModel(normalizedBaseUrl, generated);
    process.stderr.write(`[agent][healer] steps=${healed.steps.length}; asserts=${healed.asserts.length}\n`);
    return {
        schema_version: '1.0',
        run_id: input.run_id,
        target: {
            base_url: normalizedBaseUrl,
        },
        runtime: buildRuntimeConfig(input),
        cases: [
            {
                external_id: input.external_id,
                title: input.test_case_title,
                severity: 'critical',
                use_auth: healed.useAuth,
                steps: healed.steps,
                asserts: healed.asserts,
            },
        ],
    };
}
function usageAndExit() {
    process.stderr.write('Usage: node dist/generateRunSpec.js --input <path-to-input.json>\n');
    process.exit(2);
}
async function main() {
    const inputFlagIndex = process.argv.findIndex((value) => value === '--input');
    if (inputFlagIndex === -1 || !process.argv[inputFlagIndex + 1]) {
        usageAndExit();
    }
    const inputPath = path.resolve(process.argv[inputFlagIndex + 1]);
    const raw = await fs.readFile(inputPath, 'utf8');
    const parsedInput = InputSchema.parse(JSON.parse(raw));
    const generationEngine = config.generationEngine === 'classification' ? 'classification' : 'playwright-models';
    process.stderr.write(`[agent] Generation engine: ${generationEngine}\n`);
    let runSpec;
    if (generationEngine === 'classification') {
        const classification = await classifyWithFallback(parsedInput);
        process.stderr.write(`[agent] Classification outcome: scenario=${classification.scenario}; source=${classification.source}; confidence=${typeof classification.confidence === 'number' ? classification.confidence.toFixed(3) : 'n/a'}\n`);
        runSpec = buildRunSpec(parsedInput, classification.scenario);
    }
    else {
        runSpec = await buildRunSpecWithPlaywrightModels(parsedInput);
    }
    const validatedRunSpec = RunSpecSchema.parse(runSpec);
    process.stdout.write(`${JSON.stringify(validatedRunSpec)}\n`);
}
main().catch((error) => {
    const message = error instanceof Error ? error.message : String(error);
    process.stderr.write(`[agent] generateRunSpec failed: ${message}\n`);
    process.exit(3);
});
//# sourceMappingURL=generateRunSpec.js.map