<?php
/**
 * PHASE A : Évaluation sans appel API
 * Mesure sur les 11 user stories avec checklists IA existantes
 *
 * Métriques :
 * 1. Couverture fonctionnelle (% acceptance_criteria et business_rules couverts)
 * 2. Diversité des cas (distribution positif/négatif/edge-case)
 * 3. Validité structurelle (persistance, complétude des champs)
 */

require __DIR__ . '/backend/vendor/autoload.php';
$app = require __DIR__ . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

require __DIR__ . '/classification_rules_final.php';

use App\Models\UserStory;
use App\Models\Checklist;
use App\Models\ChecklistItem;

$classifier = new TestCaseClassifier();

// User stories à évaluer (Phase A)
$evaluation_us_ids = [34, 36, 1, 35, 37, 39, 42, 43, 38, 40, 41];

echo "╔═══════════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                     PHASE A : ÉVALUATION SANS APPEL API                          ║\n";
echo "║             11 User Stories | 32 Checklists IA | ~192 Items de test              ║\n";
echo "╚═══════════════════════════════════════════════════════════════════════════════════╝\n\n";

// Structures de données pour l'agrégation
$results = [
    'global' => [
        'us_count' => 0,
        'checklist_count' => 0,
        'item_count' => 0,
        'items_classified' => 0,
    ],
    'coverage' => [],
    'diversity' => [
        'positif' => 0,
        'negatif' => 0,
        'edge_case' => 0,
    ],
    'validity' => [
        'checklists_evaluated' => 0,
        'checklists_with_items' => 0,
        'missing_fields' => [],
    ],
];

$audit_csv = [];
$us_results = [];

// ========== ÉVALUATION DES USER STORIES ==========
foreach ($evaluation_us_ids as $us_id) {
    $user_story = UserStory::with('project')->find($us_id);

    if (!$user_story) {
        echo "❌ US #{$us_id} introuvable\n";
        continue;
    }

    $results['global']['us_count']++;

    echo "\n" . str_repeat("─", 80) . "\n";
    echo "📖 US #{$us_id} | {$user_story->story_id} | {$user_story->title}\n";
    echo "   Priority: {$user_story->priority} | Project: {$user_story->project?->name}\n";

    // Récupérer les checklists IA pour cette user story
    $ai_checklists = Checklist::where('source_user_story_id', $us_id)
        ->where('generated_from', 'ai')
        ->with('items')
        ->get();

    if ($ai_checklists->count() === 0) {
        echo "   ⚠️  Aucune checklist IA trouvée (Phase B)\n";
        continue;
    }

    $results['global']['checklist_count'] += $ai_checklists->count();

    // Métriques pour cette US
    $us_diversity = ['positif' => 0, 'negatif' => 0, 'edge_case' => 0];
    $us_items_total = 0;

    // ========== ÉVALUATION DES CHECKLISTS ==========
    foreach ($ai_checklists as $checklist) {
        $results['validity']['checklists_evaluated']++;

        $items = $checklist->items()->orderBy('order')->get();

        if ($items->count() > 0) {
            $results['validity']['checklists_with_items']++;
        }

        $results['global']['item_count'] += $items->count();
        $us_items_total += $items->count();

        // ========== CLASSIFICATION DES ITEMS ==========
        foreach ($items as $item) {
            $results['global']['items_classified']++;

            $classification = $classifier->classify($item->title, $item->description);
            $category = $classification['category'];

            // Compter pour diversité
            $category_key = strtolower(str_replace('EDGE-CASE', 'edge_case', str_replace('NÉGATIF', 'negatif', str_replace('POSITIF', 'positif', $category))));
            if (isset($results['diversity'][$category_key])) {
                $results['diversity'][$category_key]++;
            }
            $us_diversity[$category_key]++;

            // Vérifier complétude des champs
            $required_fields = ['title', 'description', 'priority', 'criticality'];
            $missing = [];
            foreach ($required_fields as $field) {
                if (empty($item->{$field})) {
                    $missing[] = $field;
                }
            }

            if (!empty($missing)) {
                $results['validity']['missing_fields'][] = [
                    'item_id' => $item->id,
                    'checklist_id' => $checklist->id,
                    'missing' => $missing,
                ];
            }

            // Audit CSV
            $audit_csv[] = [
                'item_id' => $item->id,
                'checklist_id' => $checklist->id,
                'us_id' => $us_id,
                'item_title' => substr($item->title, 0, 80),
                'final_category' => $category,
                'matched_in' => $classification['matched_in'] ?? 'unknown',
                'exclusion_applied' => $classification['exclusion_applied'] ?? false,
                'exclusion_rule' => $classification['exclusion_rule'] ?? '',
                'priority' => $item->priority,
                'criticality' => $item->criticality,
            ];
        }
    }

    // Afficher diversité pour cette US
    $total_items = $us_items_total;
    if ($total_items > 0) {
        $pos_pct = round(100 * $us_diversity['positif'] / $total_items, 1);
        $neg_pct = round(100 * $us_diversity['negatif'] / $total_items, 1);
        $edge_pct = round(100 * $us_diversity['edge_case'] / $total_items, 1);

        echo "   📊 Diversité: POSITIF {$us_diversity['positif']} ({$pos_pct}%) | NÉGATIF {$us_diversity['negatif']} ({$neg_pct}%) | EDGE-CASE {$us_diversity['edge_case']} ({$edge_pct}%)\n";

        $us_results[$us_id] = [
            'story' => $user_story,
            'checklist_count' => $ai_checklists->count(),
            'item_count' => $total_items,
            'diversity' => $us_diversity,
        ];
    }
}

