<?php
/**
 * MESURE DE COUVERTURE FONCTIONNELLE - Phase A
 *
 * Pour chaque user story :
 * 1. Extraire business_rules et acceptance_criteria
 * 2. Pour chaque règle/critère : extraire 3-5 mots-clés porteurs de sens
 * 3. Marquer COUVERTE si ≥2 mots-clés apparaissent dans un item
 * 4. Calculer couverture_pct = règles couvertes / total × 100
 * 5. Sauvegarder résultats dans CSV + JSON agrégé
 */

require __DIR__ . '/../../backend/vendor/autoload.php';
$app = require __DIR__ . '/../../backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\UserStory;
use App\Models\Checklist;

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         MESURE DE COUVERTURE FONCTIONNELLE - PHASE A           ║\n";
echo "║             11 User Stories | 27 Checklists IA                ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// User stories évaluées en Phase A
$phase_a_us_ids = [34, 36, 1, 35, 37, 39, 42, 43, 38, 40, 41];

$coverage_results = [];
$csv_data = [];

// Mots vides à ignorer
$stopwords = [
    'a', 'the', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'is', 'are',
    'le', 'la', 'les', 'un', 'une', 'des', 'et', 'ou', 'mais', 'dans', 'sur', 'à', 'pour', 'de', 'est', 'sont',
    'must', 'should', 'can', 'will', 'shall', 'may', 'doit', 'peut', 'doivent',
    'user', 'system', 'page', 'field', 'button', 'click', 'view', 'see', 'display',
    'utilisateur', 'système', 'page', 'champ', 'bouton', 'cliquer', 'afficher', 'voir',
];

/**
 * Extraire les mots-clés porteurs de sens d'un texte
 */
function extract_keywords($text, $stopwords) {
    // Normaliser : minuscules, supprimer accents, supprimer ponctuation
    $text = mb_strtolower($text, 'UTF-8');
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);

    // Splitter en mots
    $words = array_filter(array_map('trim', preg_split('/\s+/', $text)));

    // Filtrer les mots vides et les mots trop courts
    $keywords = array_filter($words, function ($word) use ($stopwords) {
        return strlen($word) >= 4 && !in_array($word, $stopwords);
    });

    return array_values(array_slice(array_unique($keywords), 0, 5)); // Max 5 keywords
}

/**
 * Vérifier si une règle/critère est couverte par les items
 */
function is_covered($rule_keywords, $items_text) {
    $items_lower = mb_strtolower($items_text, 'UTF-8');
    $items_lower = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $items_lower);

    $matched_count = 0;
    foreach ($rule_keywords as $keyword) {
        if (strpos($items_lower, $keyword) !== false) {
            $matched_count++;
        }
    }

    return $matched_count >= 2; // Au moins 2 mots-clés match
}

