<?php
/**
 * PHASE B : Évaluation complète (15 appels API)
 *
 * Mesure sur les 15 user stories sélectionnés :
 * - Time-to-first-token (ttft_ms)
 * - Durée totale (total_duration_ms)
 * - Tokens (prompt, completion)
 * - Vitesse d'inférence (tokens/sec)
 *
 * Aucune persistance en base.
 * Sauvegarde : evaluation/data/phase_b_timings.csv + phase_b_runs/<us_id>.json
 */

require __DIR__ . '/backend/vendor/autoload.php';
$app = require __DIR__ . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

require __DIR__ . '/classification_rules_final.php';

use App\Models\UserStory;
use App\Services\ChecklistGenerationAgentService;
use Illuminate\Support\Facades\Log;

// User stories à évaluer (15 total : 11 avec checklists + 4 sans)
$evaluation_us_ids = [34, 36, 1, 21, 24, 26, 31, 35, 37, 39, 42, 43, 38, 40, 41];

echo "╔════════════════════════════════════════════════════════════════════════════════╗\n";
echo "║             PHASE B : ÉVALUATION LATENCE & VITESSE D'INFÉRENCE                 ║\n";
echo "║              15 Appels API | Aucune persistance en base                        ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════════╝\n\n";

$generation_service = app(ChecklistGenerationAgentService::class);
$classifier = new TestCaseClassifier();

$results = [
    'timestamp' => now()->toIso8601String(),
    'phase' => 'B',
    'metadata' => [
        'note' => 'Streaming non activé : ttft_ms ≈ total_duration_ms',
        'us_count' => count($evaluation_us_ids),
    ],
    'measurements' => [],
    'statistics' => [],
];

$timings_csv = [];
$run_index = 0;
$successful_calls = [];
$failed_calls = [];

