"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.AssertionFailureError = exports.MissingEnvVarError = void 0;
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
function normalizeError(error) {
    if (error instanceof MissingEnvVarError) {
        return {
            error_type: 'missing_env_var',
            error_message: error.message,
        };
    }
    if (error instanceof AssertionFailureError) {
        return {
            error_type: 'assertion_failed',
            error_message: error.message,
        };
    }
    const message = error instanceof Error ? error.message : String(error);
    const lower = message.toLowerCase();
    if (lower.includes('timeout')) {
        if (lower.includes('page.goto') ||
            lower.includes('waitforurl') ||
            lower.includes('navigation')) {
            return {
                error_type: 'navigation_timeout',
                error_message: message,
            };
        }
        if (lower.includes('selector') || lower.includes('locator') || lower.includes('waitfor')) {
            return {
                error_type: 'selector_not_found',
                error_message: message,
            };
        }
    }
    if (lower.includes('selector') || lower.includes('locator')) {
        return {
            error_type: 'selector_not_found',
            error_message: message,
        };
    }
    if (lower.includes('navigation') && lower.includes('timeout')) {
        return {
            error_type: 'navigation_timeout',
            error_message: message,
        };
    }
    return {
        error_type: 'unexpected_error',
        error_message: message,
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
