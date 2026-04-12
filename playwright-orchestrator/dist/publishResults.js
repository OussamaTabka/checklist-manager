"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.publishResults = publishResults;
const laravelClient_1 = require("./laravelClient");
async function publishResults(config, runId, result) {
    if (!config.enableLaravel || !config.enablePublishResults) {
        return 'skipped';
    }
    if (config.enableBatchResults) {
        try {
            await (0, laravelClient_1.publishBatchResults)(config, runId, result);
            return 'batch';
        }
        catch (error) {
            if (!config.enableFallbackPatch) {
                throw error;
            }
        }
    }
    if (config.enableFallbackPatch) {
        await (0, laravelClient_1.patchVersionItemsFallback)(config, result);
        return 'fallback';
    }
    return 'skipped';
}
