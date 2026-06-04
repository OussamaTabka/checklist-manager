<?php
require __DIR__ . '/backend/vendor/autoload.php';
$app = require __DIR__ . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Checklist;

// Checklist #92
$checklist = Checklist::find(92);
$items = $checklist->items()->orderBy('order')->get();

echo "=== DEBUG : CONTENU COMPLET DES 6 ITEMS ===\n\n";

foreach ($items as $i => $item) {
    echo "ITEM #" . ($i + 1) . "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Title: " . $item->title . "\n";
    echo "\nDescription:\n" . ($item->description ?? '(vide)') . "\n";
    echo "\nPriority: {$item->priority} | Criticality: {$item->criticality}\n\n";
}