// ========== ÉVALUATION PAR USER STORY ==========
foreach ($phase_a_us_ids as $us_id) {
    $user_story = UserStory::with('project')->find($us_id);

    if (!$user_story) {
        echo "❌ US #{$us_id} introuvable\n";
        continue;
    }

    echo "\n" . str_repeat("─", 60) . "\n";
    echo "📖 US #{$us_id} | {$user_story->story_id} | {$user_story->title}\n";

    // Récupérer les checklists IA
    $checklists = Checklist::where('source_user_story_id', $us_id)
        ->where('generated_from', 'ai')
        ->with('items')
        ->get();

    if ($checklists->count() === 0) {
        echo "   ⚠️  Pas de checklist IA\n";
        continue;
    }

    // Agréger tous les items en texte
    $all_items_text = '';
    foreach ($checklists as $cl) {
        foreach ($cl->items as $item) {
            $all_items_text .= ' ' . $item->title . ' ' . $item->description;
        }
    }

    // ========== MESURER COUVERTURE BUSINESS RULES ==========
    $business_rules = $user_story->business_rules ?? [];
    if (is_string($business_rules)) {
        $business_rules = json_decode($business_rules, true) ?? [];
    }

    $rules_covered = 0;
    $rules_not_covered = [];

    foreach ($business_rules as $rule) {
        $rule_text = is_array($rule) ? implode(' ', $rule) : $rule;
        $keywords = extract_keywords($rule_text, $stopwords);

        if (is_covered($keywords, $all_items_text)) {
            $rules_covered++;
        } else {
            $rules_not_covered[] = $rule_text;
        }
    }

    $rules_coverage_pct = count($business_rules) > 0
        ? round(100 * $rules_covered / count($business_rules), 1)
        : 0;

    // ========== MESURER COUVERTURE ACCEPTANCE CRITERIA ==========
    $acceptance_criteria = $user_story->acceptance_criteria ?? '';
    $criteria_lines = array_filter(array_map('trim', preg_split('/[\n•\-]/', $acceptance_criteria)));
    $criteria_lines = array_filter($criteria_lines, fn($line) => strlen($line) > 10);

    $criteria_covered = 0;
    $criteria_not_covered = [];

    foreach ($criteria_lines as $criterion) {
        $keywords = extract_keywords($criterion, $stopwords);

        if (is_covered($keywords, $all_items_text)) {
            $criteria_covered++;
        } else {
            $criteria_not_covered[] = $criterion;
        }
    }

    $criteria_coverage_pct = count($criteria_lines) > 0
        ? round(100 * $criteria_covered / count($criteria_lines), 1)
        : 0;

    // ========== COVERAGE GLOBAL ==========
    $coverage_global = ($rules_coverage_pct + $criteria_coverage_pct) / 2;

    echo sprintf("   📊 Rules: %d/%d (%.1f%%) | Criteria: %d/%d (%.1f%%) | Global: %.1f%%\n",
        $rules_covered, count($business_rules), $rules_coverage_pct,
        $criteria_covered, count($criteria_lines), $criteria_coverage_pct,
        $coverage_global
    );

    // Afficher les non couverts (premiers 3)
    if (!empty($rules_not_covered)) {
        echo sprintf("   ⚠️  Rules non couverts (%d):\n", count($rules_not_covered));
        foreach (array_slice($rules_not_covered, 0, 3) as $r) {
            echo sprintf("      - %s\n", substr($r, 0, 60));
        }
        if (count($rules_not_covered) > 3) {
            echo sprintf("      ... et %d autres\n", count($rules_not_covered) - 3);
        }
    }

    $coverage_results[$us_id] = [
        'us_id' => $us_id,
        'story_id' => $user_story->story_id,
        'title' => $user_story->title,
        'priority' => $user_story->priority,
        'nb_rules' => count($business_rules),
        'rules_covered' => $rules_covered,
        'rules_coverage_pct' => $rules_coverage_pct,
        'nb_criteria' => count($criteria_lines),
        'criteria_covered' => $criteria_covered,
        'criteria_coverage_pct' => $criteria_coverage_pct,
        'coverage_global' => $coverage_global,
        'rules_not_covered_count' => count($rules_not_covered),
    ];

    $csv_data[] = [
        'us_id' => $us_id,
        'story_id' => $user_story->story_id,
        'title' => substr($user_story->title, 0, 50),
        'priority' => $user_story->priority,
        'nb_rules' => count($business_rules),
        'rules_covered' => $rules_covered,
        'rules_coverage_pct' => $rules_coverage_pct,
        'nb_criteria' => count($criteria_lines),
        'criteria_covered' => $criteria_covered,
        'criteria_coverage_pct' => $criteria_coverage_pct,
        'coverage_global' => $coverage_global,
    ];
}

// ========== AGRÉGATION ==========
echo "\n\n" . str_repeat("═", 70) . "\n";
echo "AGRÉGATION DES RÉSULTATS\n";
echo str_repeat("═", 70) . "\n\n";

