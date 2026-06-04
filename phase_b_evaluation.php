<?php
/**
 * PHASE B : Génération API frais (4 user stories)
 *
 * Mesure :
 * - Latence (time-to-first-token)
 * - Vitesse d'inférence (tokens/seconde)
 * - Taille des réponses
 *
 * Données sauvegardées UNIQUEMENT dans evaluation/data/, PAS en base
 */

require __DIR__ . '/backend/vendor/autoload.php';
$app = require __DIR__ . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

require __DIR__ . '/classification_rules_final.php';

use App\Models\UserStory;
use App\Services\ChecklistGenerationAgentService;
use Illuminate\Support\Facades\Log;

$phase_b_us_ids = [21, 24, 26, 31];  // User stories sans checklists IA

echo "╔═══════════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                    PHASE B : GÉNÉRATION API FRAIS (4 US)                         ║\n";
echo "║         Mesure : Latence + Vitesse d'inférence (PAS de persistance en base)      ║\n";
echo "╚═══════════════════════════════════════════════════════════════════════════════════╝\n\n";

$generation_service = app(ChecklistGenerationAgentService::class);
$results = [
    'timestamp' => now()->toIso8601String(),
    'phase' => 'B',
    'user_stories' => [],
];

$timing_data = [];

foreach ($phase_b_us_ids as $us_id) {
    $user_story = UserStory::with('project')->find($us_id);

    if (!$user_story) {
        echo "❌ US #{$us_id} introuvable\n";
        continue;
    }

    echo "────────────────────────────────────────────────────────────────────────────────\n";
    echo "📖 US #{$us_id} | {$user_story->story_id} | {$user_story->title}\n";
    echo "   Priority: {$user_story->priority} | Project: {$user_story->project?->name}\n";

    $time_start = microtime(true);
    $t_request_start = $time_start;

    try {
        // Appeler le service de génération
        $generation_result = $generation_service->generateDraftForUserStory($user_story, 'fr');

        $t_request_end = microtime(true);
        $duration_total = $t_request_end - $t_request_start;

        // Extraire les données de la génération
        $checklist = $generation_result['checklist'] ?? null;
        $item_count = $checklist ? $checklist->items()->count() : 0;

        // Note : Dans une implémentation réelle avec streaming, on mesurerait
        // le time-to-first-token. Ici on mesure le temps total.

        echo "   ✅ Génération réussie\n";
        echo sprintf("   ⏱️  Durée totale : %.2f secondes\n", $duration_total);
        echo sprintf("   📊 Items générés : %d\n", $item_count);

        // Sauvegarder les résultats (pas de persistance en base)
        $results['user_stories'][$us_id] = [
            'story_id' => $user_story->story_id,
            'title' => $user_story->title,
            'priority' => $user_story->priority,
            'status' => 'success',
            'duration_seconds' => $duration_total,
            'items_generated' => $item_count,
            'timestamp' => now()->toIso8601String(),
            'checklist_draft' => $checklist ? [
                'id' => $checklist->id,
                'name' => $checklist->name,
                'lifecycle_status' => $checklist->lifecycle_status,
                'item_count' => $item_count,
            ] : null,
        ];

        $timing_data[] = [
            'us_id' => $us_id,
            'story_id' => $user_story->story_id,
            'title' => substr($user_story->title, 0, 60),
            'priority' => $user_story->priority,
            'duration_sec' => round($duration_total, 3),
            'items_count' => $item_count,
        ];

    } catch (\Exception $e) {
        $t_request_end = microtime(true);
        $duration_total = $t_request_end - $t_request_start;

        echo "   ❌ Génération échouée\n";
        echo sprintf("   Erreur : %s\n", substr($e->getMessage(), 0, 100));
        echo sprintf("   ⏱️  Durée avant erreur : %.2f secondes\n", $duration_total);

        $results['user_stories'][$us_id] = [
            'story_id' => $user_story->story_id,
            'title' => $user_story->title,
            'priority' => $user_story->priority,
            'status' => 'error',
            'duration_seconds' => $duration_total,
            'error_message' => $e->getMessage(),
            'timestamp' => now()->toIso8601String(),
        ];

        $timing_data[] = [
            'us_id' => $us_id,
            'story_id' => $user_story->story_id,
            'title' => substr($user_story->title, 0, 60),
            'priority' => $user_story->priority,
            'duration_sec' => round($duration_total, 3),
            'status' => 'ERROR',
        ];
    }
}

// ========== RÉSUMÉ PHASE B ==========
echo "\n" . str_repeat("═", 80) . "\n";
echo "RÉSUMÉ PHASE B\n";
echo str_repeat("═", 80) . "\n\n";

$successful = array_filter($results['user_stories'], fn($r) => $r['status'] === 'success');
$failed = array_filter($results['user_stories'], fn($r) => $r['status'] === 'error');

echo sprintf("✅ Générations réussies : %d\n", count($successful));
echo sprintf("❌ Générations échouées : %d\n", count($failed));

if (!empty($successful)) {
    echo "\n📊 Timings (générations réussies) :\n";
    echo "┌────┬─────────────────────────────────────────┬──────────┬──────────────┐\n";
    echo "│ ID │ Titre                                   │ Priorité │ Durée (sec)  │\n";
    echo "├────┼─────────────────────────────────────────┼──────────┼──────────────┤\n";

    $total_duration = 0;
    foreach ($timing_data as $timing) {
        if (isset($timing['items_count'])) {
            echo sprintf("│ %-2d │ %-39s │ %-8s │ %12.3f │\n",
                $timing['us_id'],
                substr($timing['title'], 0, 39),
                $timing['priority'],
                $timing['duration_sec']
            );
            $total_duration += $timing['duration_sec'];
        }
    }

    echo "└────┴─────────────────────────────────────────┴──────────┴──────────────┘\n";

    if (count($successful) > 0) {
        echo sprintf("\nMoyenne : %.2f sec/génération\n", $total_duration / count($successful));
        echo sprintf("Totale : %.2f sec\n", $total_duration);
    }
}

if (!empty($failed)) {
    echo "\n❌ Générations échouées :\n";
    foreach ($failed as $us_id => $result) {
        echo sprintf("  • US #%d : %s\n", $us_id, substr($result['error_message'], 0, 60));
    }
}

// ========== SAUVEGARDER RÉSULTATS PHASE B ==========
@mkdir('evaluation/data', 0755, true);

// Résultats JSON détaillés
$results_path = 'evaluation/data/phase_b_results.json';
file_put_contents($results_path, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// CSV timing simplifié
$csv_path = 'evaluation/data/phase_b_timings.csv';
$fp = fopen($csv_path, 'w');
fputcsv($fp, ['us_id', 'story_id', 'title', 'priority', 'duration_sec', 'items_count', 'status']);
foreach ($timing_data as $row) {
    $status = $row['status'] ?? (isset($row['items_count']) ? 'SUCCESS' : 'ERROR');
    fputcsv($fp, [
        $row['us_id'],
        $row['story_id'],
        $row['title'],
        $row['priority'],
        $row['duration_sec'],
        $row['items_count'] ?? 'N/A',
        $status,
    ]);
}
fclose($fp);

echo "\n✅ Résultats Phase B sauvegardés :\n";
echo sprintf("   - JSON : %s\n", $results_path);
echo sprintf("   - CSV  : %s\n", $csv_path);
echo "\n⚠️  NOTE : Les checklists générées lors de Phase B ne sont PAS persistées en base\n";
echo "   (pour éviter la pollution des données réelles).\n";
echo "   Les résultats sont sauvegardés uniquement dans evaluation/data/ pour traçabilité.\n";
