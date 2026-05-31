"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const node_fs_1 = require("node:fs");
const node_path_1 = __importDefault(require("node:path"));
const artifacts_1 = require("./engine/artifacts");
const dsl_1 = require("./engine/dsl");
const executor_1 = require("./engine/executor");
const EXIT_OK = 0;
const EXIT_TESTS_FAILED = 1;
const EXIT_INVALID_SPEC = 2;
const EXIT_RUNNER_ERROR = 3;
function getEnvPath(name, fallback) {
    const value = process.env[name];
    if (!value || value.trim().length === 0) {
        return fallback;
    }
    return value;
}
function getOptionalEnvPath(name) {
    const value = process.env[name];
    if (!value || value.trim().length === 0) {
        return undefined;
    }
    return value;
}
async function writeJsonFile(filePath, payload) {
    const dir = node_path_1.default.dirname(filePath);
    await (0, artifacts_1.ensureDir)(dir);
    await node_fs_1.promises.writeFile(filePath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
}
function createBaseResult(runId, status, runnerRunId) {
    return {
        schema_version: '1.0',
        run_id: runId,
        runner_run_id: runnerRunId,
        status,
        summary: {
            passed: 0,
            failed: 0,
            blocked: 0,
            skipped: 0,
        },
        results: [],
    };
}
async function readRunJson(runJsonPath) {
    const content = await node_fs_1.promises.readFile(runJsonPath, 'utf8');
    return JSON.parse(content);
}
async function main() {
    const runJsonPath = getEnvPath('RUN_JSON_PATH', '/work/run.json');
    const resultJsonPath = getEnvPath('RESULT_JSON_PATH', '/work/result.json');
    const artifactsDir = getEnvPath('ARTIFACTS_DIR', '/work/artifacts');
    const liveTracePath = getOptionalEnvPath('LIVE_TRACE_PATH');
    const workRoot = node_path_1.default.dirname(resultJsonPath);
    const runnerRunId = `runner-${Date.now()}`;
    let runRaw;
    try {
        runRaw = await readRunJson(runJsonPath);
    }
    catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        console.error(`[runner_error] cannot read run.json: ${message}`);
        const result = createBaseResult('unknown', 'runner_error', runnerRunId);
        await writeJsonFile(resultJsonPath, result);
        process.exit(EXIT_RUNNER_ERROR);
    }
    const validation = (0, dsl_1.validateRunRequest)(runRaw);
    if (!validation.ok || !validation.value) {
        const runId = typeof runRaw === 'object' &&
            runRaw !== null &&
            'run_id' in runRaw &&
            typeof runRaw.run_id === 'string'
            ? runRaw.run_id
            : 'unknown';
        console.error('[invalid_spec] run.json validation failed');
        for (const err of validation.errors) {
            console.error(` - ${err}`);
        }
        const result = createBaseResult(runId, 'invalid_spec', runnerRunId);
        await writeJsonFile(resultJsonPath, result);
        process.exit(EXIT_INVALID_SPEC);
    }
    const request = validation.value;
    try {
        const runResult = await (0, executor_1.executeRun)(request, {
            artifactsConfig: {
                workRoot,
                artifactsRoot: artifactsDir,
            },
            liveTracePath,
        });
        await writeJsonFile(resultJsonPath, runResult);
        if (runResult.status === 'done' && runResult.summary.failed > 0) {
            process.exit(EXIT_TESTS_FAILED);
        }
        process.exit(EXIT_OK);
    }
    catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        console.error(`[runner_error] ${message}`);
        const result = createBaseResult(request.run_id, 'runner_error', runnerRunId);
        await writeJsonFile(resultJsonPath, result);
        process.exit(EXIT_RUNNER_ERROR);
    }
}
void main();
