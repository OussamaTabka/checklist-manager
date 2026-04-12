import { promises as fs } from 'node:fs'
import path from 'node:path'

export interface ArtifactsConfig {
  workRoot: string
  artifactsRoot: string
}

export function sanitizeFilename(name: string): string {
  const safe = name.trim().replace(/[^a-zA-Z0-9._-]+/g, '_')
  if (safe.length === 0) {
    return 'file'
  }
  return safe
}

export async function ensureDir(dirPath: string): Promise<void> {
  await fs.mkdir(dirPath, { recursive: true })
}

export async function ensureCaseArtifactsDir(config: ArtifactsConfig, externalId: number): Promise<string> {
  const dir = path.join(config.artifactsRoot, String(externalId))
  await ensureDir(dir)
  return dir
}

export function toRelativeWorkPath(config: ArtifactsConfig, absolutePath: string): string {
  return path.relative(config.workRoot, absolutePath).split(path.sep).join('/')
}

export async function deleteIfExists(filePath: string): Promise<void> {
  try {
    await fs.unlink(filePath)
  } catch (error) {
    const e = error as NodeJS.ErrnoException
    // Best-effort cleanup: Windows can briefly lock fresh video files (EBUSY/EPERM).
    if (e.code !== 'ENOENT' && e.code !== 'EBUSY' && e.code !== 'EPERM') {
      throw error
    }
  }
}

export async function moveOrCopyFile(source: string, destination: string): Promise<void> {
  try {
    await fs.rename(source, destination)
  } catch {
    await fs.copyFile(source, destination)
    await deleteIfExists(source)
  }
}
