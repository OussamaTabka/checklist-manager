<?php
require __DIR__ . '/backend/vendor/autoload.php';
$app = require __DIR__ . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

require __DIR__ . '/classification_rules_final.php';

use App\Models\Checklist;

$classifier = new TestCaseClassifier();
$checklist = Checklist::find(92);
$items = $checklist->items()->orderBy('order')->get();

echo "=== RECLASSIFICATION FINALE : CHECKLIST #92 ===\n";
echo "Avec règles révisées (titre en priorité, edge-case raffiné)\n\n";

$stats = ['POSITIF' => 0, 'NÉGATIF' => 0, 'EDGE-CASE' => 0];
$audit_data = [];

echo "┌─────┬────────────────────────────────────────┬──────────┬────────────────┬──────────────────┐\n";
echo "│ Ord │ Titre                                  │ Catégorie│ Matched in     │ Exclusion        │\n";
echo "├─────┼────────────────────────────────────────┼──────────┼────────────────┼──────────────────┤\n";

foreach ($items as $item) {
    $result = $classifier->classify($item->title, $item->description);
    $category = $result['category'];
    $matched_in = $result['matched_in'];
    $exclusion = ($result['exclusion_applied'] ?? false) ? '✓ ' . substr($result['exclusion_rule'] ?? '', 0, 20) : '-';

    $stats[$category]++;

    echo sprintf("│ %-3d │ %-38s │ %-8s │ %-14s │ %-16s │\n",
        $item->order,
        substr($item->title, 0, 38),
        $category,
        $matched_in,
        $exclusion
    );

    $audit_data[] = [
        'order' => $item->order,
        'item_id' => $item->id,
        'title' => $item->title,
        'category' => $category,
        'matched_in' => $matched_in,
        'reason' => $result['reason'],
        'exclusion_applied' => $result['exclusion_applied'] ?? false,
        'exclusion_rule' => $result['exclusion_rule'] ?? null,
    ];
}

echo "└─────┴────────────────────────────────────────┴──────────┴────────────────┴─────────────────┘\n\n";

echo "STATISTIQUES CHECKLIST #92 (FINAL):\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$total = count($items);
foreach (['POSITIF', 'NÉGATIF', 'EDGE-CASE'] as $cat) {
    $count = $stats[$cat];
    $pct = $total > 0 ? round(100 * $count / $total, 1) : 0;
    echo sprintf("%-12s : %d (%.1f%%)\n", $cat, $count, $pct);
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "DÉTAILS COMPLETS (avec motif de décision)\n";
echo str_repeat("=", 80) . "\n\n";

foreach ($audit_data as $audit) {
    echo sprintf("[Item %d] %s\n", $audit['order'], $audit['title']);
    echo sprintf("  → Catégorie: %-8s | Matched in: %-12s\n", $audit['category'], $audit['matched_in']);
    echo sprintf("  → Motif: %s\n\n", $audit['reason']);
}

// Save audit CSV
@mkdir('evaluation/data', 0755, true);
$csv_path = 'evaluation/data/classification_audit_checklist_92_final.csv';
$fp = fopen($csv_path, 'w');
fputcsv($fp, ['order', 'item_id', 'item_title', 'final_category', 'matched_in', 'exclusion_applied', 'exclusion_rule', 'reason']);
foreach ($audit_data as $row) {
    fputcsv($fp, [
        $row['order'],
        $row['item_id'],
        $row['title'],
        $row['category'],
        $row['matched_in'],
        $row['exclusion_applied'] ? 'YES' : 'NO',
        $row['exclusion_rule'] ?? '',
        $row['reason'],
    ]);
}
fclose($fp);

echo "✅ Audit CSV sauvegardé : {$csv_path}\n";
