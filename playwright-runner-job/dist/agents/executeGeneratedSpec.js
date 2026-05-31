"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || (function () {
    var ownKeys = function(o) {
        ownKeys = Object.getOwnPropertyNames || function (o) {
            var ar = [];
            for (var k in o) if (Object.prototype.hasOwnProperty.call(o, k)) ar[ar.length] = k;
            return ar;
        };
        return ownKeys(o);
    };
    return function (mod) {
        if (mod && mod.__esModule) return mod;
        var result = {};
        if (mod != null) for (var k = ownKeys(mod), i = 0; i < k.length; i++) if (k[i] !== "default") __createBinding(result, mod, k[i]);
        __setModuleDefault(result, mod);
        return result;
    };
})();
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.mapGeneratedSpecFailure = mapGeneratedSpecFailure;
exports.readExecutionInput = readExecutionInput;
exports.executeGeneratedSpec = executeGeneratedSpec;
const node_fs_1 = require("node:fs");
const node_path_1 = __importDefault(require("node:path"));
const childProcess = __importStar(require("node:child_process"));
function mapGeneratedSpecFailure(message) {
    const normalized = message.trim();
    if (/ERR_NAME_NOT_RESOLVED|ERR_CONNECTION_REFUSED|ERR_CONNECTION_TIMED_OUT|Cannot navigate to invalid URL/i.test(normalized)) {
        return {
            status: 'blocked',
            error_type: 'url_unreachable',
            error_message: normalized,
            failure_source: {
                phase: 'initial_navigation',
                reference: 'url_unreachable',
                message: normalized,
            },
        };
    }
    if (/expect\(|assert|Expected:/i.test(normalized)) {
        return {
            status: 'failed',
            error_type: 'assertion_failed',
            error_message: normalized,
            failure_source: {
                phase: 'assert',
                reference: 'generated_spec_assertion',
                message: normalized,
            },
        };
    }
    return {
        status: 'blocked',
        error_type: 'generated_spec_execution_failed',
        error_message: normalized,
        failure_source: {
            phase: 'runtime',
            reference: 'generated_spec_runtime',
            message: normalized,
        },
    };
}
async function readExecutionInput(inputPath) {
    const raw = await node_fs_1.promises.readFile(inputPath, 'utf8');
    return JSON.parse(raw);
}
async function executeGeneratedSpec(input) {
    const startedAt = Date.now();
    const outputDir = node_path_1.default.resolve(input.output_dir);
    const specFile = node_path_1.default.resolve(input.spec_file);
    const configPath = node_path_1.default.join(outputDir, 'playwright.generated.config.cjs');
    const reportPath = node_path_1.default.join(outputDir, 'playwright-report.json');
    const resultsDir = node_path_1.default.join(outputDir, 'test-results');
    await node_fs_1.promises.mkdir(outputDir, { recursive: true });
    await node_fs_1.promises.mkdir(resultsDir, { recursive: true });
    const configContent = [
        'module.exports = {',
        `  testDir: ${JSON.stringify(node_path_1.default.dirname(specFile))},`,
        `  outputDir: ${JSON.stringify(resultsDir)},`,
        `  use: { baseURL: ${JSON.stringify(input.base_url)}, trace: 'on', video: 'retain-on-failure', screenshot: 'only-on-failure' },`,
        `  reporter: [['json', { outputFile: ${JSON.stringify(reportPath)} }]],`,
        '};',
        '',
    ].join('\n');
    await node_fs_1.promises.writeFile(configPath, configContent, 'utf8');
    const runnerArgs = ['playwright', 'test', specFile, '--config', configPath];
    const command = process.platform === 'win32' ? 'npx.cmd' : 'npx';
    const { code, stderr } = await new Promise((resolve) => {
        const child = childProcess.spawn(command, runnerArgs, {
            cwd: outputDir,
            env: {
                ...process.env,
                BASE_URL: input.base_url,
                PLAYWRIGHT_GENERATED_PROVIDED_INPUTS: JSON.stringify(input.provided_inputs ?? {}),
            },
            windowsHide: true,
            stdio: ['ignore', 'ignore', 'pipe'],
        });
        let errorOutput = '';
        child.stderr.setEncoding('utf8');
        child.stderr.on('data', (chunk) => {
            errorOutput += chunk;
        });
        child.on('close', (exitCode) => {
            resolve({ code: exitCode ?? 1, stderr: errorOutput.trim() });
        });
    });
    const artifacts = await collectArtifacts(outputDir);
    const durationMs = Date.now() - startedAt;
    if (code === 0) {
        return {
            status: 'passed',
            duration_ms: durationMs,
            execution_trace: ['Generated Playwright spec passed.'],
            artifacts,
            error_type: null,
            error_message: null,
        };
    }
    const mapped = mapGeneratedSpecFailure(stderr || 'Generated Playwright spec failed.');
    return {
        status: mapped.status,
        duration_ms: durationMs,
        execution_trace: [mapped.error_message ?? 'Generated Playwright spec failed.'],
        artifacts,
        error_type: mapped.error_type,
        error_message: mapped.error_message,
        failure_source: mapped.failure_source,
    };
}
async function collectArtifacts(outputDir) {
    const artifacts = [];
    async function walk(currentPath) {
        const entries = await node_fs_1.promises.readdir(currentPath, { withFileTypes: true });
        for (const entry of entries) {
            const absolutePath = node_path_1.default.join(currentPath, entry.name);
            if (entry.isDirectory()) {
                await walk(absolutePath);
                continue;
            }
            if (/\.(zip|png|webm|json)$/i.test(entry.name)) {
                artifacts.push(node_path_1.default.relative(outputDir, absolutePath).replaceAll('\\', '/'));
            }
        }
    }
    await walk(outputDir);
    return artifacts.sort();
}
async function main() {
    const inputPath = process.argv[2];
    if (!inputPath) {
        process.stdout.write(`${JSON.stringify({
            status: 'blocked',
            duration_ms: 0,
            execution_trace: [],
            artifacts: [],
            error_type: 'missing_input',
            error_message: 'Missing execution input path.',
        })}\n`);
        process.exit(1);
    }
    try {
        const input = await readExecutionInput(inputPath);
        const result = await executeGeneratedSpec(input);
        process.stdout.write(`${JSON.stringify(result)}\n`);
        process.exit(result.status === 'passed' ? 0 : 1);
    }
    catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        process.stdout.write(`${JSON.stringify({
            status: 'blocked',
            duration_ms: 0,
            execution_trace: [message],
            artifacts: [],
            error_type: 'generated_spec_execution_failed',
            error_message: message,
        })}\n`);
        process.exit(1);
    }
}
if (require.main === module) {
    void main();
}
