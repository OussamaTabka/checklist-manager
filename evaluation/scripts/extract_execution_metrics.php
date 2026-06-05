<?php

/**
 * Extract real execution metrics from MySQL test_results and test_runs
 * PROBLÈME 1 correction: Get execution rate, error distribution, retry counts
 */

// MySQL connection
$dbConfig = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'checklist_manager',
    'username' => 'root',
    'password' => '',
];

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s', $dbConfig['host'], $dbConfig['port'], $dbConfig['database']),
        $dbConfig['username'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "[Extract] MySQL connection successful\n";
} catch (PDOException $e) {
    echo "[ERROR] MySQL connection failed: " . $e->getMessage() . "\n";
    echo "[INFO] Trying SQLite fallback...\n";

    // Fallback to SQLite if MySQL unavailable
    $sqlitePath = __DIR__ . '/../../backend/database/database.sqlite';
    if (!file_exists($sqlitePath)) {
        echo "[ERROR] SQLite database not found at $sqlitePath\n";
        exit(1);
    }

    $pdo = new PDO("sqlite:$sqlitePath", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "[Extract] SQLite fallback successful\n";
}

// ===== EXTRACT TEST EXECUTION DATA =====

class ExecutionMetricsExtractor {
    private $pdo;
    private $outputDir;

    public function __construct(PDO $pdo, $outputDir) {
        $this->pdo = $pdo;
        $this->outputDir = $outputDir;
    }

    public function extract() {
        echo "[Extract] Fetching test_results and test_runs...\n";

        $metrics = [
            'totalTestRuns' => 0,
            'totalTestResults' => 0,
            'statusDistribution' => [],
            'errorDistribution' => [],
            'executionDetails' => [],
            'retryStats' => [],
        ];

        try {
            // Count total test runs
            $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM test_runs");
            $metrics['totalTestRuns'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
            echo "[Extract] Found {$metrics['totalTestRuns']} test_runs\n";

            // Get execution metrics
            $stmt = $this->pdo->query(<<<SQL
                SELECT
                    tr.id,
                    tr.run_id,
                    tr.status,
                    COUNT(tres.id) as result_count,
                    SUM(CASE WHEN tres.status = 'passed' THEN 1 ELSE 0 END) as passed_count,
                    SUM(CASE WHEN tres.status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                    SUM(CASE WHEN tres.status = 'blocked' THEN 1 ELSE 0 END) as blocked_count,
                    SUM(CASE WHEN tres.status = 'skipped' THEN 1 ELSE 0 END) as skipped_count
                FROM test_runs tr
                LEFT JOIN test_results tres ON tr.id = tres.test_run_id
                GROUP BY tr.id
                ORDER BY tr.id
            SQL);

            $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Extract individual test results
            $stmt = $this->pdo->query(<<<SQL
                SELECT
                    tres.id,
                    tres.test_run_id,
                    tres.status,
                    tres.error_type,
                    tres.error_message,
                    tres.duration_ms,
                    ci.title as checklist_item_title
                FROM test_results tres
                LEFT JOIN checklist_items ci ON tres.checklist_item_id = ci.id
                ORDER BY tres.created_at DESC
            SQL);

            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $metrics['totalTestResults'] = count($results);

            // Process status distribution
            $statusCounts = [];
            foreach ($results as $result) {
                $status = $result['status'] ?? 'unknown';
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            }

            // Calculate percentages
            foreach ($statusCounts as $status => $count) {
                $pct = $metrics['totalTestResults'] > 0
                    ? round(($count / $metrics['totalTestResults']) * 100, 1)
                    : 0;
                $metrics['statusDistribution'][$status] = [
                    'count' => $count,
                    'percentage' => $pct,
                ];
            }

            // Process error distribution (only for non-passed results)
            $errorCounts = [];
            foreach ($results as $result) {
                if ($result['status'] !== 'passed' && !empty($result['error_type'])) {
                    $errorType = $result['error_type'];
                    $errorCounts[$errorType] = ($errorCounts[$errorType] ?? 0) + 1;
                }
            }

            $failedCount = $statusCounts['failed'] ?? 0;
            foreach ($errorCounts as $errorType => $count) {
                $pct = $failedCount > 0 ? round(($count / $failedCount) * 100, 1) : 0;
                $metrics['errorDistribution'][$errorType] = [
                    'count' => $count,
                    'percentage' => $pct,
                ];
            }

            // Store detailed execution records
            $metrics['executionDetails'] = $results;

            // Try to extract retry stats if available
            $stmt = $this->pdo->query(<<<SQL
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN retry_count = 0 THEN 1 ELSE 0 END) as zero_retries,
                    SUM(CASE WHEN retry_count > 0 THEN 1 ELSE 0 END) as with_retries,
                    MAX(retry_count) as max_retries
                FROM test_runs
                WHERE retry_count IS NOT NULL
            SQL);

            $retryData = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($retryData && $retryData['total'] > 0) {
                $metrics['retryStats'] = [
                    'measured_runs' => (int)$retryData['total'],
                    'zero_retries' => (int)$retryData['zero_retries'],
                    'with_retries' => (int)$retryData['with_retries'],
                    'max_retries_observed' => (int)$retryData['max_retries'],
                    'first_attempt_success_rate' => $retryData['total'] > 0
                        ? round((($retryData['zero_retries'] ?? 0) / $retryData['total']) * 100, 1)
                        : 0,
                ];
            }

            echo "[Extract] Found {$metrics['totalTestResults']} test_results\n";

        } catch (Exception $e) {
            echo "[WARNING] Database query failed: " . $e->getMessage() . "\n";
            echo "[INFO] Trying alternative query structure...\n";

            // Fallback: try simpler query
            try {
                $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM test_results");
                $count = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC)['count'] : 0;
                $metrics['totalTestResults'] = $count;
                echo "[Extract] Found {$count} test_results via fallback\n";
            } catch (Exception $e2) {
                echo "[ERROR] All database queries failed\n";
            }
        }

        return $metrics;
    }

    public function saveResults($metrics) {
        $outputDir = $this->outputDir;

        // Save detailed CSV
        if (!empty($metrics['executionDetails'])) {
            $csv = "test_result_id,test_run_id,status,error_type,duration_ms,checklist_item\n";
            foreach ($metrics['executionDetails'] as $result) {
                $csv .= implode(',', [
                    $result['id'] ?? '',
                    $result['test_run_id'] ?? '',
                    $result['status'] ?? '',
                    $result['error_type'] ?? '',
                    $result['duration_ms'] ?? '',
                    '"' . str_replace('"', '""', $result['checklist_item_title'] ?? '') . '"',
                ]) . "\n";
            }
            file_put_contents("$outputDir/phase_a_executions_detail.csv", $csv);
            echo "[Save] Detailed execution CSV saved\n";
        }

        // Save execution summary JSON
        $summary = [
            'timestamp' => date('c'),
            'test_execution_summary' => [
                'total_test_runs' => $metrics['totalTestRuns'],
                'total_test_results' => $metrics['totalTestResults'],
                'status_distribution' => $metrics['statusDistribution'],
                'error_distribution' => $metrics['errorDistribution'],
            ],
            'retry_analysis' => $metrics['retryStats'],
        ];

        file_put_contents("$outputDir/phase_a_executions.json",
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "[Save] Execution summary JSON saved\n";

        // Create aggregated CSV
        $aggCsv = "status,count,percentage\n";
        foreach ($metrics['statusDistribution'] as $status => $data) {
            $aggCsv .= "{$status}," . $data['count'] . "," . $data['percentage'] . "\n";
        }
        file_put_contents("$outputDir/phase_a_executions.csv", $aggCsv);
        echo "[Save] Execution aggregates CSV saved\n";

        return $summary;
    }
}

$extractor = new ExecutionMetricsExtractor($pdo, __DIR__ . '/../data');
$metrics = $extractor->extract();
$summary = $extractor->saveResults($metrics);

// Print summary
echo "\n=== EXECUTION METRICS SUMMARY ===\n";
echo "Total Test Runs: {$metrics['totalTestRuns']}\n";
echo "Total Test Results: {$metrics['totalTestResults']}\n";
echo "\nStatus Distribution:\n";
foreach ($metrics['statusDistribution'] as $status => $data) {
    echo "  {$status}: {$data['count']} ({$data['percentage']}%)\n";
}
if (!empty($metrics['errorDistribution'])) {
    echo "\nError Distribution:\n";
    foreach ($metrics['errorDistribution'] as $errorType => $data) {
        echo "  {$errorType}: {$data['count']} ({$data['percentage']}%)\n";
    }
}
if (!empty($metrics['retryStats'])) {
    echo "\nRetry Analysis:\n";
    echo "  Runs measured: {$metrics['retryStats']['measured_runs']}\n";
    echo "  First attempt success: {$metrics['retryStats']['first_attempt_success_rate']}%\n";
}

echo "\n✓ All files saved to: " . __DIR__ . '/../data/' . "\n";
