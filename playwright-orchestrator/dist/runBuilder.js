"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.buildRunRequest = buildRunRequest;
function emailSelector(config) {
    if (!config.loginEmailTestId) {
        throw new Error('LOGIN_EMAIL_TESTID is required for testid-only mode');
    }
    return { by: 'testid', id: config.loginEmailTestId };
}
function passwordSelector(config) {
    if (!config.loginPasswordTestId) {
        throw new Error('LOGIN_PASSWORD_TESTID is required for testid-only mode');
    }
    return { by: 'testid', id: config.loginPasswordTestId };
}
function submitSelector(config) {
    if (!config.loginSubmitTestId) {
        throw new Error('LOGIN_SUBMIT_TESTID is required for testid-only mode');
    }
    return { by: 'testid', id: config.loginSubmitTestId };
}
function testIdSelector(id) {
    return { by: 'testid', id };
}
function claimExternalId(preferredId, usedIds) {
    let id = preferredId;
    while (usedIds.has(id)) {
        id += 1;
    }
    usedIds.add(id);
    return id;
}
function buildPageSmokeCase(page, externalId) {
    switch (page) {
        case 'dashboard':
            return {
                external_id: externalId,
                title: 'Dashboard loads',
                severity: 'critical',
                use_auth: true,
                steps: [{ action: 'goto', url: '/dashboard' }],
                asserts: [{ type: 'expect_visible', selector: testIdSelector('dashboard-stats') }],
            };
        case 'checklists':
            return {
                external_id: externalId,
                title: 'Checklists loads',
                severity: 'critical',
                use_auth: true,
                steps: [{ action: 'goto', url: '/checklists' }],
                asserts: [{ type: 'expect_visible', selector: testIdSelector('checklists-table') }],
            };
        case 'projects':
            return {
                external_id: externalId,
                title: 'Projects loads',
                severity: 'critical',
                use_auth: true,
                steps: [{ action: 'goto', url: '/projects' }],
                asserts: [{ type: 'expect_visible', selector: testIdSelector('projects-table') }],
            };
        case 'users':
            return {
                external_id: externalId,
                title: 'Users loads',
                severity: 'critical',
                use_auth: true,
                steps: [{ action: 'goto', url: '/users' }],
                asserts: [{ type: 'expect_visible', selector: testIdSelector('users-table') }],
            };
        default: {
            const exhaustive = page;
            throw new Error(`Unsupported smoke page '${String(exhaustive)}'`);
        }
    }
}
function generateRunId(config) {
    if (config.runId) {
        return config.runId;
    }
    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const dd = String(now.getDate()).padStart(2, '0');
    const hh = String(now.getHours()).padStart(2, '0');
    const min = String(now.getMinutes()).padStart(2, '0');
    const sec = String(now.getSeconds()).padStart(2, '0');
    return `tr-${yyyy}${mm}${dd}-${hh}${min}${sec}-${config.runSuite}`;
}
function buildUiLoginCase(config) {
    return {
        external_id: config.smokeUiLoginExternalId,
        title: 'Smoke UI Login',
        severity: 'critical',
        use_auth: false,
        steps: [
            { action: 'goto', url: '/login' },
            {
                action: 'fill',
                selector: emailSelector(config),
                value: '${E2E_EMAIL}',
            },
            {
                action: 'fill',
                selector: passwordSelector(config),
                value: '${E2E_PASSWORD}',
            },
            {
                action: 'click',
                selector: submitSelector(config),
            },
            {
                action: 'wait_for_url',
                contains: config.dashboardUrlFragment,
                timeout_ms: Math.max(10000, config.runtimeTimeoutMs),
            },
        ],
        asserts: [
            {
                type: 'expect_url_contains',
                value: config.dashboardUrlFragment,
            },
        ],
    };
}
function validateRequiredSelectors(config) {
    if (config.targetApp !== 'checklist-manager') {
        return;
    }
    const shouldRequireLoginSelectors = config.authMode === 'ui' || config.authUiTestEnabled;
    if (!shouldRequireLoginSelectors) {
        return;
    }
    const missing = [];
    if (!config.loginEmailTestId) {
        missing.push('LOGIN_EMAIL_TESTID');
    }
    if (!config.loginPasswordTestId) {
        missing.push('LOGIN_PASSWORD_TESTID');
    }
    if (!config.loginSubmitTestId) {
        missing.push('LOGIN_SUBMIT_TESTID');
    }
    if (missing.length > 0) {
        throw new Error(`Missing required testid selectors: ${missing.join(', ')}`);
    }
}
function buildRunRequest(config, runId) {
    validateRequiredSelectors(config);
    const finalRunId = runId ?? generateRunId(config);
    const usedExternalIds = new Set();
    const cases = config.smokePages.map((page, index) => {
        const externalId = claimExternalId(config.smokeExternalId + index, usedExternalIds);
        return buildPageSmokeCase(page, externalId);
    });
    if (config.authMode === 'ui' || config.authUiTestEnabled) {
        const uiCase = buildUiLoginCase(config);
        uiCase.external_id = claimExternalId(config.smokeUiLoginExternalId, usedExternalIds);
        cases.push(uiCase);
    }
    const auth = config.authMode === 'api' && config.enableLoginFlow
        ? {
            mode: 'api',
            csrf_cookie_url: config.sanctumCsrfCookiePath,
            login_url: config.loginPath,
            verify_url: config.authVerifyPath,
            username_env: 'E2E_EMAIL',
            password_env: 'E2E_PASSWORD',
            username_field: 'email',
            password_field: 'password',
            body_format: config.authBodyFormat,
        }
        : undefined;
    return {
        schema_version: '1.0',
        run_id: finalRunId,
        target: {
            base_url: config.baseUrl,
        },
        runtime: {
            headless: config.runtimeHeadless,
            timeout_ms: config.runtimeTimeoutMs,
            viewport: {
                width: config.runtimeViewportWidth,
                height: config.runtimeViewportHeight,
            },
            trace: config.runtimeTrace,
            video: config.runtimeVideo,
            screenshot: config.runtimeScreenshot,
        },
        cases,
        auth,
    };
}
