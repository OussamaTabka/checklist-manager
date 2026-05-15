<?php

namespace App\Services;

use App\Models\ChecklistItem;
use App\Models\VersionItem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ExecutionProfileService
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array{run_spec: array<string, mixed>, execution_profile: array<string, mixed>}
     */
    public function generateForVersionItem(VersionItem $item, ?string $baseUrl = null, array $overrides = []): array
    {
        $item->loadMissing('version.project');

        $resolvedBaseUrl = $this->resolveBaseUrl(
            $baseUrl,
            $item->version?->project?->app_url,
            'http://localhost:5173',
        );

        return $this->generateForPayload(
            [
                'external_id' => $item->id,
                'test_case_title' => (string) $item->title,
                'test_case_description' => (string) ($item->description ?? ''),
                'test_case_text' => trim((string) $item->title . "\n" . (string) ($item->description ?? '')),
                'base_url' => $resolvedBaseUrl,
                'use_auth' => true,
                'environment_name' => '',
                'notes' => '',
                'priority' => (string) ($item->priority ?? ''),
                'criticality' => (string) ($item->criticality ?? ''),
                'current_status' => (string) ($item->status ?? ''),
                'project_version_id' => (int) $item->project_version_id,
                'target_type' => 'version_item',
            ],
            $overrides,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{run_spec: array<string, mixed>, execution_profile: array<string, mixed>}
     */
    public function generateForChecklistItem(ChecklistItem $item, ?string $baseUrl = null, array $overrides = []): array
    {
        $item->loadMissing('checklist.project');

        $resolvedBaseUrl = $this->resolveBaseUrl(
            $baseUrl,
            $item->checklist?->project?->app_url,
            'http://localhost:5173',
        );

        return $this->generateForPayload(
            [
                'external_id' => $item->id,
                'test_case_title' => (string) $item->title,
                'test_case_description' => (string) ($item->description ?? ''),
                'test_case_text' => trim((string) $item->title . "\n" . (string) ($item->description ?? '')),
                'base_url' => $resolvedBaseUrl,
                'use_auth' => true,
                'environment_name' => '',
                'notes' => '',
                'priority' => (string) ($item->priority ?? ''),
                'criticality' => (string) ($item->criticality ?? ''),
                'current_status' => (string) ($item->status ?? ''),
                'target_type' => 'checklist_item',
                'checklist_id' => (int) $item->checklist_id,
            ],
            $overrides,
        );
    }

    /**
     * @param  array<string, mixed>  $basePayload
     * @param  array<string, mixed>  $overrides
     * @return array{run_spec: array<string, mixed>, execution_profile: array<string, mixed>}
     */
    public function generateForPayload(array $basePayload, array $overrides = []): array
    {
        $workspaceRoot = $this->resolveWorkspaceRoot();
        $agentDir = $workspaceRoot . DIRECTORY_SEPARATOR . 'playwright-agent';
        $agentEntrypoint = $this->resolveAgentEntrypoint($agentDir);

        $payload = array_merge(
            [
                'run_id' => 'profile-preview-' . uniqid(),
            ],
            $basePayload,
            $overrides,
        );

        $tmpDir = $workspaceRoot . DIRECTORY_SEPARATOR . 'playwright-orchestrator' . DIRECTORY_SEPARATOR . 'tmp';
        $this->ensureDirectory($tmpDir);

        $agentInputPath = $tmpDir . DIRECTORY_SEPARATOR . 'profile-input-' . uniqid() . '.json';

        try {
            file_put_contents($agentInputPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            $output = $this->runCommand(
                ['node', $agentEntrypoint, '--input', $agentInputPath],
                $agentDir,
                [],
                180,
            );

            $runSpec = json_decode(trim($output), true);
            if (!is_array($runSpec)) {
                throw new \RuntimeException('Agent output is not valid JSON.');
            }

            $executionProfile = $this->extractExecutionProfile($runSpec);

            return [
                'run_spec' => $runSpec,
                'execution_profile' => $executionProfile,
            ];
        } finally {
            if (is_file($agentInputPath)) {
                @unlink($agentInputPath);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $runSpec
     * @return array<string, mixed>
     */
    public function extractExecutionProfile(array $runSpec): array
    {
        $case = is_array($runSpec['cases'][0] ?? null) ? $runSpec['cases'][0] : [];
        $profile = is_array($case['execution_profile'] ?? null) ? $case['execution_profile'] : [];

        $generatedPlan = [
            'title' => (string) ($case['title'] ?? 'Generated execution plan'),
            'intent_summary' => (string) ($profile['intent_summary'] ?? ''),
            'coverage_type' => (string) ($profile['coverage_type'] ?? 'generic_ui'),
            'preflight_checks' => is_array($case['preflight_checks'] ?? null) ? $case['preflight_checks'] : [],
            'steps' => is_array($case['steps'] ?? null) ? $case['steps'] : [],
            'asserts' => is_array($case['asserts'] ?? null) ? $case['asserts'] : [],
            'expected_observations' => is_array($profile['expected_observations'] ?? null) ? $profile['expected_observations'] : [],
            'diagnostics' => is_array($profile['diagnostics'] ?? null) ? $profile['diagnostics'] : [],
        ];

        $profile['preconditions'] = is_array($profile['preconditions'] ?? null) ? $profile['preconditions'] : [];
        $profile['required_inputs'] = is_array($profile['required_inputs'] ?? null) ? $profile['required_inputs'] : [];
        $profile['expected_observations'] = is_array($profile['expected_observations'] ?? null) ? $profile['expected_observations'] : [];
        $profile['diagnostics'] = is_array($profile['diagnostics'] ?? null) ? $profile['diagnostics'] : [];
        $profile['last_generated_plan'] = $generatedPlan;

        return $profile;
    }

    private function resolveWorkspaceRoot(): string
    {
        $workspaceRoot = realpath(base_path('..'));
        if (!$workspaceRoot) {
            throw new \RuntimeException('Unable to resolve workspace root from backend path.');
        }

        return $workspaceRoot;
    }

    private function resolveAgentEntrypoint(string $agentDir): string
    {
        $distEntrypoint = $agentDir . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'generateRunSpec.js';
        if (is_file($distEntrypoint)) {
            return $distEntrypoint;
        }

        $srcEntrypoint = $agentDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'generateRunSpec.js';
        if (is_file($srcEntrypoint)) {
            return $srcEntrypoint;
        }

        throw new \RuntimeException('Missing agent entrypoint generateRunSpec.js. Run npm run build in playwright-agent.');
    }

    private function resolveBaseUrl(?string $preferred, ?string $fallback, string $default): string
    {
        $candidate = trim((string) ($preferred ?: $fallback ?: $default));
        return rtrim($candidate, '/');
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to create directory: ' . $path);
        }
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $extraEnv
     */
    private function runCommand(array $command, string $workingDirectory, array $extraEnv = [], int $timeoutSeconds = 300): string
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS') && strtolower((string) ($command[0] ?? '')) === 'npm') {
            $command[0] = 'npm.cmd';
        }

        $process = new Process($command, $workingDirectory, $this->buildProcessEnvironment($extraEnv));
        $process->setTimeout($timeoutSeconds);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }

    /**
     * @param  array<string, string>  $extraEnv
     * @return array<string, mixed>
     */
    private function buildProcessEnvironment(array $extraEnv = []): array
    {
        $environment = [];

        $systemEnvironment = getenv();
        if (is_array($systemEnvironment)) {
            $environment = $systemEnvironment;
        }

        $environment = array_merge($environment, $_SERVER, $_ENV);

        $path = getenv('PATH');
        if (!$path) {
            $path = getenv('Path');
        }

        if (is_string($path) && $path !== '') {
            $environment['PATH'] = $path;
            $environment['Path'] = $path;
        }

        $systemRoot = getenv('SystemRoot');
        if (is_string($systemRoot) && $systemRoot !== '') {
            $environment['SystemRoot'] = $systemRoot;
        }

        return array_merge($environment, $extraEnv);
    }
};
