"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.runDockerJob = runDockerJob;
const node_child_process_1 = require("node:child_process");
const node_path_1 = __importDefault(require("node:path"));
function dockerCandidates() {
    const fromEnv = process.env.DOCKER_BIN?.trim();
    const candidates = fromEnv ? [fromEnv] : [];
    if (process.platform === 'win32') {
        candidates.push('docker.exe', 'docker', 'C:/Program Files/Docker/Docker/resources/bin/docker.exe', 'C:/ProgramData/DockerDesktop/version-bin/docker.exe');
    }
    else {
        candidates.push('docker');
    }
    return Array.from(new Set(candidates));
}
function spawnDocker(executable, args, cwd) {
    return new Promise((resolve, reject) => {
        const child = (0, node_child_process_1.spawn)(executable, args, {
            stdio: 'inherit',
            cwd,
        });
        child.on('error', (error) => {
            reject(error);
        });
        child.on('close', (code) => {
            resolve({
                exitCode: code ?? 1,
            });
        });
    });
}
function shouldInjectLinuxHostGateway(baseUrl, config) {
    if (process.platform !== 'linux') {
        return false;
    }
    if (!config.dockerUseHostGateway) {
        return false;
    }
    return baseUrl.includes('host.docker.internal') || baseUrl.includes('localhost');
}
async function runDockerJob(config, hostRunDir) {
    const args = ['run', '--rm'];
    if (shouldInjectLinuxHostGateway(config.baseUrl, config)) {
        args.push('--add-host=host.docker.internal:host-gateway');
    }
    args.push('--mount', `type=bind,source=${hostRunDir},target=/work`);
    args.push('-e', 'RUN_JSON_PATH=/work/run.json', '-e', 'RESULT_JSON_PATH=/work/result.json', '-e', 'ARTIFACTS_DIR=/work/artifacts');
    if (config.e2eEmail) {
        args.push('-e', `E2E_EMAIL=${config.e2eEmail}`);
    }
    if (config.e2ePassword) {
        args.push('-e', `E2E_PASSWORD=${config.e2ePassword}`);
    }
    if (config.dockerExtraArgs.length > 0) {
        args.push(...config.dockerExtraArgs);
    }
    args.push(config.dockerImage);
    const cwd = node_path_1.default.resolve(process.cwd());
    const candidates = dockerCandidates();
    let lastError;
    for (const candidate of candidates) {
        try {
            return await spawnDocker(candidate, args, cwd);
        }
        catch (error) {
            const isEnoent = typeof error === 'object' &&
                error !== null &&
                'code' in error &&
                error.code === 'ENOENT';
            if (isEnoent) {
                lastError = error;
                continue;
            }
            throw error;
        }
    }
    throw lastError instanceof Error
        ? lastError
        : new Error('Unable to spawn docker: executable not found');
}
