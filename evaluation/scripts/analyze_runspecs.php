<?php

/**
 * Phase A Analysis Script
 * Analyzes existing run_specs and test_results for metrics:
 * 1. Structural validity (schema compliance)
 * 2. Selector quality distribution
 * 3. Execution success rate
 */

// Configuration
$runSpecDir = __DIR__ . '/../../backend/storage/app/agent-run-dsl';
$dbPath = __DIR__ . '/../../backend/database/database.sqlite';
$outputDir = __DIR__ . '/../data';

// Create output directory if needed
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// ===== PHASE A: ANALYZE RUNSPECS =====

class RunSpecAnalyzer {
    private $runSpecDir;
    private $selectorStats = [
        'testid' => 0,
        'role' => 0,
        'label' => 0,
        'text' => 0,
        'css' => 0,
        'unknown' => 0,
    ];
    private $totalSelectors = 0;
    private $runSpecs = [];
    private $structuralIssues = [];

    public function __construct($runSpecDir) {
        $this->runSpecDir = $runSpecDir;
    }

    public function analyze() {
        echo "[Phase A] Analyzing run_specs from: {$this->runSpecDir}\n";

        // Get all JSON files
        $jsonFiles = glob($this->runSpecDir . '/run-single-*.json');
        echo "[Phase A] Found " . count($jsonFiles) . " run_spec files\n";

        foreach ($jsonFiles as $file) {
            $this->analyzeRunSpec($file);
        }

        return [
            'totalRunSpecs' => count($this->runSpecs),
            'validRunSpecs' => count(array_filter($this->runSpecs, fn($spec) => $spec['valid'])),
            'invalidRunSpecs' => count(array_filter($this->runSpecs, fn($spec) => !$spec['valid'])),
            'structuralIssues' => $this->structuralIssues,
            'selectorStats' => $this->getSelectorStats(),
            'runSpecs' => $this->runSpecs,
        ];
    }

    private function analyzeRunSpec($filePath) {
        $filename = basename($filePath);
        $content = file_get_contents($filePath);
        $decoded = json_decode($content, true);

        $spec = [
            'filename' => $filename,
            'run_id' => null,
            'valid' => false,
            'parseError' => null,
            'structureErrors' => [],
            'caseCount' => 0,
            'selectorCount' => 0,
        ];

        if ($decoded === null) {
            $spec['parseError'] = json_last_error_msg();
            $this->structuralIssues[] = "$filename: JSON parse error - " . $spec['parseError'];
            $this->runSpecs[] = $spec;
            return;
        }

        // Check required fields
        if (!isset($decoded['schema_version'])) {
            $spec['structureErrors'][] = 'Missing schema_version';
        }
        if (!isset($decoded['run_id'])) {
            $spec['structureErrors'][] = 'Missing run_id';
        } else {
            $spec['run_id'] = $decoded['run_id'];
        }
        if (!isset($decoded['target'])) {
            $spec['structureErrors'][] = 'Missing target';
        }
        if (!isset($decoded['runtime'])) {
            $spec['structureErrors'][] = 'Missing runtime';
        }
        if (!isset($decoded['cases']) || !is_array($decoded['cases'])) {
            $spec['structureErrors'][] = 'Missing or invalid cases array';
        }

        // Analyze cases
        if (isset($decoded['cases']) && is_array($decoded['cases'])) {
            $spec['caseCount'] = count($decoded['cases']);
            foreach ($decoded['cases'] as $caseIndex => $case) {
                $this->analyzeCase($case, $spec);
            }
        }

        $spec['valid'] = empty($spec['structureErrors']) && $spec['parseError'] === null;
        if (!$spec['valid'] && !empty($spec['structureErrors'])) {
            $this->structuralIssues[] = "{$filename}: " . implode('; ', $spec['structureErrors']);
        }

        $this->runSpecs[] = $spec;
    }

    private function analyzeCase($case, &$spec) {
        if (isset($case['steps']) && is_array($case['steps'])) {
            foreach ($case['steps'] as $step) {
                if (isset($step['selector'])) {
                    $this->countSelector($step['selector']);
                    $spec['selectorCount']++;
                }
            }
        }

        if (isset($case['asserts']) && is_array($case['asserts'])) {
            foreach ($case['asserts'] as $assert) {
                if (isset($assert['selector'])) {
                    $this->countSelector($assert['selector']);
                    $spec['selectorCount']++;
                }
            }
        }

        if (isset($case['preflight_checks']) && is_array($case['preflight_checks'])) {
            foreach ($case['preflight_checks'] as $check) {
                if (isset($check['selector'])) {
                    $this->countSelector($check['selector']);
                    $spec['selectorCount']++;
                }
            }
        }
    }

    private function countSelector($selector) {
        $this->totalSelectors++;

        if (!isset($selector['by'])) {
            $this->selectorStats['unknown']++;
            return;
        }

        $by = $selector['by'];
        if (isset($this->selectorStats[$by])) {
            $this->selectorStats[$by]++;
        } else {
            $this->selectorStats['unknown']++;
        }
    }

    private function getSelectorStats() {
        $stats = [];
        foreach ($this->selectorStats as $type => $count) {
            $pct = $this->totalSelectors > 0 ? round(($count / $this->totalSelectors) * 100, 1) : 0;
            $stats[$type] = [
                'count' => $count,
                'percentage' => $pct,
            ];
        }
        $stats['total'] = $this->totalSelectors;
        return $stats;
    }
}

// Run analysis
$analyzer = new RunSpecAnalyzer($runSpecDir);
$phaseAResults = $analyzer->analyze();