// Statistiques globales
$global_rules_coverage = 0;
$global_criteria_coverage = 0;
$global_coverage = 0;
$count = 0;

$by_priority = [];

foreach ($coverage_results as $result) {
    $global_rules_coverage += $result['rules_coverage_pct'];
    $global_criteria_coverage += $result['criteria_coverage_pct'];
    $global_coverage += $result['coverage_global'];
    $count++;

    $priority = $result['priority'] ?? 'unknown';
    if (!isset($by_priority[$priority])) {
        $by_priority[$priority] = [
            'coverage' => [],
            'count' => 0,
        ];
    }
    $by_priority[$priority]['coverage'][] = $result['coverage_global'];
    $by_priority[$priority]['count']++;
}

$avg_rules_coverage = round($global_rules_coverage / $count, 1);
$avg_criteria_coverage = round($global_criteria_coverage / $count, 1);
$avg_global_coverage = round($global_coverage / $count, 1);

echo "MOYENNES GLOBALES\n";
echo "─────────────────────────────────────────────────────\n";
echo sprintf("  Business Rules Coverage:     %.1f%%\n", $avg_rules_coverage);
echo sprintf("  Acceptance Criteria Coverage: %.1f%%\n", $avg_criteria_coverage);
echo sprintf("  Coverage Global (moyenne):   %.1f%%\n\n", $avg_global_coverage);

echo "PAR PRIORITÉ\n";
echo "─────────────────────────────────────────────────────\n";
foreach ($by_priority as $priority => $data) {
    $avg = round(array_sum($data['coverage']) / count($data['coverage']), 1);
    echo sprintf("  %-10s : %.1f%% (%d US)\n", $priority, $avg, $data['count']);
}

// ========== SAUVEGARDER RÉSULTATS ==========
@mkdir('evaluation/data', 0755, true);
@mkdir('evaluation/scripts', 0755, true);

// CSV détaillé
$csv_path = 'evaluation/data/phase_a_coverage.csv';
$fp = fopen($csv_path, 'w');
fputcsv($fp, ['us_id', 'story_id', 'title', 'priority', 'nb_rules', 'rules_covered', 'rules_coverage_pct',
              'nb_criteria', 'criteria_covered', 'criteria_coverage_pct', 'coverage_global']);
foreach ($csv_data as $row) {
    fputcsv($fp, $row);
}
fclose($fp);

// JSON agrégé
$summary = [
    'timestamp' => now()->toIso8601String(),
    'phase' => 'A',
    'methodology' => [
        'description' => 'Correspondance par mots-clés (≥2 mots-clés entre règle et items)',
        'limitation' => 'Peut sous-estimer la couverture pour règles formulées différemment',
        'stopwords' => count($stopwords) . ' mots vides exclus',
    ],
    'global' => [
        'us_count' => count($coverage_results),
        'avg_rules_coverage_pct' => $avg_rules_coverage,
        'avg_criteria_coverage_pct' => $avg_criteria_coverage,
        'avg_global_coverage_pct' => $avg_global_coverage,
    ],
    'by_priority' => array_map(function ($priority, $data) {
        return [
            'priority' => $priority,
            'us_count' => $data['count'],
            'avg_coverage_pct' => round(array_sum($data['coverage']) / count($data['coverage']), 1),
        ];
    }, array_keys($by_priority), array_values($by_priority)),
    'detailed_results' => $coverage_results,
];

$summary_path = 'evaluation/data/phase_a_coverage_summary.json';
file_put_contents($summary_path, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n\n" . str_repeat("═", 70) . "\n";
echo "✅ RÉSULTATS SAUVEGARDÉS\n";
echo str_repeat("═", 70) . "\n";
echo sprintf("  CSV détaillé     : %s\n", $csv_path);
echo sprintf("  JSON agrégé      : %s\n", $summary_path);
echo sprintf("\n  Coverage global  : %.1f%%\n", $avg_global_coverage);
echo sprintf("  Prêt pour rapport\n");