// ========== APPELS API ==========
foreach ($evaluation_us_ids as $us_id) {
    $user_story = UserStory::with('project')->find($us_id);

    if (!$user_story) {
        echo "❌ US #$us_id introuvable\n";
        continue;
    }

    $run_index++;
    echo sprintf("\n[%2d/15] US #%-2d | %s\n", $run_index, $us_id, substr($user_story->title, 0, 50));

    // ========== MESURE PRÉCISE DES TIMINGS ==========
    $time_before_request = microtime(true);
    $time_first_byte = null;
    $time_after_request = null;

    try {
        // Appeler le service
        $generation_result = $generation_service->generateDraftForUserStory($user_story, 'fr');

        $time_after_request = microtime(true);
        // Note: Sans streaming, on considère que first_byte = end du stream
        $time_first_byte = $time_after_request;

        // Extraire les données
        $checklist = $generation_result['checklist'] ?? null;
        $item_count = $checklist ? $checklist->items()->count() : 0;

        // Calculer les métriques
        $ttft_ms = ($time_first_byte - $time_before_request) * 1000;
        $total_duration_ms = ($time_after_request - $time_before_request) * 1000;

        // Estimer tokens depuis le contenu généré (heuristique)
        // Approximation : ~4 chars = 1 token
        $checklist_content = json_encode($generation_result);
        $estimated_completion_tokens = strlen($checklist_content) / 4;

        // Estimer prompt_tokens desde la user story
        $story_content = $user_story->title . ' ' . $user_story->description . ' ' .
                        ($user_story->acceptance_criteria ?? '') . ' ' .
                        ($user_story->business_rules ? json_encode($user_story->business_rules) : '');
        $estimated_prompt_tokens = strlen($story_content) / 4;

        $tokens_per_second = ($total_duration_ms > 0)
            ? ($estimated_completion_tokens / ($total_duration_ms / 1000))
            : 0;

        echo sprintf("   ✅ Succès | TTFT: %.0fms | Total: %.0fms | Items: %d\n",
            $ttft_ms, $total_duration_ms, $item_count);

        $results['measurements'][$us_id] = [
            'us_id' => $us_id,
            'story_id' => $user_story->story_id,
            'title' => $user_story->title,
            'priority' => $user_story->priority,
            'status' => 'success',
            'ttft_ms' => round($ttft_ms, 2),
            'total_duration_ms' => round($total_duration_ms, 2),
            'prompt_tokens_estimated' => round($estimated_prompt_tokens),
            'completion_tokens_estimated' => round($estimated_completion_tokens),
            'tokens_per_second' => round($tokens_per_second, 2),
            'items_generated' => $item_count,
            'timestamp' => now()->toIso8601String(),
        ];

        $timings_csv[] = [
            'us_id' => $us_id,
            'run_index' => $run_index,
            'ttft_ms' => round($ttft_ms, 2),
            'total_duration_ms' => round($total_duration_ms, 2),
            'prompt_tokens' => round($estimated_prompt_tokens),
            'completion_tokens' => round($estimated_completion_tokens),
            'tokens_per_second' => round($tokens_per_second, 2),
            'http_status' => 200,
            'api_provider' => 'codex.sale',
        ];

        $successful_calls[] = [
            'us_id' => $us_id,
            'ttft_ms' => $ttft_ms,
            'total_duration_ms' => $total_duration_ms,
            'tokens_per_second' => $tokens_per_second,
        ];

        // Sauvegarder le contenu généré (pas en base, seulement en fichier)
        @mkdir('evaluation/data/phase_b_runs', 0755, true);
        $run_file = sprintf('evaluation/data/phase_b_runs/%d.json', $us_id);
        file_put_contents($run_file, json_encode([
            'timestamp' => now()->toIso8601String(),
            'us_id' => $us_id,
            'story_id' => $user_story->story_id,
            'title' => $user_story->title,
            'checklist_draft' => $checklist ? [
                'id' => $checklist->id,
                'name' => $checklist->name,
                'lifecycle_status' => $checklist->lifecycle_status,
                'items_count' => $item_count,
            ] : null,
            'generation_result_summary' => [
                'has_decision' => isset($generation_result['decision']),
                'coverage_ratio' => $generation_result['decision']['coverage_ratio'] ?? null,
            ],
            'metrics' => $results['measurements'][$us_id],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    } catch (\Exception $e) {
        $time_after_request = microtime(true);
        $total_duration_ms = ($time_after_request - $time_before_request) * 1000;

        echo sprintf("   ❌ Erreur | Durée: %.0fms | %s\n",
            $total_duration_ms, substr($e->getMessage(), 0, 50));

        $results['measurements'][$us_id] = [
            'us_id' => $us_id,
            'story_id' => $user_story->story_id,
            'title' => $user_story->title,
            'priority' => $user_story->priority,
            'status' => 'error',
            'total_duration_ms' => round($total_duration_ms, 2),
            'error_message' => $e->getMessage(),
            'timestamp' => now()->toIso8601String(),
        ];

        $timings_csv[] = [
            'us_id' => $us_id,
            'run_index' => $run_index,
            'ttft_ms' => null,
            'total_duration_ms' => round($total_duration_ms, 2),
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'tokens_per_second' => null,
            'http_status' => 'ERROR',
            'api_provider' => 'codex.sale',
        ];

        $failed_calls[] = [
            'us_id' => $us_id,
            'error' => $e->getMessage(),
        ];
    }
}

// ========== CALCULS STATISTIQUES ==========
echo "\n" . str_repeat("═", 80) . "\n";
echo "STATISTIQUES PHASE B\n";
echo str_repeat("═", 80) . "\n\n";

$success_count = count($successful_calls);
$total_count = count($evaluation_us_ids);
$success_rate = $total_count > 0 ? round(100 * $success_count / $total_count, 1) : 0;

echo sprintf("Appels réussis:      %d/%d (%.1f%%)\n", $success_count, $total_count, $success_rate);
echo sprintf("Appels échoués:      %d/%d\n\n", count($failed_calls), $total_count);

if ($success_count > 0) {
    // Extraire les valeurs pour statistiques
    $ttft_values = array_column($successful_calls, 'ttft_ms');
    $duration_values = array_column($successful_calls, 'total_duration_ms');
    $speed_values = array_column($successful_calls, 'tokens_per_second');

    // Fonction pour calculer statistiques
    function calc_stats($values) {
        if (empty($values)) return null;

        sort($values);
        $n = count($values);
        $mean = array_sum($values) / $n;

        // Médiane
        $median = $n % 2 === 0
            ? ($values[$n/2 - 1] + $values[$n/2]) / 2
            : $values[floor($n/2)];

        // P95
        $p95_index = ceil(0.95 * $n) - 1;
        $p95 = $values[$p95_index] ?? end($values);

        // Écart-type
        $sq_diffs = array_map(fn($x) => pow($x - $mean, 2), $values);
        $variance = array_sum($sq_diffs) / $n;
        $stddev = sqrt($variance);

        // Min/Max
        $min = min($values);
        $max = max($values);

        return compact('median', 'mean', 'p95', 'stddev', 'min', 'max', 'n');
    }

    // Latence (TTFT)
    $ttft_stats = calc_stats($ttft_values);
    $results['statistics']['latence_ttft_ms'] = $ttft_stats;

    echo "LATENCE (Time-to-First-Token)\n";
    echo "─────────────────────────────────────────────────────────\n";
    echo sprintf("  Médiane:   %.1f ms\n", $ttft_stats['median']);
    echo sprintf("  Moyenne:   %.1f ms\n", $ttft_stats['mean']);
    echo sprintf("  P95:       %.1f ms\n", $ttft_stats['p95']);
    echo sprintf("  Écart-type: %.1f ms\n", $ttft_stats['stddev']);
    echo sprintf("  Min:       %.1f ms\n", $ttft_stats['min']);
    echo sprintf("  Max:       %.1f ms\n\n", $ttft_stats['max']);

    // Durée totale
    $duration_stats = calc_stats($duration_values);
    $results['statistics']['durée_totale_ms'] = $duration_stats;

    echo "DURÉE TOTALE\n";
    echo "─────────────────────────────────────────────────────────\n";
    echo sprintf("  Médiane:    %.1f ms\n", $duration_stats['median']);
    echo sprintf("  Moyenne:    %.1f ms\n", $duration_stats['mean']);
    echo sprintf("  P95:        %.1f ms\n", $duration_stats['p95']);
    echo sprintf("  Écart-type: %.1f ms\n", $duration_stats['stddev']);
    echo sprintf("  Min:        %.1f ms\n", $duration_stats['min']);
    echo sprintf("  Max:        %.1f ms\n\n", $duration_stats['max']);

    // Vitesse
    $speed_stats = calc_stats($speed_values);
    $results['statistics']['vitesse_tokens_per_sec'] = $speed_stats;

    echo "VITESSE D'INFÉRENCE (tokens/sec)\n";
    echo "─────────────────────────────────────────────────────────\n";
    echo sprintf("  Médiane:    %.2f tok/s\n", $speed_stats['median']);
    echo sprintf("  Moyenne:    %.2f tok/s\n", $speed_stats['mean']);
    echo sprintf("  Min:        %.2f tok/s\n", $speed_stats['min']);
    echo sprintf("  Max:        %.2f tok/s\n\n", $speed_stats['max']);

    // Note méthodologique
    echo "⚠️  LIMITATION MÉTHODOLOGIQUE\n";
    echo "─────────────────────────────────────────────────────────\n";
    echo "  Streaming n'est pas activé dans l'implémentation actuelle.\n";
    echo "  Donc : ttft_ms ≈ total_duration_ms (latence = borne supérieure)\n";
    echo "  La vraie latence perçue serait inférieure avec streaming.\n";
}

if (!empty($failed_calls)) {
    echo "\n❌ APPELS ÉCHOUÉS\n";
    echo "─────────────────────────────────────────────────────────\n";
    foreach ($failed_calls as $failed) {
        echo sprintf("  • US #%d : %s\n", $failed['us_id'], substr($failed['error'], 0, 60));
    }
}

// ========== SAUVEGARDER RÉSULTATS ==========
@mkdir('evaluation/data', 0755, true);

// CSV Timings
$csv_path = 'evaluation/data/phase_b_timings.csv';
$fp = fopen($csv_path, 'w');
fputcsv($fp, ['us_id', 'run_index', 'ttft_ms', 'total_duration_ms', 'prompt_tokens', 'completion_tokens', 'tokens_per_second', 'http_status', 'api_provider']);
foreach ($timings_csv as $row) {
    fputcsv($fp, $row);
}
fclose($fp);

// JSON Résultats complets
$results_path = 'evaluation/data/phase_b_results.json';
file_put_contents($results_path, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n" . str_repeat("═", 80) . "\n";
echo "✅ RÉSULTATS PHASE B SAUVEGARDÉS\n";
echo str_repeat("═", 80) . "\n";
echo sprintf("  CSV timings:           %s\n", $csv_path);
echo sprintf("  JSON résultats:        %s\n", $results_path);
echo sprintf("  Contenus générés:      evaluation/data/phase_b_runs/*.json\n");
echo "\n⚠️  NOTE : Aucun résultat persisté en base de données.\n";
echo "   Toutes les données sont sauvegardées pour traçabilité uniquement.\n";
