"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.sanitizeFilename = sanitizeFilename;
exports.ensureDir = ensureDir;
exports.ensureCaseArtifactsDir = ensureCaseArtifactsDir;
exports.toRelativeWorkPath = toRelativeWorkPath;
exports.deleteIfExists = deleteIfExists;
exports.moveOrCopyFile = moveOrCopyFile;
const node_fs_1 = require("node:fs");
const node_path_1 = __importDefault(require("node:path"));
function sanitizeFilename(name) {
    const safe = name.trim().replace(/[^a-zA-Z0-9._-]+/g, '_');
    if (safe.length === 0) {
        return 'file';
    }
    return safe;
}
async function ensureDir(dirPath) {
    await node_fs_1.promises.mkdir(dirPath, { recursive: true });
}
async function ensureCaseArtifactsDir(config, externalId) {
    const dir = node_path_1.default.join(config.artifactsRoot, String(externalId));
    await ensureDir(dir);
    return dir;
}
function toRelativeWorkPath(config, absolutePath) {
    return node_path_1.default.relative(config.workRoot, absolutePath).split(node_path_1.default.sep).join('/');
}
async function deleteIfExists(filePath) {
    try {
        await node_fs_1.promises.unlink(filePath);
    }
    catch (error) {
        const e = error;
        // Best-effort cleanup: Windows can briefly lock fresh video files (EBUSY/EPERM).
        if (e.code !== 'ENOENT' && e.code !== 'EBUSY' && e.code !== 'EPERM') {
            throw error;
        }
    }
}
async function moveOrCopyFile(source, destination) {
    try {
        await node_fs_1.promises.rename(source, destination);
    }
    catch {
        await node_fs_1.promises.copyFile(source, destination);
        await deleteIfExists(source);
    }
}
