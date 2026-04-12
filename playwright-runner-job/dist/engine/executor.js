"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.executeRun = executeRun;
const node_fs_1 = require("node:fs");
const node_path_1 = __importDefault(require("node:path"));
const playwright_1 = require("playwright");
const artifacts_1 = require("./artifacts");
const results_1 = require("./results");
const selectors_1 = require("./selectors");
const ENV_VAR_PATTERN = /\$\{([A-Z0-9_]+)\}/g;
const UNKNOWN_SCENARIO_GUARD_TOKEN = '__agent_unknown_scenario__';
function sleep(ms) {
    if (ms <= 0) {
        return Promise.resolve();
    }
    return new Promise((resolve) => setTimeout(resolve, ms));
}
async function flushLiveTrace(liveTracePath, state) {
    if (!liveTracePath || !state) {
        return;
    }
    await (0, artifacts_1.ensureDir)(node_path_1.default.dirname(liveTracePath));
    await node_fs_1.promises.writeFile(liveTracePath, `${JSON.stringify(state, null, 2)}\n`, 'utf8');
}
function resolveTemplateValue(value) {
    return value.replace(ENV_VAR_PATTERN, (_, varName) => {
        const envValue = process.env[varName];
        if (envValue === undefined) {
            throw new results_1.MissingEnvVarError(varName);
        }
        return envValue;
    });
}
function resolveUrl(baseUrl, inputUrl) {
    const resolved = resolveTemplateValue(inputUrl);
    if (resolved.startsWith('http://') || resolved.startsWith('https://')) {
        return resolved;
    }
    return new URL(resolved, baseUrl).toString();
}
function getRequiredEnv(name) {
    const value = process.env[name];
    if (!value || value.trim() === '') {
        throw new results_1.MissingEnvVarError(name);
    }
    return value;
}
function tryExtractJsonBody(payload) {
    if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
        return null;
    }
    return payload;
}
async function createApiAuthStorageState(browser, request, auth, timeoutMs, storageStatePath) {
    const setupContext = await browser.newContext({
        viewport: request.runtime.viewport,
    });
    try {
        const username = getRequiredEnv(auth.username_env);
        const password = getRequiredEnv(auth.password_env);
        const csrfUrl = resolveUrl(request.target.base_url, auth.csrf_cookie_url);
        const loginUrl = resolveUrl(request.target.base_url, auth.login_url);
        const verifyUrl = auth.verify_url ? resolveUrl(request.target.base_url, auth.verify_url) : null;
        const defaultHeaders = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        const csrfResponse = await setupContext.request.get(csrfUrl, {
            timeout: timeoutMs,
            failOnStatusCode: false,
            headers: defaultHeaders,
        });
        if (csrfResponse.status() < 200 || csrfResponse.status() >= 300) {
            throw new Error(`CSRF bootstrap failed (${csrfResponse.status()})`);
        }
        const usernameField = auth.username_field ?? 'email';
        const passwordField = auth.password_field ?? 'password';
        const bodyFormat = auth.body_format ?? 'json';
        const payload = {
            [usernameField]: username,
            [passwordField]: password,
        };
        const loginResponse = bodyFormat === 'form'
            ? await setupContext.request.post(loginUrl, {
                timeout: timeoutMs,
                failOnStatusCode: false,
                headers: defaultHeaders,
                form: payload,
            })
            : await setupContext.request.post(loginUrl, {
                timeout: timeoutMs,
                failOnStatusCode: false,
                headers: defaultHeaders,
                data: payload,
            });
        if (loginResponse.status() < 200 || loginResponse.status() >= 300) {
            throw new Error(`API login failed (${loginResponse.status()})`);
        }
        if (verifyUrl) {
            const verifyResponse = await setupContext.request.get(verifyUrl, {
                timeout: timeoutMs,
                failOnStatusCode: false,
                headers: defaultHeaders,
            });
            if (verifyResponse.status() < 200 || verifyResponse.status() >= 300) {
                throw new Error(`Auth verify failed (${verifyResponse.status()})`);
            }
        }
        // If login API returns token/user, persist expected localStorage keys used by the Vue auth store.
        const loginJson = tryExtractJsonBody(await loginResponse.json().catch(() => null));
        if (loginJson) {
            const page = await setupContext.newPage();
            await page.goto(resolveUrl(request.target.base_url, '/login'), {
                waitUntil: 'domcontentloaded',
                timeout: timeoutMs,
            });
            const token = typeof loginJson.token === 'string' ? loginJson.token : '';
            const user = typeof loginJson.user === 'object' && loginJson.user !== null ? loginJson.user : null;
            const roles = Array.isArray(loginJson.roles) ? loginJson.roles : [];
            await page.evaluate(({ tokenValue, userValue, rolesValue }) => {
                if (tokenValue) {
                    window.localStorage.setItem('auth_token', tokenValue);
                }
                if (userValue) {
                    window.localStorage.setItem('auth_user', JSON.stringify({ user: userValue, roles: rolesValue }));
                }
            }, {
                tokenValue: token,
                userValue: user,
                rolesValue: roles,
            });
        }
        await setupContext.storageState({ path: storageStatePath });
    }
    finally {
        await setupContext.close();
    }
}
function createEmptyArtifacts() {
    return {
        trace_path: null,
        screenshot_path: null,
        video_path: null,
    };
}
function describeStep(step) {
    switch (step.action) {
        case 'goto':
            return `goto ${step.url}`;
        case 'click':
            return `click ${(0, selectors_1.selectorDebugText)(step.selector)}`;
        case 'fill':
            return `fill ${(0, selectors_1.selectorDebugText)(step.selector)}`;
        case 'press':
            return `press ${step.key} on ${(0, selectors_1.selectorDebugText)(step.selector)}`;
        case 'wait_for_url':
            return `wait_for_url contains '${step.contains}'`;
        case 'wait_for_selector':
            return `wait_for_selector ${(0, selectors_1.selectorDebugText)(step.selector)} state=${step.state}`;
        case 'screenshot':
            return `screenshot ${step.name}`;
        default: {
            const unreachable = step;
            return JSON.stringify(unreachable);
        }
    }
}
function describeAssert(assertItem) {
    switch (assertItem.type) {
        case 'expect_visible':
            return `expect_visible ${(0, selectors_1.selectorDebugText)(assertItem.selector)}`;
        case 'expect_hidden':
            return `expect_hidden ${(0, selectors_1.selectorDebugText)(assertItem.selector)}`;
        case 'expect_text':
            return `expect_text ${(0, selectors_1.selectorDebugText)(assertItem.selector)}`;
        case 'expect_url_contains':
            return `expect_url_contains '${assertItem.value}'`;
        case 'expect_title':
            return `expect_title '${assertItem.value}'`;
        default: {
            const unreachable = assertItem;
            return JSON.stringify(unreachable);
        }
    }
}
function isStrictModeViolation(error) {
    const message = error instanceof Error ? error.message : String(error);
    return message.toLowerCase().includes('strict mode violation');
}
async function clickWithStrictFallback(page, selector) {
    const locator = (0, selectors_1.selectorToLocator)(page, selector);
    try {
        await locator.click();
    }
    catch (error) {
        if (!isStrictModeViolation(error)) {
            throw error;
        }
        const fallbackLocator = await selectFirstVisibleLocator(locator);
        await fallbackLocator.click();
    }
}
async function fillWithStrictFallback(page, selector, value) {
    const locator = (0, selectors_1.selectorToLocator)(page, selector);
    try {
        await locator.fill(value);
    }
    catch (error) {
        if (!isStrictModeViolation(error)) {
            throw error;
        }
        const fallbackLocator = await selectFirstVisibleLocator(locator);
        await fallbackLocator.fill(value);
    }
}
async function pressWithStrictFallback(page, selector, key) {
    const locator = (0, selectors_1.selectorToLocator)(page, selector);
    try {
        await locator.press(key);
    }
    catch (error) {
        if (!isStrictModeViolation(error)) {
            throw error;
        }
        const fallbackLocator = await selectFirstVisibleLocator(locator);
        await fallbackLocator.press(key);
    }
}
async function selectFirstVisibleLocator(locator) {
    const total = await locator.count();
    for (let index = 0; index < total; index += 1) {
        const candidate = locator.nth(index);
        try {
            if (await candidate.isVisible()) {
                return candidate;
            }
        }
        catch {
            // Ignore transient visibility lookup errors and keep scanning.
        }
    }
    return locator.first();
}
async function waitForSelectorWithStrictFallback(page, selector, state, timeout) {
    const locator = (0, selectors_1.selectorToLocator)(page, selector);
    try {
        await locator.waitFor({ state, timeout });
    }
    catch (error) {
        if (!isStrictModeViolation(error)) {
            throw error;
        }
        const fallbackLocator = state === 'visible'
            ? await selectFirstVisibleLocator(locator)
            : locator.first();
        await fallbackLocator.waitFor({ state, timeout });
    }
}
async function textContentWithStrictFallback(page, selector, timeout) {
    const locator = (0, selectors_1.selectorToLocator)(page, selector);
    try {
        return await locator.textContent({ timeout });
    }
    catch (error) {
        if (!isStrictModeViolation(error)) {
            throw error;
        }
        const fallbackLocator = await selectFirstVisibleLocator(locator);
        return fallbackLocator.textContent({ timeout });
    }
}
async function executeStep(page, step, baseUrl, timeoutMs, caseArtifactsDir) {
    switch (step.action) {
        case 'goto': {
            const finalUrl = resolveUrl(baseUrl, step.url);
            await page.goto(finalUrl, { timeout: timeoutMs, waitUntil: 'domcontentloaded' });
            return;
        }
        case 'click': {
            await clickWithStrictFallback(page, step.selector);
            return;
        }
        case 'fill': {
            await fillWithStrictFallback(page, step.selector, resolveTemplateValue(step.value));
            return;
        }
        case 'press': {
            await pressWithStrictFallback(page, step.selector, step.key);
            return;
        }
        case 'wait_for_url': {
            const contains = resolveTemplateValue(step.contains);
            await page.waitForURL((url) => url.toString().includes(contains), {
                timeout: step.timeout_ms ?? timeoutMs,
            });
            return;
        }
        case 'wait_for_selector': {
            await waitForSelectorWithStrictFallback(page, step.selector, step.state, step.timeout_ms ?? timeoutMs);
            return;
        }
        case 'screenshot': {
            await (0, artifacts_1.ensureDir)(caseArtifactsDir);
            const screenshotName = `${(0, artifacts_1.sanitizeFilename)(step.name)}.png`;
            const screenshotPath = node_path_1.default.join(caseArtifactsDir, screenshotName);
            await page.screenshot({
                path: screenshotPath,
                fullPage: true,
            });
            return;
        }
        default: {
            const unreachable = step;
            throw new Error(`Unsupported step: ${JSON.stringify(unreachable)}`);
        }
    }
}
async function executeAssert(page, assertItem, timeoutMs) {
    switch (assertItem.type) {
        case 'expect_visible': {
            try {
                await waitForSelectorWithStrictFallback(page, assertItem.selector, 'visible', timeoutMs);
            }
            catch (error) {
                throw new results_1.AssertionFailureError(`Expected visible but selector was not visible: ${(0, selectors_1.selectorDebugText)(assertItem.selector)} (${String(error)})`);
            }
            return;
        }
        case 'expect_hidden': {
            try {
                await waitForSelectorWithStrictFallback(page, assertItem.selector, 'hidden', timeoutMs);
            }
            catch (error) {
                throw new results_1.AssertionFailureError(`Expected hidden but selector was still visible: ${(0, selectors_1.selectorDebugText)(assertItem.selector)} (${String(error)})`);
            }
            return;
        }
        case 'expect_text': {
            const expected = resolveTemplateValue(assertItem.text);
            const actualRaw = await textContentWithStrictFallback(page, assertItem.selector, timeoutMs);
            const actual = (actualRaw ?? '').trim();
            if (actual !== expected) {
                throw new results_1.AssertionFailureError(`Expected text '${expected}' but got '${actual}' for ${(0, selectors_1.selectorDebugText)(assertItem.selector)}`);
            }
            return;
        }
        case 'expect_url_contains': {
            const expectedFragment = resolveTemplateValue(assertItem.value);
            const currentUrl = page.url();
            if (!currentUrl.includes(expectedFragment)) {
                if (expectedFragment === UNKNOWN_SCENARIO_GUARD_TOKEN) {
                    throw new results_1.AssertionFailureError(`Unknown scenario guard triggered: classifier returned unknown or low-confidence, so this case is intentionally failed. Current URL: '${currentUrl}'.`);
                }
                throw new results_1.AssertionFailureError(`Expected URL to contain '${expectedFragment}' but got '${currentUrl}'`);
            }
            return;
        }
        case 'expect_title': {
            const expectedTitle = resolveTemplateValue(assertItem.value);
            const actualTitle = await page.title();
            if (actualTitle !== expectedTitle) {
                throw new results_1.AssertionFailureError(`Expected title '${expectedTitle}' but got '${actualTitle}'`);
            }
            return;
        }
        default: {
            const unreachable = assertItem;
            throw new Error(`Unsupported assert: ${JSON.stringify(unreachable)}`);
        }
    }
}
async function stopTraceIfStarted(context, traceStarted, keepTrace, traceOutputPath) {
    if (!traceStarted || !context) {
        return null;
    }
    if (keepTrace) {
        await context.tracing.stop({ path: traceOutputPath });
        return traceOutputPath;
    }
    await context.tracing.stop();
    return null;
}
async function finalizeVideo(video, keepVideo, caseArtifactsDir) {
    if (!video) {
        return null;
    }
    const originalVideoPath = await video.path().catch(() => null);
    if (!originalVideoPath) {
        return null;
    }
    if (!keepVideo) {
        await (0, artifacts_1.deleteIfExists)(originalVideoPath);
        return null;
    }
    const destination = node_path_1.default.join(caseArtifactsDir, 'video.webm');
    await (0, artifacts_1.moveOrCopyFile)(originalVideoPath, destination);
    return destination;
}
async function executeCase(browser, runCase, request, options, storageStatePath, onTrace, tracePrefix = '') {
    const start = Date.now();
    const timeoutMs = request.runtime.timeout_ms;
    const caseArtifactsDir = await (0, artifacts_1.ensureCaseArtifactsDir)(options.artifactsConfig, runCase.external_id);
    let context;
    let page;
    let videoHandle = null;
    let traceStarted = false;
    const artifacts = createEmptyArtifacts();
    const executionTrace = [];
    const pushTrace = async (line) => {
        const prefixed = tracePrefix ? `[${tracePrefix}] ${line}` : line;
        executionTrace.push(prefixed);
        if (onTrace) {
            await onTrace(prefixed);
        }
    };
    let status = 'passed';
    let error_type = null;
    let error_message = null;
    try {
        await pushTrace('Browser context started');
        const caseStorageStatePath = runCase.use_auth === false ? undefined : storageStatePath;
        context = await browser.newContext({
            viewport: request.runtime.viewport,
            storageState: caseStorageStatePath,
            recordVideo: request.runtime.video === 'retain-on-failure'
                ? {
                    dir: caseArtifactsDir,
                }
                : undefined,
        });
        page = await context.newPage();
        videoHandle = page.video();
        page.setDefaultTimeout(timeoutMs);
        if (request.runtime.trace === 'retain-on-failure') {
            await context.tracing.start({
                screenshots: true,
                snapshots: true,
                sources: true,
            });
            traceStarted = true;
        }
        for (let index = 0; index < runCase.steps.length; index += 1) {
            const step = runCase.steps[index];
            const stepLabel = describeStep(step);
            const stepStart = Date.now();
            try {
                await executeStep(page, step, request.target.base_url, timeoutMs, caseArtifactsDir);
                await pushTrace(`Step ${index + 1}: ${stepLabel} -> ok (${Date.now() - stepStart}ms)`);
            }
            catch (error) {
                const message = error instanceof Error ? error.message : String(error);
                await pushTrace(`Step ${index + 1}: ${stepLabel} -> failed (${message})`);
                throw error;
            }
        }
        for (let index = 0; index < runCase.asserts.length; index += 1) {
            const assertItem = runCase.asserts[index];
            const assertLabel = describeAssert(assertItem);
            const assertStart = Date.now();
            try {
                await executeAssert(page, assertItem, timeoutMs);
                await pushTrace(`Assert ${index + 1}: ${assertLabel} -> ok (${Date.now() - assertStart}ms)`);
            }
            catch (error) {
                const message = error instanceof Error ? error.message : String(error);
                await pushTrace(`Assert ${index + 1}: ${assertLabel} -> failed (${message})`);
                throw error;
            }
        }
    }
    catch (error) {
        const normalized = (0, results_1.normalizeError)(error);
        if (normalized.error_type === 'missing_env_var' || normalized.error_type === 'selector_not_found') {
            status = 'blocked';
        }
        else {
            status = 'failed';
        }
        error_type = normalized.error_type;
        error_message = normalized.error_message;
        await pushTrace(`Run failed: ${normalized.error_message}`);
        if (status === 'failed' && request.runtime.screenshot === 'only-on-failure' && page) {
            const failScreenshotPath = node_path_1.default.join(caseArtifactsDir, 'fail.png');
            await page.screenshot({ path: failScreenshotPath, fullPage: true });
            artifacts.screenshot_path = (0, artifacts_1.toRelativeWorkPath)(options.artifactsConfig, failScreenshotPath);
        }
        const tracePath = node_path_1.default.join(caseArtifactsDir, 'trace.zip');
        const keepTrace = status === 'failed' && request.runtime.trace === 'retain-on-failure';
        const traceSavedPath = await stopTraceIfStarted(context, traceStarted, keepTrace, tracePath);
        traceStarted = false;
        if (traceSavedPath) {
            artifacts.trace_path = (0, artifacts_1.toRelativeWorkPath)(options.artifactsConfig, traceSavedPath);
        }
    }
    finally {
        const holdOpenMs = request.runtime.hold_open_ms ?? 0;
        if (!request.runtime.headless && holdOpenMs > 0 && page) {
            await sleep(holdOpenMs);
        }
        if (traceStarted) {
            await stopTraceIfStarted(context, traceStarted, false, node_path_1.default.join(caseArtifactsDir, 'trace.zip'));
            traceStarted = false;
        }
        if (context) {
            await context.close();
        }
        if (request.runtime.video === 'retain-on-failure') {
            const keepVideo = status === 'failed';
            const videoPath = await finalizeVideo(videoHandle, keepVideo, caseArtifactsDir);
            if (videoPath) {
                artifacts.video_path = (0, artifacts_1.toRelativeWorkPath)(options.artifactsConfig, videoPath);
            }
        }
        if (status === 'passed') {
            await pushTrace('Run completed successfully');
        }
    }
    return {
        external_id: runCase.external_id,
        status,
        attempt: 1,
        duration_ms: Date.now() - start,
        error_type,
        error_message,
        artifacts,
        execution_trace: executionTrace,
    };
}
function getRuntimeBrowserTargets(request) {
    const browsers = request.runtime.browsers;
    if (!Array.isArray(browsers) || browsers.length === 0) {
        return ['chromium'];
    }
    return Array.from(new Set(browsers));
}
function getBrowserLabel(target) {
    switch (target) {
        case 'chromium':
            return 'chrome';
        case 'msedge':
            return 'edge';
        case 'firefox':
            return 'firefox';
        case 'webkit':
            return 'safari';
        default:
            return target;
    }
}
function statusPriority(status) {
    switch (status) {
        case 'failed':
            return 3;
        case 'blocked':
            return 2;
        case 'skipped':
            return 1;
        case 'passed':
        default:
            return 0;
    }
}
function pickWorstStatus(results) {
    let worst = 'passed';
    for (const entry of results) {
        if (statusPriority(entry.result.status) > statusPriority(worst)) {
            worst = entry.result.status;
        }
    }
    return worst;
}
function firstNonNull(values) {
    for (const value of values) {
        if (value !== null && value !== undefined) {
            return value;
        }
    }
    return null;
}
function aggregateBrowserResults(runCase, browserResults) {
    const status = pickWorstStatus(browserResults);
    const firstFailure = browserResults.find((entry) => entry.result.status !== 'passed');
    const aggregatedTrace = browserResults.flatMap((entry) => entry.result.execution_trace ?? []);
    return {
        external_id: runCase.external_id,
        status,
        attempt: 1,
        duration_ms: browserResults.reduce((sum, entry) => sum + entry.result.duration_ms, 0),
        error_type: firstFailure?.result.error_type ?? null,
        error_message: firstFailure?.result.error_message ?? null,
        artifacts: {
            trace_path: firstNonNull(browserResults.map((entry) => entry.result.artifacts.trace_path)),
            screenshot_path: firstNonNull(browserResults.map((entry) => entry.result.artifacts.screenshot_path)),
            video_path: firstNonNull(browserResults.map((entry) => entry.result.artifacts.video_path)),
        },
        execution_trace: aggregatedTrace,
    };
}
async function launchBrowserForTarget(target, request) {
    const launchOptions = {
        headless: request.runtime.headless,
        slowMo: request.runtime.slow_mo_ms ?? 0,
    };
    let browserType;
    switch (target) {
        case 'firefox':
            browserType = playwright_1.firefox;
            break;
        case 'webkit':
            browserType = playwright_1.webkit;
            break;
        case 'msedge':
            browserType = playwright_1.chromium;
            launchOptions.channel = 'msedge';
            break;
        case 'chromium':
        default:
            browserType = playwright_1.chromium;
            break;
    }
    return browserType.launch(launchOptions);
}
async function executeRun(request, options) {
    const runnerRunId = `runner-${Date.now()}`;
    const browserTargets = getRuntimeBrowserTargets(request);
    const liveTraceState = options.liveTracePath
        ? {
            schema_version: '1.0',
            run_id: request.run_id,
            status: 'running',
            updated_at: new Date().toISOString(),
            cases: request.cases.map((runCase) => ({
                external_id: runCase.external_id,
                title: runCase.title,
                status: 'queued',
                execution_trace: [],
            })),
        }
        : null;
    const results = [];
    let storageStatePath;
    try {
        await flushLiveTrace(options.liveTracePath, liveTraceState);
        if (request.auth?.mode === 'api') {
            const authBrowser = await playwright_1.chromium.launch({
                headless: request.runtime.headless,
                slowMo: request.runtime.slow_mo_ms ?? 0,
            });
            storageStatePath = node_path_1.default.join(options.artifactsConfig.workRoot, 'storageState.json');
            try {
                await createApiAuthStorageState(authBrowser, request, request.auth, request.runtime.timeout_ms, storageStatePath);
            }
            finally {
                await authBrowser.close();
            }
        }
        for (const runCase of request.cases) {
            const liveCase = liveTraceState?.cases.find((entry) => entry.external_id === runCase.external_id);
            if (liveCase && liveTraceState) {
                liveCase.status = 'running';
                liveTraceState.updated_at = new Date().toISOString();
                await flushLiveTrace(options.liveTracePath, liveTraceState);
            }
            const browserResults = [];
            for (const browserTarget of browserTargets) {
                const browserLabel = getBrowserLabel(browserTarget);
                let caseBrowser;
                try {
                    caseBrowser = await launchBrowserForTarget(browserTarget, request);
                    const caseResult = await executeCase(caseBrowser, runCase, request, options, storageStatePath, async (line) => {
                        if (!liveCase || !liveTraceState) {
                            return;
                        }
                        liveCase.execution_trace.push(line);
                        liveTraceState.updated_at = new Date().toISOString();
                        await flushLiveTrace(options.liveTracePath, liveTraceState);
                    }, browserLabel);
                    browserResults.push({
                        browser: browserTarget,
                        result: caseResult,
                    });
                }
                catch (error) {
                    const message = error instanceof Error ? error.message : String(error);
                    browserResults.push({
                        browser: browserTarget,
                        result: {
                            external_id: runCase.external_id,
                            status: 'blocked',
                            attempt: 1,
                            duration_ms: 0,
                            error_type: 'unexpected_error',
                            error_message: `[${browserLabel}] ${message}`,
                            artifacts: {
                                trace_path: null,
                                screenshot_path: null,
                                video_path: null,
                            },
                            execution_trace: [`[${browserLabel}] Browser launch failed: ${message}`],
                        },
                    });
                    if (liveCase && liveTraceState) {
                        liveCase.execution_trace.push(`[${browserLabel}] Browser launch failed: ${message}`);
                        liveTraceState.updated_at = new Date().toISOString();
                        await flushLiveTrace(options.liveTracePath, liveTraceState);
                    }
                }
                finally {
                    if (caseBrowser) {
                        await caseBrowser.close();
                    }
                }
            }
            const caseResult = aggregateBrowserResults(runCase, browserResults);
            if (liveCase && liveTraceState) {
                liveCase.status = caseResult.status;
                liveTraceState.updated_at = new Date().toISOString();
                await flushLiveTrace(options.liveTracePath, liveTraceState);
            }
            results.push(caseResult);
        }
        if (liveTraceState) {
            liveTraceState.status = 'done';
            liveTraceState.updated_at = new Date().toISOString();
            await flushLiveTrace(options.liveTracePath, liveTraceState);
        }
    }
    catch (error) {
        if (liveTraceState) {
            liveTraceState.status = 'runner_error';
            liveTraceState.error_message = error instanceof Error ? error.message : String(error);
            liveTraceState.updated_at = new Date().toISOString();
            await flushLiveTrace(options.liveTracePath, liveTraceState);
        }
        throw error;
    }
    return {
        schema_version: '1.0',
        run_id: request.run_id,
        runner_run_id: runnerRunId,
        base_url_used: request.target.base_url,
        status: 'done',
        summary: (0, results_1.summarizeResults)(results),
        results,
    };
}
