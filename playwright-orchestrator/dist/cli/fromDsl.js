"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const node_fs_1 = __importDefault(require("node:fs"));
const node_path_1 = __importDefault(require("node:path"));
const runOnce_1 = require("../runOnce");
const config_1 = require("../config");
function runSpecRequiresAuth(runSpec) {
    if (!Array.isArray(runSpec.cases)) {
        throw new Error('[from-dsl] invalid run spec: cases must be an array');
    }
    // `use_auth` defaults to enabled when omitted in runner behavior.
    return runSpec.cases.some((runCase) => runCase.use_auth !== false);
}
function withAuthFromConfig(runSpec) {
    if (runSpec.auth) {
        return runSpec;
    }
    if (!runSpecRequiresAuth(runSpec)) {
        return runSpec;
    }
    let config;
    try {
        config = (0, config_1.loadConfig)();
    }
    catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        throw new Error(`[from-dsl] run spec requires auth but auth injection failed to load config: ${message}`);
    }
    if (config.authMode === 'api' && config.enableLoginFlow) {
        console.error('[from-dsl] injected auth from config because run spec missing auth block');
        return {
            ...runSpec,
            auth: {
                mode: 'api',
                csrf_cookie_url: config.sanctumCsrfCookiePath,
                login_url: config.loginPath,
                verify_url: config.authVerifyPath,
                username_env: 'E2E_EMAIL',
                password_env: 'E2E_PASSWORD',
                username_field: 'email',
                password_field: 'password',
                body_format: config.authBodyFormat,
            },
        };
    }
    if (config.authMode === 'ui') {
        console.error('[from-dsl] injected auth from config because run spec missing auth block');
        return {
            ...runSpec,
            auth: {
                mode: 'ui',
            },
        };
    }
    throw new Error('[from-dsl] run spec requires auth but no auth block was provided and config does not allow injection. Set ENABLE_LOGIN_FLOW=true with AUTH_MODE=api, switch AUTH_MODE=ui, or provide runSpec.auth explicitly.');
}
function usageAndExit() {
    console.error('Usage: npm run run:from-dsl -- <path-to-run.json>');
    process.exit(3);
}
async function main() {
    const dslPath = process.argv[2];
    if (!dslPath) {
        usageAndExit();
    }
    const absoluteDslPath = node_path_1.default.resolve(dslPath);
    if (!node_fs_1.default.existsSync(absoluteDslPath)) {
        console.error(`DSL file not found: ${absoluteDslPath}`);
        process.exit(3);
    }
    const raw = node_fs_1.default.readFileSync(absoluteDslPath, 'utf8');
    const parsedRunSpec = JSON.parse(raw);
    if (!process.env.BASE_URL && parsedRunSpec?.target?.base_url) {
        process.env.BASE_URL = parsedRunSpec.target.base_url;
    }
    const runSpec = withAuthFromConfig(parsedRunSpec);
    const result = await (0, runOnce_1.runOnceWithRunSpec)(runSpec, () => { });
    process.stdout.write(JSON.stringify(result, null, 2));
    process.exit(result.exitCode ?? 0);
}
main().catch((error) => {
    console.error(error);
    process.exit(3);
});
