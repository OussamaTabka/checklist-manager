"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.runOnce = runOnce;
exports.runOnceWithRunSpec = runOnceWithRunSpec;
const node_fs_1 = require("node:fs");
const node_path_1 = __importDefault(require("node:path"));
const dockerRunner_1 = require("./dockerRunner");
const laravelClient_1 = require("./laravelClient");
const publishResults_1 = require("./publishResults");
const config_1 = require("./config");
function defaultLog(event, data = {}) {
    console.log(JSON.stringify({
        ts: new Date().toISOString(),
        event,
        ...data,
    }));
}
async function ensureDir(dirPath) {
    await node_fs_1.promises.mkdir(dirPath, { recursive: true });
}
async function writeJson(filePath, payload) {
    await ensureDir(node_path_1.default.dirname(filePath));
    await node_fs_1.promises.writeFile(filePath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
}
async function readJson(filePath) {
    const content = await node_fs_1.promises.readFile(filePath, 'utf8');
    return JSON.parse(content);
}
function ensureRunnerResultShape(payload) {
    if (typeof payload !== 'object' || payload === null) {
        throw new Error('result.json is not a valid JSON object');
    }
    const candidate = payload;
    if (candidate.schema_version !== '1.0') {
        throw new Error('result.json schema_version is invalid');
    }
    if (!Array.isArray(candidate.results)) {
        throw new Error('result.json results must be an array');
    }
    if (!candidate.summary ||
        typeof candidate.summary !== 'object' ||
        typeof candidate.summary.passed !== 'number' ||
        typeof candidate.summary.failed !== 'number' ||
        typeof candidate.summary.blocked !== 'number' ||
        typeof candidate.summary.skipped !== 'number') {
        throw new Error('result.json summary is invalid');
    }
    if (candidate.status !== 'done' &&
        candidate.status !== 'invalid_spec' &&
        candidate.status !== 'runner_error') {
        throw new Error('result.json status is invalid');
    }
    return candidate;
}
function buildInfraFailureResult(runRequest, reason) {
    const results = runRequest.cases.map((testCase) => ({
        external_id: testCase.external_id,
        status: 'blocked',
        attempt: 1,
        duration_ms: 0,
        error_type: 'infra_error',
        error_message: reason,
        artifacts: {
            trace_path: null,
            screenshot_path: null,
            video_path: null,
        },
    }));
    return {
        schema_version: '1.0',
        run_id: runRequest.run_id,
        runner_run_id: `orchestrator-${Date.now()}`,
        status: 'runner_error',
        summary: {
            passed: 0,
            failed: 0,
            blocked: results.length,
            skipped: 0,
        },
        results,
    };
}
function ensureRunId(runRequest) {
    if (runRequest.run_id && runRequest.run_id.trim() !== '') {
        return runRequest.run_id.trim();
    }
    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const dd = String(now.getDate()).padStart(2, '0');
    const hh = String(now.getHours()).padStart(2, '0');
    const min = String(now.getMinutes()).padStart(2, '0');
    const sec = String(now.getSeconds()).padStart(2, '0');
    return `tr-${yyyy}${mm}${dd}-${hh}${min}${sec}`;
}
function resolveExitCode(result, dockerExitCode) {
    if (result.status === 'invalid_spec') {
        return 2;
    }
    if (result.status === 'runner_error') {
        return 3;
    }
    return dockerExitCode === 0 ? 0 : 3;
}
function normalizeExitCode(exitCode) {
    if (exitCode === 0) {
        return 0;
    }
    if (exitCode === 2) {
        return 2;
    }
    return 3;
}
async function runOnce(config, inputRunRequest, logger = defaultLog) {
    let runRequest = {
        ...inputRunRequest,
        run_id: ensureRunId(inputRunRequest),
    };
    let runId = runRequest.run_id;
    if (config.enableLaravel && config.enableCreateRun) {
        const created = await (0, laravelClient_1.createRunInLaravel)(config, runRequest);
        runId = created.run_id;
        runRequest = {
            ...runRequest,
            run_id: runId,
        };
        logger('laravel.run_created', {
            run_id: runId,
            status: created.status,
        });
    }
    const hostRunDir = node_path_1.default.join(config.runsRoot, runId);
    const runJsonPath = node_path_1.default.join(hostRunDir, 'run.json');
    const resultJsonPath = node_path_1.default.join(hostRunDir, 'result.json');
    const artifactsDir = node_path_1.default.join(hostRunDir, 'artifacts');
    await ensureDir(artifactsDir);
    await writeJson(runJsonPath, runRequest);
    logger('run.files_written', {
        run_id: runId,
        host_run_dir: hostRunDir,
    });
    let dockerOutcome = { exitCode: 3 };
    let dockerFailureMessage = null;
    try {
        dockerOutcome = await (0, dockerRunner_1.runDockerJob)(config, hostRunDir);
        logger('docker.completed', {
            run_id: runId,
            exit_code: dockerOutcome.exitCode,
        });
    }
    catch (error) {
        dockerFailureMessage = error instanceof Error ? error.message : String(error);
        logger('docker.failed', {
            run_id: runId,
            message: dockerFailureMessage,
        });
    }
    let result;
    try {
        const rawResult = await readJson(resultJsonPath);
        result = ensureRunnerResultShape(rawResult);
    }
    catch (error) {
        const resultLoadError = error instanceof Error ? error.message : String(error);
        const reason = dockerFailureMessage
            ? `docker_failed: ${dockerFailureMessage}`
            : dockerOutcome.exitCode !== 0
                ? `docker_exit_code_${dockerOutcome.exitCode}; missing_or_invalid_result_json: ${resultLoadError}`
                : `missing_or_invalid_result_json: ${resultLoadError}`;
        result = buildInfraFailureResult(runRequest, reason);
        await writeJson(resultJsonPath, result);
        logger('runner.result_fallback_written', {
            run_id: runId,
            reason,
        });
    }
    logger('runner.result_loaded', {
        run_id: result.run_id,
        runner_status: result.status,
        summary: result.summary,
    });
    const publishMode = await (0, publishResults_1.publishResults)(config, runId, result);
    logger('laravel.publish_done', {
        run_id: runId,
        mode: publishMode,
    });
    return {
        runId,
        summary: result.summary,
        laravelPublishMode: publishMode,
        exitCode: resolveExitCode(result, dockerOutcome.exitCode),
    };
}
async function runOnceWithRunSpec(runSpec, logger = defaultLog, configOverride) {
    const config = configOverride ?? (0, config_1.loadConfig)();
    const outcome = await runOnce(config, runSpec, logger);
    return {
        exitCode: normalizeExitCode(outcome.exitCode),
        run_id: outcome.runId,
        summary: {
            passed: outcome.summary.passed,
            failed: outcome.summary.failed,
            blocked: outcome.summary.blocked,
            skipped: outcome.summary.skipped,
        },
        publish_mode: outcome.laravelPublishMode,
    };
}
