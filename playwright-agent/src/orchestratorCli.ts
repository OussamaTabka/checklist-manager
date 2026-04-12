import { spawn } from 'node:child_process';
import path from 'node:path';

async function runSpawn(opts: {
  command: string;
  args: string[];
  cwd: string;
}): Promise<{ exitCode: number; stdout: string; stderr: string }> {
  return await new Promise((resolve, reject) => {
    const child = spawn(opts.command, opts.args, {
      cwd: opts.cwd,
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    let stdout = '';
    let stderr = '';

    child.stdout.on('data', (d) => (stdout += d.toString()));
    child.stderr.on('data', (d) => (stderr += d.toString()));
    child.on('error', reject);
    child.on('close', (code) => resolve({ exitCode: code ?? 3, stdout, stderr }));
  });
}

export async function runOrchestratorFromDsl(opts: {
  orchestratorDir: string;
  dslPath: string;
}): Promise<{ exitCode: number; stdout: string; stderr: string }> {
  const cwd = path.resolve(opts.orchestratorDir);
  const dslAbs = path.resolve(opts.dslPath);
  const dslArg = path.relative(cwd, dslAbs) || path.basename(dslAbs);

  if (process.platform !== 'win32') {
    return await runSpawn({
      command: 'npm',
      args: ['run', 'run:from-dsl', '--', dslArg],
      cwd,
    });
  }

  try {
    return await runSpawn({
      command: 'npm.cmd',
      args: ['run', 'run:from-dsl', '--', dslArg],
      cwd,
    });
  } catch (error) {
    const err = error as NodeJS.ErrnoException;
    if (err.code !== 'EINVAL') {
      throw error;
    }

    return await runSpawn({
      command: 'cmd.exe',
      args: ['/d', '/s', '/c', `npm run run:from-dsl -- ${dslArg}`],
      cwd,
    });
  }
}
