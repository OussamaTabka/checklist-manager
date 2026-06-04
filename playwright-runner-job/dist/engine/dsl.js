"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.validateRunRequest = validateRunRequest;
const DEFAULT_RUNTIME = {
    headless: true,
    slow_mo_ms: 0,
    hold_open_ms: 0,
    browsers: ['chromium'],
    timeout_ms: 30000,
    viewport: {
        width: 1280,
        height: 720,
    },
    trace: 'off',
    video: 'off',
    screenshot: 'off',
};
function isObject(value) {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
function isNonEmptyString(value) {
    return typeof value === 'string' && value.trim().length > 0;
}
function isPositiveInteger(value) {
    return typeof value === 'number' && Number.isInteger(value) && value > 0;
}
function isHttpUrl(value) {
    try {
        const url = new URL(value);
        return url.protocol === 'http:' || url.protocol === 'https:';
    }
    catch {
        return false;
    }
}
function validateSelector(input, path, errors) {
    if (!isObject(input)) {
        errors.push(`${path} must be an object`);
        return false;
    }
    if (!isNonEmptyString(input.by)) {
        errors.push(`${path}.by must be a non-empty string`);
        return false;
    }
    switch (input.by) {
        case 'testid':
            if (!isNonEmptyString(input.id)) {
                errors.push(`${path}.id must be a non-empty string`);
                return false;
            }
            return true;
        case 'role':
            if (!isNonEmptyString(input.role)) {
                errors.push(`${path}.role must be a non-empty string`);
                return false;
            }
            if (!isNonEmptyString(input.name)) {
                errors.push(`${path}.name must be a non-empty string`);
                return false;
            }
            if (input.exact !== undefined && typeof input.exact !== 'boolean') {
                errors.push(`${path}.exact must be a boolean when provided`);
                return false;
            }
            return true;
        case 'label':
            if (!isNonEmptyString(input.text)) {
                errors.push(`${path}.text must be a non-empty string`);
                return false;
            }
            return true;
        case 'text':
            if (!isNonEmptyString(input.text)) {
                errors.push(`${path}.text must be a non-empty string`);
                return false;
            }
            if (input.exact !== undefined && typeof input.exact !== 'boolean') {
                errors.push(`${path}.exact must be a boolean when provided`);
                return false;
            }
            return true;
        case 'css':
            if (!isNonEmptyString(input.value)) {
                errors.push(`${path}.value must be a non-empty string`);
                return false;
            }
            return true;
        default:
            errors.push(`${path}.by has unsupported value '${String(input.by)}'`);
            return false;
    }
}
function validateStep(input, path, errors) {
    if (!isObject(input)) {
        errors.push(`${path} must be an object`);
        return false;
    }
    if (!isNonEmptyString(input.action)) {
        errors.push(`${path}.action must be a non-empty string`);
        return false;
    }
    switch (input.action) {
        case 'goto':
            if (!isNonEmptyString(input.url)) {
                errors.push(`${path}.url must be a non-empty string`);
                return false;
            }
            return true;
        case 'click':
            return validateSelector(input.selector, `${path}.selector`, errors);
        case 'fill':
            if (!validateSelector(input.selector, `${path}.selector`, errors)) {
                return false;
            }
            if (input.value !== undefined && !isNonEmptyString(input.value)) {
                errors.push(`${path}.value must be a non-empty string when provided`);
                return false;
            }
            if (input.input_key !== undefined && !isNonEmptyString(input.input_key)) {
                errors.push(`${path}.input_key must be a non-empty string when provided`);
                return false;
            }
            if (input.value === undefined && input.input_key === undefined) {
                errors.push(`${path}.value or ${path}.input_key must be provided`);
                return false;
            }
            return true;
        case 'press':
            if (!validateSelector(input.selector, `${path}.selector`, errors)) {
                return false;
            }
            if (!isNonEmptyString(input.key)) {
                errors.push(`${path}.key must be a non-empty string`);
                return false;
            }
            return true;
        case 'wait_for_url':
            if (!isNonEmptyString(input.contains)) {
                errors.push(`${path}.contains must be a non-empty string`);
                return false;
            }
            if (input.timeout_ms !== undefined && (!isPositiveInteger(input.timeout_ms))) {
                errors.push(`${path}.timeout_ms must be a positive integer when provided`);
                return false;
            }
            return true;
        case 'wait_for_selector':
            if (!validateSelector(input.selector, `${path}.selector`, errors)) {
                return false;
            }
            if (!isNonEmptyString(input.state)) {
                errors.push(`${path}.state must be provided`);
                return false;
            }
            if (!['visible', 'hidden', 'attached', 'detached'].includes(input.state)) {
                errors.push(`${path}.state must be one of visible|hidden|attached|detached`);
                return false;
            }
            if (input.timeout_ms !== undefined && (!isPositiveInteger(input.timeout_ms))) {
                errors.push(`${path}.timeout_ms must be a positive integer when provided`);
                return false;
            }
            return true;
        case 'screenshot':
            if (!isNonEmptyString(input.name)) {
                errors.push(`${path}.name must be a non-empty string`);
                return false;
            }
            return true;
        case 'set_file':
            if (!validateSelector(input.selector, `${path}.selector`, errors)) {
                return false;
            }
            if (input.file_path !== undefined && !isNonEmptyString(input.file_path)) {
                errors.push(`${path}.file_path must be a non-empty string when provided`);
                return false;
            }
            if (input.input_key !== undefined && !isNonEmptyString(input.input_key)) {
                errors.push(`${path}.input_key must be a non-empty string when provided`);
                return false;
            }
            if (input.file_path === undefined && input.input_key === undefined) {
                errors.push(`${path}.file_path or ${path}.input_key must be provided`);
                return false;
            }
            return true;
        default:
            errors.push(`${path}.action has unsupported value '${String(input.action)}'`);
            return false;
    }
}
function validateRequiredInput(input, path, errors) {
    if (!isObject(input)) {
        errors.push(`${path} must be an object`);
        return false;
    }
    if (!isNonEmptyString(input.key)) {
        errors.push(`${path}.key must be a non-empty string`);
    }
    if (!isNonEmptyString(input.label)) {
        errors.push(`${path}.label must be a non-empty string`);
    }
    if (!isNonEmptyString(input.kind) || !['text', 'email', 'password', 'textarea', 'search', 'file'].includes(input.kind)) {
        errors.push(`${path}.kind must be one of text|email|password|textarea|search|file`);
    }
    if (typeof input.required !== 'boolean') {
        errors.push(`${path}.required must be a boolean`);
    }
    if (input.description !== undefined && !isNonEmptyString(input.description)) {
        errors.push(`${path}.description must be a non-empty string when provided`);
    }
    if (input.allow_empty !== undefined && typeof input.allow_empty !== 'boolean') {
        errors.push(`${path}.allow_empty must be a boolean when provided`);
    }
    if (input.value !== undefined && input.value !== null && typeof input.value !== 'string') {
        errors.push(`${path}.value must be a string when provided`);
    }
    return true;
}
function validateDiagnostic(input, path, errors) {
    if (!isObject(input)) {
        errors.push(`${path} must be an object`);
        return false;
    }
    if (!isNonEmptyString(input.code)) {
        errors.push(`${path}.code must be a non-empty string`);
    }
    if (!isNonEmptyString(input.message)) {
        errors.push(`${path}.message must be a non-empty string`);
    }
    if (!isNonEmptyString(input.severity) || !['info', 'warning', 'error'].includes(input.severity)) {
        errors.push(`${path}.severity must be info|warning|error`);
    }
    return true;
}
function validatePreflightCheck(input, path, errors) {
    if (!isObject(input)) {
        errors.push(`${path} must be an object`);
        return false;
    }
    if (!isNonEmptyString(input.id)) {
        errors.push(`${path}.id must be a non-empty string`);
    }
    if (!isNonEmptyString(input.kind) ||
        !['page_accessible', 'element_visible', 'element_attached', 'url_contains', 'input_available', 'unsupported'].includes(input.kind)) {
        errors.push(`${path}.kind has unsupported value`);
    }
    if (!isNonEmptyString(input.label)) {
        errors.push(`${path}.label must be a non-empty string`);
    }
    if (typeof input.required !== 'boolean') {
        errors.push(`${path}.required must be a boolean`);
    }
    if (input.selector !== undefined) {
        validateSelector(input.selector, `${path}.selector`, errors);
    }
    if (input.expected !== undefined && !isNonEmptyString(input.expected)) {
        errors.push(`${path}.expected must be a non-empty string when provided`);
    }
    if (input.input_key !== undefined && !isNonEmptyString(input.input_key)) {
        errors.push(`${path}.input_key must be a non-empty string when provided`);
    }
    if (!isNonEmptyString(input.failure_message)) {
        errors.push(`${path}.failure_message must be a non-empty string`);
    }
    return true;
}
function validateGeneratedPlan(input, path, errors) {
    if (!isObject(input)) {
        errors.push(`${path} must be an object`);
        return false;
    }
    if (input.title !== undefined && !isNonEmptyString(input.title)) {
        errors.push(`${path}.title must be a non-empty string when provided`);
    }
    if (!isNonEmptyString(input.intent_summary)) {
        errors.push(`${path}.intent_summary must be a non-empty string`);
    }
    if (!isNonEmptyString(input.coverage_type)) {
        errors.push(`${path}.coverage_type must be a non-empty string`);
    }
    if (input.preflight_checks !== undefined) {
        if (!Array.isArray(input.preflight_checks)) {
            errors.push(`${path}.preflight_checks must be an array when provided`);
        }
        else {
            for (let i = 0; i < input.preflight_checks.length; i += 1) {
                validatePreflightCheck(input.preflight_checks[i], `${path}.preflight_checks[${i}]`, errors);
            }
        }
    }
    if (!Array.isArray(input.steps)) {
        errors.push(`${path}.steps must be an array`);
    }
    else {
        for (let i = 0; i < input.steps.length; i += 1) {
            validateStep(input.steps[i], `${path}.steps[${i}]`, errors);
        }
    }
    if (!Array.isArray(input.asserts)) {
        errors.push(`${path}.asserts must be an array`);
    }
    else {
        for (let i = 0; i < input.asserts.length; i += 1) {
            validateAssert(input.asserts[i], `${path}.asserts[${i}]`, errors);
        }
    }
    if (!Array.isArray(input.expected_observations)) {
        errors.push(`${path}.expected_observations must be an array`);
    }
    if (input.diagnostics !== undefined) {
        if (!Array.isArray(input.diagnostics)) {
            errors.push(`${path}.diagnostics must be an array when provided`);
        }
        else {
            for (let i = 0; i < input.diagnostics.length; i += 1) {
                validateDiagnostic(input.diagnostics[i], `${path}.diagnostics[${i}]`, errors);
            }
        }
    }
    return true;
}
function validateExecutionProfile(input, path, errors) {
    if (!isObject(input)) {
        errors.push(`${path} must be an object`);
        return false;
    }
    if (!isNonEmptyString(input.intent_summary)) {
        errors.push(`${path}.intent_summary must be a non-empty string`);
    }
    if (!isNonEmptyString(input.coverage_type)) {
        errors.push(`${path}.coverage_type must be a non-empty string`);
    }
    if (input.preconditions !== undefined) {
        if (!Array.isArray(input.preconditions)) {
            errors.push(`${path}.preconditions must be an array when provided`);
        }
    }
    if (input.required_inputs !== undefined) {
        if (!Array.isArray(input.required_inputs)) {
            errors.push(`${path}.required_inputs must be an array when provided`);
        }
        else {
            for (let i = 0; i < input.required_inputs.length; i += 1) {
                validateRequiredInput(input.required_inputs[i], `${path}.required_inputs[${i}]`, errors);
            }
        }
    }
    if (!Array.isArray(input.expected_observations)) {
        errors.push(`${path}.expected_observations must be an array`);
    }
    if (input.diagnostics !== undefined) {
        if (!Array.isArray(input.diagnostics)) {
            errors.push(`${path}.diagnostics must be an array when provided`);
        }
        else {
            for (let i = 0; i < input.diagnostics.length; i += 1) {
                validateDiagnostic(input.diagnostics[i], `${path}.diagnostics[${i}]`, errors);
            }
        }
    }
    if (input.generation_confidence !== undefined && typeof input.generation_confidence !== 'number') {
        errors.push(`${path}.generation_confidence must be a number when provided`);
    }
    if (input.last_generated_plan !== undefined) {
        validateGeneratedPlan(input.last_generated_plan, `${path}.last_generated_plan`, errors);
    }
    return true;
}
function validateAssert(input, path, errors) {
    if (!isObject(input)) {
        errors.push(`${path} must be an object`);
        return false;
    }
    if (!isNonEmptyString(input.type)) {
        errors.push(`${path}.type must be a non-empty string`);
        return false;
    }
    switch (input.type) {
        case 'expect_visible':
        case 'expect_hidden':
            return validateSelector(input.selector, `${path}.selector`, errors);
        case 'expect_text':
        case 'expect_text_contains':
            if (!validateSelector(input.selector, `${path}.selector`, errors)) {
                return false;
            }
            if (!isNonEmptyString(input.text)) {
                errors.push(`${path}.text must be a non-empty string`);
                return false;
            }
            return true;
        case 'expect_url_contains':
        case 'expect_title':
            if (!isNonEmptyString(input.value)) {
                errors.push(`${path}.value must be a non-empty string`);
                return false;
            }
            return true;
        default:
            errors.push(`${path}.type has unsupported value '${String(input.type)}'`);
            return false;
    }
}
function normalizeRuntime(input, errors) {
    if (input === undefined) {
        return { ...DEFAULT_RUNTIME };
    }
    if (!isObject(input)) {
        errors.push('runtime must be an object');
        return { ...DEFAULT_RUNTIME };
    }
    const runtime = {
        headless: typeof input.headless === 'boolean' ? input.headless : DEFAULT_RUNTIME.headless,
        slow_mo_ms: isPositiveInteger(input.slow_mo_ms) ? input.slow_mo_ms : DEFAULT_RUNTIME.slow_mo_ms,
        hold_open_ms: isPositiveInteger(input.hold_open_ms) ? input.hold_open_ms : DEFAULT_RUNTIME.hold_open_ms,
        browsers: [...(DEFAULT_RUNTIME.browsers ?? ['chromium'])],
        timeout_ms: isPositiveInteger(input.timeout_ms) ? input.timeout_ms : DEFAULT_RUNTIME.timeout_ms,
        viewport: {
            width: DEFAULT_RUNTIME.viewport.width,
            height: DEFAULT_RUNTIME.viewport.height,
        },
        trace: input.trace === 'retain-on-failure' || input.trace === 'off' ? input.trace : DEFAULT_RUNTIME.trace,
        video: input.video === 'retain-on-failure' || input.video === 'off' ? input.video : DEFAULT_RUNTIME.video,
        screenshot: input.screenshot === 'only-on-failure' || input.screenshot === 'off'
            ? input.screenshot
            : DEFAULT_RUNTIME.screenshot,
    };
    if (input.viewport !== undefined) {
        if (!isObject(input.viewport)) {
            errors.push('runtime.viewport must be an object when provided');
        }
        else {
            if (isPositiveInteger(input.viewport.width)) {
                runtime.viewport.width = input.viewport.width;
            }
            else {
                errors.push('runtime.viewport.width must be a positive integer');
            }
            if (isPositiveInteger(input.viewport.height)) {
                runtime.viewport.height = input.viewport.height;
            }
            else {
                errors.push('runtime.viewport.height must be a positive integer');
            }
        }
    }
    if (input.headless !== undefined && typeof input.headless !== 'boolean') {
        errors.push('runtime.headless must be a boolean');
    }
    if (input.slow_mo_ms !== undefined &&
        (typeof input.slow_mo_ms !== 'number' || !Number.isInteger(input.slow_mo_ms) || input.slow_mo_ms < 0)) {
        errors.push('runtime.slow_mo_ms must be a non-negative integer');
    }
    if (input.hold_open_ms !== undefined &&
        (typeof input.hold_open_ms !== 'number' || !Number.isInteger(input.hold_open_ms) || input.hold_open_ms < 0)) {
        errors.push('runtime.hold_open_ms must be a non-negative integer');
    }
    if (input.timeout_ms !== undefined && !isPositiveInteger(input.timeout_ms)) {
        errors.push('runtime.timeout_ms must be a positive integer');
    }
    if (input.browsers !== undefined) {
        if (!Array.isArray(input.browsers)) {
            errors.push('runtime.browsers must be an array when provided');
        }
        else {
            const supported = ['chromium', 'firefox', 'webkit', 'msedge'];
            const normalized = [];
            for (const entry of input.browsers) {
                if (!isNonEmptyString(entry)) {
                    errors.push('runtime.browsers must contain non-empty strings');
                    continue;
                }
                if (!supported.includes(entry)) {
                    errors.push(`runtime.browsers has unsupported value '${entry}'`);
                    continue;
                }
                normalized.push(entry);
            }
            const unique = Array.from(new Set(normalized));
            if (unique.length > 0) {
                runtime.browsers = unique;
            }
        }
    }
    if (input.trace !== undefined && input.trace !== 'retain-on-failure' && input.trace !== 'off') {
        errors.push("runtime.trace must be 'retain-on-failure' or 'off'");
    }
    if (input.video !== undefined && input.video !== 'retain-on-failure' && input.video !== 'off') {
        errors.push("runtime.video must be 'retain-on-failure' or 'off'");
    }
    if (input.screenshot !== undefined && input.screenshot !== 'only-on-failure' && input.screenshot !== 'off') {
        errors.push("runtime.screenshot must be 'only-on-failure' or 'off'");
    }
    return runtime;
}
function normalizeAuth(input, errors) {
    if (input === undefined) {
        return undefined;
    }
    if (!isObject(input)) {
        errors.push('auth must be an object when provided');
        return undefined;
    }
    if (!isNonEmptyString(input.mode)) {
        errors.push('auth.mode must be a non-empty string');
        return undefined;
    }
    if (input.mode === 'ui') {
        return { mode: 'ui' };
    }
    if (input.mode !== 'api') {
        errors.push("auth.mode must be 'api' or 'ui'");
        return undefined;
    }
    const requiredFields = [
        ['auth.csrf_cookie_url', input.csrf_cookie_url],
        ['auth.login_url', input.login_url],
        ['auth.username_env', input.username_env],
        ['auth.password_env', input.password_env],
    ];
    for (const [fieldName, value] of requiredFields) {
        if (!isNonEmptyString(value)) {
            errors.push(`${fieldName} must be a non-empty string`);
        }
    }
    if (input.verify_url !== undefined && !isNonEmptyString(input.verify_url)) {
        errors.push('auth.verify_url must be a non-empty string when provided');
    }
    if (input.username_field !== undefined && !isNonEmptyString(input.username_field)) {
        errors.push('auth.username_field must be a non-empty string when provided');
    }
    if (input.password_field !== undefined && !isNonEmptyString(input.password_field)) {
        errors.push('auth.password_field must be a non-empty string when provided');
    }
    if (input.body_format !== undefined && input.body_format !== 'json' && input.body_format !== 'form') {
        errors.push("auth.body_format must be 'json' or 'form' when provided");
    }
    if (errors.length > 0) {
        return undefined;
    }
    return {
        mode: 'api',
        csrf_cookie_url: input.csrf_cookie_url,
        login_url: input.login_url,
        verify_url: input.verify_url,
        username_env: input.username_env,
        password_env: input.password_env,
        username_field: input.username_field,
        password_field: input.password_field,
        body_format: input.body_format,
    };
}
function validateRunRequest(input) {
    const errors = [];
    if (!isObject(input)) {
        return {
            ok: false,
            errors: ['run.json root must be an object'],
        };
    }
    if (input.schema_version !== '1.0') {
        errors.push("schema_version must equal '1.0'");
    }
    if (!isNonEmptyString(input.run_id)) {
        errors.push('run_id must be a non-empty string');
    }
    if (!isObject(input.target)) {
        errors.push('target must be an object');
    }
    else if (!isNonEmptyString(input.target.base_url)) {
        errors.push('target.base_url must be a non-empty string');
    }
    else if (!isHttpUrl(input.target.base_url)) {
        errors.push('target.base_url must be a valid http(s) URL');
    }
    const runtime = normalizeRuntime(input.runtime, errors);
    const auth = normalizeAuth(input.auth, errors);
    if (!Array.isArray(input.cases)) {
        errors.push('cases must be an array');
    }
    const normalizedCases = [];
    if (Array.isArray(input.cases)) {
        for (let i = 0; i < input.cases.length; i += 1) {
            const c = input.cases[i];
            const basePath = `cases[${i}]`;
            if (!isObject(c)) {
                errors.push(`${basePath} must be an object`);
                continue;
            }
            if (!isPositiveInteger(c.external_id)) {
                errors.push(`${basePath}.external_id must be a positive integer`);
            }
            if (!isNonEmptyString(c.title)) {
                errors.push(`${basePath}.title must be a non-empty string`);
            }
            if (c.severity !== undefined && c.severity !== 'minor' && c.severity !== 'major' && c.severity !== 'critical') {
                errors.push(`${basePath}.severity must be minor|major|critical when provided`);
            }
            if (!Array.isArray(c.steps)) {
                errors.push(`${basePath}.steps must be an array`);
            }
            if (!Array.isArray(c.asserts)) {
                errors.push(`${basePath}.asserts must be an array`);
            }
            if (c.use_auth !== undefined && typeof c.use_auth !== 'boolean') {
                errors.push(`${basePath}.use_auth must be a boolean when provided`);
            }
            if (c.execution_profile !== undefined) {
                validateExecutionProfile(c.execution_profile, `${basePath}.execution_profile`, errors);
            }
            if (c.generated_plan !== undefined) {
                validateGeneratedPlan(c.generated_plan, `${basePath}.generated_plan`, errors);
            }
            if (c.preflight_checks !== undefined) {
                if (!Array.isArray(c.preflight_checks)) {
                    errors.push(`${basePath}.preflight_checks must be an array when provided`);
                }
                else {
                    for (let p = 0; p < c.preflight_checks.length; p += 1) {
                        validatePreflightCheck(c.preflight_checks[p], `${basePath}.preflight_checks[${p}]`, errors);
                    }
                }
            }
            if (Array.isArray(c.steps)) {
                for (let s = 0; s < c.steps.length; s += 1) {
                    validateStep(c.steps[s], `${basePath}.steps[${s}]`, errors);
                }
            }
            if (Array.isArray(c.asserts)) {
                for (let a = 0; a < c.asserts.length; a += 1) {
                    validateAssert(c.asserts[a], `${basePath}.asserts[${a}]`, errors);
                }
            }
            if (isPositiveInteger(c.external_id) &&
                isNonEmptyString(c.title) &&
                Array.isArray(c.steps) &&
                Array.isArray(c.asserts)) {
                normalizedCases.push({
                    external_id: c.external_id,
                    title: c.title,
                    severity: c.severity,
                    use_auth: c.use_auth,
                    execution_profile: c.execution_profile,
                    generated_plan: c.generated_plan,
                    preflight_checks: c.preflight_checks,
                    steps: c.steps,
                    asserts: c.asserts,
                });
            }
        }
    }
    if (errors.length > 0) {
        return {
            ok: false,
            errors,
        };
    }
    return {
        ok: true,
        value: {
            schema_version: '1.0',
            run_id: input.run_id,
            target: {
                base_url: input.target.base_url,
            },
            runtime,
            cases: normalizedCases,
            auth,
        },
        errors: [],
    };
}