// ========== RÉSUMÉ GLOBAL ==========
echo "\n\n" . str_repeat("═", 80) . "\n";
echo "RÉSUMÉ PHASE A\n";
echo str_repeat("═", 80) . "\n\n";

$total_items = $results['global']['item_count'];
if ($total_items > 0) {
    $pos_pct = round(100 * $results['diversity']['positif'] / $total_items, 1);
    $neg_pct = round(100 * $results['diversity']['negatif'] / $total_items, 1);
    $edge_pct = round(100 * $results['diversity']['edge_case'] / $total_items, 1);

    echo "Global Statistics:\n";
    echo sprintf("  • User Stories évaluées:        %d\n", $results['global']['us_count']);
    echo sprintf("  • Checklists IA évaluées:       %d\n", $results['global']['checklist_count']);
    echo sprintf("  • Items de test classifiés:     %d\n", $results['global']['items_classified']);
    echo "\nDiversité des cas (tous items):\n";
    echo sprintf("  • POSITIF:      %d items (%.1f%%)\n", $results['diversity']['positif'], $pos_pct);
    echo sprintf("  • NÉGATIF:      %d items (%.1f%%)\n", $results['diversity']['negatif'], $neg_pct);
    echo sprintf("  • EDGE-CASE:    %d items (%.1f%%)\n", $results['diversity']['edge_case'], $edge_pct);
    echo "\nValidité structurelle:\n";
    echo sprintf("  • Checklists évaluées:          %d\n", $results['validity']['checklists_evaluated']);
    echo sprintf("  • Checklists avec items:        %d\n", $results['validity']['checklists_with_items']);
    echo sprintf("  • Items avec champs manquants:  %d\n", count($results['validity']['missing_fields']));

    if (!empty($results['validity']['missing_fields'])) {
        echo "\n  ⚠️  Items avec champs manquants:\n";
        foreach (array_slice($results['validity']['missing_fields'], 0, 5) as $missing) {
            echo sprintf("    - Item %d: manquent %s\n", $missing['item_id'], implode(', ', $missing['missing']));
        }
        if (count($results['validity']['missing_fields']) > 5) {
            echo sprintf("    ... et %d autres\n", count($results['validity']['missing_fields']) - 5);
        }
    }
}

// ========== SAUVEGARDER L'AUDIT CSV ==========
@mkdir('evaluation/data', 0755, true);
$csv_path = 'evaluation/data/phase_a_classification_audit.csv';
$fp = fopen($csv_path, 'w');
fputcsv($fp, ['item_id', 'checklist_id', 'us_id', 'item_title', 'final_category', 'matched_in', 'exclusion_applied', 'exclusion_rule', 'priority', 'criticality']);
foreach ($audit_csv as $row) {
    fputcsv($fp, $row);
}
fclose($fp);

echo "\n✅ Audit CSV Phase A sauvegardé : {$csv_path}\n";
echo sprintf("   (%d lignes)\n", count($audit_csv));

// ========== SAUVEGARDER LES RÉSULTATS GLOBAUX ==========
$results_path = 'evaluation/data/phase_a_results.json';
file_put_contents($results_path, json_encode([
    'timestamp' => now()->toIso8601String(),
    'phase' => 'A',
    'global_stats' => $results['global'],
    'diversity_stats' => $results['diversity'],
    'validity_stats' => $results['validity'],
    'us_results' => $us_results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "✅ Résultats Phase A sauvegardés : {$results_path}\n";