// ===== PHASE A: ANALYZE TESTRUN AND TESTRESULT =====

class DatabaseAnalyzer {
    private $db;

    public function __construct($dbPath) {
        if (!file_exists($dbPath)) {
            throw new Exception("Database not found: $dbPath");
        }
        $this->db = new PDO("sqlite:$dbPath");
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function analyzeTestResults() {
        echo "[Phase A] Analyzing test results from database\n";

        $results = [
            'totalTestRuns' => 0,
            'totalTestResults' => 0,
            'passedTests' => 0,
            'failedTests' => 0,
            'blockedTests' => 0,
            'errorDistribution' => [],
        ];

        try {
            // Get total test runs
            $stmt = $this->db->query("SELECT COUNT(*) as count FROM test_runs");
            $results['totalTestRuns'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Get total test results by status
            $stmt = $this->db->query("SELECT status, COUNT(*) as count FROM test_results GROUP BY status");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $status = $row['status'];
                $count = $row['count'];
                $results['totalTestResults'] += $count;

                if ($status === 'passed') {
                    $results['passedTests'] = $count;
                } elseif ($status === 'failed') {
                    $results['failedTests'] = $count;
                } elseif ($status === 'blocked') {
                    $results['blockedTests'] = $count;
                }
            }

            // Get error distribution for failed tests
            $stmt = $this->db->query("SELECT error_type, COUNT(*) as count FROM test_results WHERE status = 'failed' GROUP BY error_type");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results['errorDistribution'][$row['error_type']] = $row['count'];
            }
        } catch (Exception $e) {
            echo "[Phase A] Warning: Could not read database: " . $e->getMessage() . "\n";
            // Database might be empty or not set up yet
        }

        return $results;
    }
}

$dbAnalyzer = new DatabaseAnalyzer($dbPath);
$testResultsAnalysis = $dbAnalyzer->analyzeTestResults();

// ===== OUTPUT RESULTS =====

// Save Phase A aggregates
file_put_contents("$outputDir/phase_a_runspecs.json", json_encode([
    'timestamp' => date('c'),
    'runspec_analysis' => [
        'total' => $phaseAResults['totalRunSpecs'],
        'valid' => $phaseAResults['validRunSpecs'],
        'invalid' => $phaseAResults['invalidRunSpecs'],
        'validity_rate_percent' => $phaseAResults['totalRunSpecs'] > 0
            ? round(($phaseAResults['validRunSpecs'] / $phaseAResults['totalRunSpecs']) * 100, 1)
            : 0,
    ],
    'selector_distribution' => $phaseAResults['selectorStats'],
    'structural_issues' => $phaseAResults['structuralIssues'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Save test results
file_put_contents("$outputDir/phase_a_executions.json", json_encode([
    'timestamp' => date('c'),
    'test_execution_summary' => [
        'total_test_runs' => $testResultsAnalysis['totalTestRuns'],
        'total_test_results' => $testResultsAnalysis['totalTestResults'],
        'passed' => $testResultsAnalysis['passedTests'],
        'failed' => $testResultsAnalysis['failedTests'],
        'blocked' => $testResultsAnalysis['blockedTests'],
        'success_rate_percent' => $testResultsAnalysis['totalTestResults'] > 0
            ? round(($testResultsAnalysis['passedTests'] / $testResultsAnalysis['totalTestResults']) * 100, 1)
            : 0,
        'failure_rate_percent' => $testResultsAnalysis['totalTestResults'] > 0
            ? round(($testResultsAnalysis['failedTests'] / $testResultsAnalysis['totalTestResults']) * 100, 1)
            : 0,
        'blocked_rate_percent' => $testResultsAnalysis['totalTestResults'] > 0
            ? round(($testResultsAnalysis['blockedTests'] / $testResultsAnalysis['totalTestResults']) * 100, 1)
            : 0,
    ],
    'error_distribution' => $testResultsAnalysis['errorDistribution'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Save detailed selector report
$selectorsCsv = "selector_type,count,percentage\n";
foreach ($phaseAResults['selectorStats'] as $type => $data) {
    if ($type !== 'total' && is_array($data)) {
        $selectorsCsv .= "{$type}," . $data['count'] . "," . $data['percentage'] . "\n";
    }
}
file_put_contents("$outputDir/phase_a_selectors.csv", $selectorsCsv);

// Print summary
echo "\n=== PHASE A ANALYSIS SUMMARY ===\n";
echo "Total Run Specs: " . $phaseAResults['totalRunSpecs'] . "\n";
echo "Valid Run Specs: " . $phaseAResults['validRunSpecs'] . " ("
    . round(($phaseAResults['validRunSpecs'] / max($phaseAResults['totalRunSpecs'], 1)) * 100, 1) . "%)\n";
echo "\nSelector Distribution:\n";
foreach ($phaseAResults['selectorStats'] as $type => $data) {
    if ($type !== 'total' && is_array($data)) {
        echo "  {$type}: {$data['count']} ({$data['percentage']}%)\n";
    }
}
echo "\nTest Execution Results:\n";
echo "  Total Results: {$testResultsAnalysis['totalTestResults']}\n";
echo "  Passed: {$testResultsAnalysis['passedTests']}\n";
echo "  Failed: {$testResultsAnalysis['failedTests']}\n";
echo "  Blocked: {$testResultsAnalysis['blockedTests']}\n";
echo "  Success Rate: " . round(($testResultsAnalysis['passedTests'] / max($testResultsAnalysis['totalTestResults'], 1)) * 100, 1) . "%\n";

echo "\nOutputs saved to: $outputDir/\n";
