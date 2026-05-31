"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.AmbiguousTargetError = exports.InitialNavigationError = exports.InputDataMissingError = exports.UnsupportedTestCaseError = exports.PreconditionFailureError = exports.AssertionFailureError = exports.MissingEnvVarError = void 0;
exports.normalizeError = normalizeError;
exports.summarizeResults = summarizeResults;
class MissingEnvVarError extends Error {
    variableName;
    constructor(variableName) {
        super(`Missing required environment variable: ${variableName}`);
        this.name = 'MissingEnvVarError';
        this.variableName = variableName;
    }
}
exports.MissingEnvVarError = MissingEnvVarError;
class AssertionFailureError extends Error {
    constructor(message) {
        super(message);
        this.name = 'AssertionFailureError';
    }
}
exports.AssertionFailureError = AssertionFailureError;
class PreconditionFailureError extends Error {
    constructor(message) {
        super(message);
        this.name = 'PreconditionFailureError';
    }
}
exports.PreconditionFailureError = PreconditionFailureError;
class UnsupportedTestCaseError extends Error {
    constructor(message) {
        super(message);
        this.name = 'UnsupportedTestCaseError';
    }
}
exports.UnsupportedTestCaseError = UnsupportedTestCaseError;
class InputDataMissingError extends Error {
    constructor(message) {
        super(message);
        this.name = 'InputDataMissingError';
    }
}
exports.InputDataMissingError = InputDataMissingError;
class InitialNavigationError extends Error {
    constructor(message) {
        super(message);
        this.name = 'InitialNavigationError';
    }
}
exports.InitialNavigationError = InitialNavigationError;
class AmbiguousTargetError extends Error {
    constructor(message) {
        super(message);
        this.name = 'AmbiguousTargetError';
    }
}
exports.AmbiguousTargetError = AmbiguousTargetError;
function isUrlReachabilityError(lower) {
    return [
        'err_name_not_resolved',
        'err_connection_refused',
        'err_connection_timed_out',
        'err_connection_closed',
        'err_internet_disconnected',
        'econnrefused',
        'enotfound',
        'net::',
        'dns',
        'socket hang up',
    ].some((pattern) => lower.includes(pattern));
}
function isAuthenticationError(lower) {
    return [
        'api login failed',
        'auth verify failed',
        'csrf bootstrap failed',
        'authentication failed',
        'unauthorized',
        'forbidden',
        'invalid credentials',
        'login failed',
    ].some((pattern) => lower.includes(pattern));
}
function isSelectorError(lower) {
    return lower.includes('selector') || lower.includes('locator');
}
function normalizeError(error) {
    if (error instanceof MissingEnvVarError) {
        return {
            error_type: 'missing_env_var',
            error_message: `A required environment value is missing for the automated run: ${error.variableName}.`,
        };
    }
    if (error instanceof AssertionFailureError) {
        return {
            error_type: 'assertion_failed',
            error_message: error.message,
        };
    }
    if (error instanceof PreconditionFailureError) {
        return {
            error_type: 'precondition_failed',
            error_message: error.message,
        };
    }
    if (error instanceof UnsupportedTestCaseError) {
        return {
            error_type: 'unsupported_test_case',
            error_message: error.message,
        };
    }
    if (error instanceof InputDataMissingError) {
        return {
            error_type: 'input_data_missing',
            error_message: error.message,
        };
    }
    if (error instanceof AmbiguousTargetError) {
        return {
            error_type: 'ambiguous_target',
            error_message: error.message,
        };
    }
    if (error instanceof InitialNavigationError) {
        return {
            error_type: 'url_unreachable',
            error_message: error.message,
        };
    }
    const message = error instanceof Error ? error.message : String(error);
    const lower = message.toLowerCase();
    if (isAuthenticationError(lower)) {
        return {
            error_type: 'authentication_failed',
            error_message: 'Authentication failed before or during the automated test flow.',
        };
    }
    if (lower.includes('timeout')) {
        if (lower.includes('page.goto') ||
            lower.includes('waitforurl') ||
            lower.includes('navigation')) {
            return {
                error_type: 'navigation_timeout',
                error_message: 'The page navigation did not finish in time.',
            };
        }
        if (isSelectorError(lower) || lower.includes('waitfor')) {
            return {
                error_type: 'selector_not_found',
                error_message: 'A required element did not appear before the timeout expired.',
            };
        }
        return {
            error_type: 'timeout',
            error_message: 'The automated step timed out before completion.',
        };
    }
    if (isSelectorError(lower)) {
        return {
            error_type: 'selector_not_found',
            error_message: 'A required button, field, or selector was not found on the page.',
        };
    }
    if (isUrlReachabilityError(lower)) {
        return {
            error_type: 'unexpected_navigation_state',
            error_message: 'The browser encountered an unexpected navigation or network state after the page was already being exercised.',
        };
    }
    return {
        error_type: 'unknown',
        error_message: message || 'An unknown Playwright error occurred during the automated run.',
    };
}
function summarizeResults(results) {
    return results.reduce((acc, result) => {
        acc[result.status] += 1;
        return acc;
    }, {
        passed: 0,
        failed: 0,
        blocked: 0,
        skipped: 0,
    });
}
