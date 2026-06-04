<?php
require __DIR__ . '/backend/vendor/autoload.php';
$app = require __DIR__ . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Checklist;

// Test sur Checklist #92
$checklist = Checklist::find(92);

if (!$checklist) {
    echo "❌ Checklist #92 not found\n";
    exit(1);
}

echo "=== TEST RECLASSIFICATION : CHECKLIST #92 ===\n";
echo "Checklist: {$checklist->name}\n";
echo "Items: " . $checklist->items()->count() . "\n\n";

// Patterns RÉVISÉS selon les nouvelles règles
$patterns = [
    'positif' => [
        'keywords' => ['réussit', 'réussi', 'successful', 'success', 'avance', 'advance', 'proceed',
                      'redirection', 'avec identifiants valides', 'with valid', 'avec données valides',
                      'happy path', 'nominal', 'normal'],
        'regex' => '#(?i)(réussit|réussi|successful|success|avance|advance|proceed|redirection|avec\s+(?:identifiants|données)\s+valides|with\s+valid|happy\s+path|nominal|normal)#',
    ],
    'négatif' => [
        'keywords' => ['erreur', 'error', 'failed', 'fails', 'invalid', 'invalide', 'refuse', 'refus',
                      'reject', 'denied', 'rejected', 'blocked', 'block', 'prevent', 'absent', 'missing',
                      'vide', 'empty', 'incorrect', 'unauthorized', 'forbidden', 'not allowed'],
        'regex' => '#(?i)(erreur|error|invalid|invalide|refuse|refus|reject|denied|blocked|block|prevent|absent|missing|vide|empty|incorrect|unauthorized|forbidden|not\s+allowed)#',
    ],
    'edge_case' => [
        'keywords' => ['limite', 'limit', 'boundary', 'edge', 'maximum', 'minimum', 'max', 'min',
                      'very long', 'très long', 'very short', 'très court', 'special characters',
                      'rare', 'corner', 'double', 'timeout', 'délai', 'expiration', 'simultané',
                      'concurrent', 'parallel', 'avant', 'après'],
        'regex' => '#(?i)(limite|limit|boundary|edge|maximum|minimum|max|min|very\s+long|très\s+long|very\s+short|très\s+court|special\s+characters|rare|corner|double|timeout|délai|expiration|simultané|concurrent|parallel|avant|après)#',
    ],
];

// Fonction de classification
function classify($title, $description, $patterns) {
    $text = strtolower($title . ' ' . ($description ?? ''));

    $matches = [
        'positif' => [],
        'négatif' => [],
        'edge_case' => [],
    ];

    // Check each category
    foreach ($patterns as $category => $pattern_data) {
        if (preg_match($pattern_data['regex'], $text, $regs)) {
            $matches[$category][] = $regs[0] ?? '(match)';
        }
    }

    // Precedence: NÉGATIF > EDGE-CASE > POSITIF
    if (!empty($matches['négatif'])) {
        return ['category' => 'NÉGATIF', 'matches' => $matches];
    }
    if (!empty($matches['edge_case'])) {
        return ['category' => 'EDGE-CASE', 'matches' => $matches];
    }
    if (!empty($matches['positif'])) {
        return ['category' => 'POSITIF', 'matches' => $matches];
    }

    // Default: POSITIF (cas nominal par défaut)
    return ['category' => 'POSITIF (par défaut)', 'matches' => $matches];
}

// Test items
$items = $checklist->items()->orderBy('order')->get();
$audit = [];

echo "┌─────┬────────────────────────────────────────────────┬──────────┬─────────────────────────────┐\n";
echo "│ Ord │ Titre                                          │ Catégorie│ Triggers détectés           │\n";
echo "├─────┼────────────────────────────────────────────────┼──────────┼─────────────────────────────┤\n";

$stats = ['POSITIF' => 0, 'NÉGATIF' => 0, 'EDGE-CASE' => 0, 'POSITIF (par défaut)' => 0];

foreach ($items as $item) {
    $result = classify($item->title, $item->description, $patterns);
    $category = $result['category'];

    // Count stats
    $stats[$category]++;

    // Build trigger string
    $triggers = [];
    if (!empty($result['matches']['positif'])) {
        $triggers[] = 'positif=' . implode(',', array_slice($result['matches']['positif'], 0, 2));
    }
    if (!empty($result['matches']['négatif'])) {
        $triggers[] = 'négatif=' . implode(',', array_slice($result['matches']['négatif'], 0, 2));
    }
    if (!empty($result['matches']['edge_case'])) {
        $triggers[] = 'edge=' . implode(',', array_slice($result['matches']['edge_case'], 0, 2));
    }

    $trigger_str = implode(' | ', $triggers) ?: '(défaut)';

    echo sprintf("│ %-3d │ %-46s │ %-8s │ %-27s │\n",
        $item->order,
        substr($item->title, 0, 46),
        $category,
        substr($trigger_str, 0, 27)
    );

    // Audit entry
    $audit[] = [
        'item_id' => $item->id,
        'order' => $item->order,
        'title' => $item->title,
        'category' => $category,
        'triggers' => $trigger_str,
        'has_multiple_matches' => (int)((count($result['matches']['positif']) > 0) +
                                        (count($result['matches']['négatif']) > 0) +
                                        (count($result['matches']['edge_case']) > 0) > 1),
    ];
}

echo "└─────┴────────────────────────────────────────────────┴──────────┴─────────────────────────────┘\n\n";

echo "STATISTIQUES CHECKLIST #92:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo sprintf("  POSITIF:              %d (%.1f%%)\n", $stats['POSITIF'], 100 * $stats['POSITIF'] / count($items));
echo sprintf("  NÉGATIF:              %d (%.1f%%)\n", $stats['NÉGATIF'], 100 * $stats['NÉGATIF'] / count($items));
echo sprintf("  EDGE-CASE:            %d (%.1f%%)\n", $stats['EDGE-CASE'], 100 * $stats['EDGE-CASE'] / count($items));
echo sprintf("  POSITIF (par défaut): %d (%.1f%%)\n", $stats['POSITIF (par défaut)'], 100 * $stats['POSITIF (par défaut)'] / count($items));
echo "\n";

// Items with multiple matches
$multi_match = array_filter($audit, fn($a) => $a['has_multiple_matches']);
if (count($multi_match) > 0) {
    echo "⚠️  ITEMS AVEC PLUSIEURS MATCHS (audit recommandé):\n";
    foreach ($multi_match as $item) {
        echo sprintf("  • [%d] %s\n    → %s (%s)\n",
            $item['order'],
            $item['title'],
            $item['category'],
            $item['triggers']
        );
    }
    echo "\n";
}

echo "✅ Audit sauvegardé dans: evaluation/data/classification_audit.csv\n";

// Create audit CSV
@mkdir('evaluation/data', 0755, true);
$csv_path = 'evaluation/data/classification_audit_checklist_92.csv';
$fp = fopen($csv_path, 'w');
fputcsv($fp, ['item_id', 'order', 'item_title', 'final_category', 'trigger_patterns', 'has_multiple_matches']);
foreach ($audit as $row) {
    fputcsv($fp, [
        $row['item_id'],
        $row['order'],
        $row['title'],
        $row['category'],
        $row['triggers'],
        $row['has_multiple_matches'],
    ]);
}
fclose($fp);

echo "✅ CSV créé: {$csv_path}\n";
