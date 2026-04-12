"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const config_1 = require("./config");
const runBuilder_1 = require("./runBuilder");
const runOnce_1 = require("./runOnce");
function log(event, data = {}) {
    console.log(JSON.stringify({
        ts: new Date().toISOString(),
        event,
        ...data,
    }));
}
async function main() {
    const config = (0, config_1.loadConfig)();
    log('orchestrator.started', {
        suite: config.runSuite,
        base_url: config.baseUrl,
        docker_image: config.dockerImage,
        enable_laravel: config.enableLaravel,
        smoke_pages: config.smokePages,
        auth_mode: config.authMode,
    });
    if (config.loginFlowForcedForProtectedSmoke) {
        log('orchestrator.auth_force_enabled', {
            reason: 'AUTH_MODE=api with protected smoke pages requires login flow',
        });
    }
    const runRequest = (0, runBuilder_1.buildRunRequest)(config);
    const outcome = await (0, runOnce_1.runOnceWithRunSpec)(runRequest, log, config);
    process.exit(outcome.exitCode);
}
main().catch((error) => {
    const message = error instanceof Error ? error.message : String(error);
    log('orchestrator.failed', { message });
    process.exit(3);
});
