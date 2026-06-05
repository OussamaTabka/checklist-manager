<?php

/**
 * Classify test failures by origin: HALLUCINATION, ENVIRONNEMENT, or DESIGN
 */

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
    echo "[Hallucinations] MySQL connection successful\n";
} catch (PDOException $e) {
    echo "[ERROR] MySQL connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

class HallucinationClassifier {
    private $pdo;
    private $outputDir;
    private $classifications = [];

    public function __construct(PDO $pdo, $outputDir) {
        $this->pdo = $pdo;
        $this->outputDir = $outputDir;
    }

    private function classifyError($errorType, $errorMessage, $status) {
        if ($status === 'passed') {
            return 'PASSED';
        }

        $defaultClassification = [
            'selector_not_found' => 'HALLUCINATION',
            'script_generation_failed' => 'HALLUCINATION',
            'assertion_failed' => 'HALLUCINATION',
            'url_unreachable' => 'ENVIRONNEMENT',
            'navigation_timeout' => 'ENVIRONNEMENT',
            'infra_error' => 'ENVIRONNEMENT',
            'input_data_missing' => 'DESIGN',
            'timeout' => 'ENVIRONNEMENT',
            'unexpected_error' => 'TO_EXAMINE',
        ];

        if (!isset($defaultClassification[$errorType])) {
            return 'TO_EXAMINE';
        }

        $classification = $defaultClassification[$errorType];

        if ($errorType === 'assertion_failed') {
            if (stripos($errorMessage, 'expected') !== false && stripos($errorMessage, 'empty') !== false) {
                $classification = 'ENVIRONNEMENT';
            }
        }

        if ($errorType === 'script_generation_failed' || $errorType === 'script_failed') {
            if (stripos($errorMessage, 'timeout') !== false) {
                $classification = 'ENVIRONNEMENT';
            }
        }

        return $classification;
    }

    public function analyze() {
        echo "[Hallucinations] Fetching test_results for classification...\n";

        $stmt = $this->pdo->query("
            SELECT
                tres.id as result_id,
                tres.test_run_id,
                tres.id as external_id,
                tres.status,
                tres.error_type,
                tres.error_message,
                ci.title as test_case_title
            FROM test_results tres
            LEFT JOIN checklist_items ci ON tres.checklist_item_id = ci.id
            ORDER BY tres.created_at DESC
        ");

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "[Hallucinations] Retrieved " . count($results) . " results\n";

        $stats = [
            'total' => 0,
            'passed' => 0,
            'hallucination' => 0,
            'environnement' => 0,
            'design' => 0,
            'to_examine' => 0,
        ];

        $detailed = [];

        foreach ($results as $result) {
            $stats['total']++;
            $classification = $this->classifyError(
                $result['error_type'] ?? '',
                $result['error_message'] ?? '',
                $result['status']
            );

            if ($classification === 'PASSED') {
                $stats['passed']++;
            } else {
                $stats[strtolower($classification)]++;
            }

            $detailed[] = [
                'external_id' => $result['external_id'] ?? 'unknown',
                'test_case_title' => $result['test_case_title'] ?? '',
                'status' => $result['status'],
                'error_type' => $result['error_type'] ?? '',
                'error_message_excerpt' => substr($result['error_message'] ?? '', 0, 100),
                'classification' => $classification,
            ];
        }

        $this->classifications = $detailed;
        return $stats;
    }

    public function saveResults($stats) {
        $outputDir = $this->outputDir;

        $csv = "external_id,test_case_title,status,error_type,error_message_excerpt,classification\n";
        foreach ($this->classifications as $item) {
            $csv .= implode(',', [
                $item['external_id'],
                '"' . str_replace('"', '""', $item['test_case_title']) . '"',
                $item['status'],
                $item['error_type'],
                '"' . str_replace('"', '""', $item['error_message_excerpt']) . '"',
                $item['classification'],
            ]) . "\n";
        }
        file_put_contents("$outputDir/phase_a_hallucinations.csv", $csv);
        echo "[Save] Hallucination classification CSV saved\n";

        $effectivePool = $stats['total'] - $stats['design'] - $stats['environnement'];
        $resistanceAlt = $effectivePool > 0
            ? round((1 - ($stats['hallucination'] / $effectivePool)) * 100, 1)
            : 0;

        $note = 1;
        if ($resistanceAlt >= 95) $note = 5;
        elseif ($resistanceAlt >= 90) $note = 4;
        elseif ($resistanceAlt >= 80) $note = 3;
        elseif ($resistanceAlt >= 70) $note = 2;

        $aggregates = [
            'timestamp' => date('c'),
            'total_test_results' => $stats['total'],
            'status_breakdown' => [
                'passed' => $stats['passed'],
                'hallucination' => $stats['hallucination'],
                'environnement' => $stats['environnement'],
                'design' => $stats['design'],
                'to_examine' => $stats['to_examine'],
            ],
            'effective_pool' => $effectivePool,
            'resistance_rate_percent' => $resistanceAlt,
            'note_out_of_5' => $note,
        ];

        file_put_contents("$outputDir/phase_a_hallucinations.json",
            json_encode($aggregates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "[Save] Hallucination aggregates JSON saved\n";

        return $aggregates;
    }
}

$classifier = new HallucinationClassifier($pdo, __DIR__ . '/../data');
$stats = $classifier->analyze();
$aggregates = $classifier->saveResults($stats);

echo "\n=== HALLUCINATION CLASSIFICATION SUMMARY ===\n";
echo "Total: {$stats['total']}\n";
echo "  Passed: {$stats['passed']}\n";
echo "  HALLUCINATION: {$stats['hallucination']}\n";
echo "  ENVIRONNEMENT: {$stats['environnement']}\n";
echo "  DESIGN: {$stats['design']}\n";
echo "  TO_EXAMINE: {$stats['to_examine']}\n";
echo "\nResistance Rate: {$aggregates['resistance_rate_percent']}%\n";
echo "Note: {$aggregates['note_out_of_5']}/5\n";
echo "\n✓ Hallucination analysis complete\n";
