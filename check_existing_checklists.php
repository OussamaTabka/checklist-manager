<?php
require __DIR__ . '/backend/vendor/autoload.php';
$app = require __DIR__ . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\UserStory;
use App\Models\Checklist;

// IDs de user stories à évaluer
$evaluationIds = [34, 36, 1, 21, 24, 26, 31, 35, 37, 39, 42, 43, 38, 40, 41];

echo "=== INVENTAIRE DES CHECKLISTS IA POUR LES 15 USER STORIES ===\n\n";

$summary = [
    'with_ai_checklist' => [],
    'without_ai_checklist' => [],
];

foreach ($evaluationIds as $usId) {
    $userStory = UserStory::find($usId);
    if (!$userStory) {
        echo "❌ US ID {$usId} introuvable\n";
        continue;
    }

    // Checklists générées par IA pour cette user story
    $aiChecklists = Checklist::where('source_user_story_id', $usId)
        ->where('generated_from', 'ai')
        ->get();

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "📖 US #{$usId} | {$userStory->story_id} | {$userStory->title}\n";
    echo "   Priority: {$userStory->priority} | Status: {$userStory->status}\n";
    echo "   Project: {$userStory->project?->name}\n\n";

    if ($aiChecklists->count() > 0) {
        echo "✅ CHECKLISTS IA TROUVÉES: {$aiChecklists->count()}\n\n";
        $summary['with_ai_checklist'][] = $usId;

        foreach ($aiChecklists as $cl) {
            $itemCount = $cl->items()->count();
            echo "   • Checklist #{$cl->id}: {$cl->name}\n";
            echo "     Status: {$cl->lifecycle_status} | Items: {$itemCount}\n";
            echo "     Créée: {$cl->created_at?->format('Y-m-d H:i:s')}\n";

            // Afficher quelques items pour validation
            if ($itemCount > 0) {
                echo "     Items (premiers 3):\n";
                foreach ($cl->items()->take(3)->get() as $item) {
                    echo "       - {$item->title} (prio: {$item->priority}, crit: {$item->criticality})\n";
                }
                if ($itemCount > 3) {
                    echo "       ... et " . ($itemCount - 3) . " autres\n";
                }
            }
            echo "\n";
        }
    } else {
        echo "❌ AUCUNE CHECKLIST IA | À générer en Phase B\n\n";
        $summary['without_ai_checklist'][] = $usId;
    }
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
echo "RÉSUMÉ:\n";
echo "✅ Avec checklists IA (Phase A): " . count($summary['with_ai_checklist']) . "\n";
echo "   IDs: " . implode(", ", $summary['with_ai_checklist']) . "\n\n";
echo "❌ Sans checklists IA (Phase B): " . count($summary['without_ai_checklist']) . "\n";
echo "   IDs: " . implode(", ", $summary['without_ai_checklist']) . "\n";
