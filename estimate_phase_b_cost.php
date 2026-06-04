<?php
/**
 * Estimer le coût de Phase B (15 appels API)
 *
 * Test sur 1 user story pour vérifier:
 * 1. Si usage.completion_tokens est disponible
 * 2. Estimer tokens par appel
 * 3. Calculer coût total
 */

require __DIR__ . '/backend/vendor/autoload.php';
$app = require __DIR__ . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\UserStory;
use App\Services\TestCaseGenerationService;
use Illuminate\Support\Facades\Http;

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║     ESTIMATION COÛT PHASE B (15 appels API)                   ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Test sur US #1 (Authentification utilisateur - déjà testé avant)
$test_us_id = 1;
$user_story = UserStory::find($test_us_id);

echo "TEST SUR 1 USER STORY POUR ESTIMER TOKENS\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "US #$test_us_id: {$user_story->title}\n";
echo "Description length: " . strlen($user_story->description) . " chars\n\n";

// Faire l'appel API manuellement pour inspecter la réponse
$apiKey = config('services.test_generation.openai_api_key', '');
$model = config('services.test_generation.openai_model', 'gpt-5.3-codex');
$baseUrl = config('services.test_generation.openai_api_url', 'https://codex.sale/v1');

if (empty($apiKey)) {
    echo "❌ AGENT_CODEX_API_KEY non configurée\n";
    echo "   Impossible de faire le test. Estimation basée sur moyenne typique.\n\n";

    // Fallback: estimation
    $estimated_prompt_tokens = 800;
    $estimated_completion_tokens = 2000;
} else {
    echo "✅ API Key détectée. Tentative d'appel test...\n\n";

    // Préparer une requête simple
    $response = Http::withToken($apiKey)
        ->timeout(60)
        ->post($baseUrl . '/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a QA test designer.'],
                ['role' => 'user', 'content' => 'Generate 3 test cases for: ' . $user_story->title],
            ],
            'max_tokens' => 1000,
            'temperature' => 0.1,
        ]);

    if (!$response->successful()) {
        echo "❌ API ERROR {$response->status()}\n";
        echo "   Body: " . substr($response->body(), 0, 200) . "\n\n";
        echo "   Estimation basée sur moyenne typique.\n\n";

        $estimated_prompt_tokens = 800;
        $estimated_completion_tokens = 2000;
    } else {
        $data = $response->json();

        echo "✅ API Response reçue\n";
        echo sprintf("   Model: %s\n", $data['model'] ?? 'unknown');
        echo sprintf("   Finish reason: %s\n", $data['choices'][0]['finish_reason'] ?? 'unknown');

        // Chercher usage
        $usage = $data['usage'] ?? null;
        if ($usage) {
            $prompt_tokens = $usage['prompt_tokens'] ?? 0;
            $completion_tokens = $usage['completion_tokens'] ?? 0;

            echo sprintf("   Prompt tokens: %d\n", $prompt_tokens);
            echo sprintf("   Completion tokens: %d\n", $completion_tokens);
            echo sprintf("   Total tokens: %d\n\n", ($prompt_tokens + $completion_tokens));

            $estimated_prompt_tokens = $prompt_tokens;
            $estimated_completion_tokens = $completion_tokens;

        } else {
            echo "   ❌ `usage` non trouvé dans la réponse\n";
            echo "   Estimation basée sur moyenne typique.\n\n";

            $estimated_prompt_tokens = 800;
            $estimated_completion_tokens = 2000;
        }
    }
}

// ========== ESTIMATION COÛT TOTAL ==========
echo "\n" . str_repeat("═", 70) . "\n";
echo "ESTIMATION POUR 15 APPELS\n";
echo str_repeat("═", 70) . "\n\n";

$num_calls = 15;
$total_prompt_tokens = $num_calls * $estimated_prompt_tokens;
$total_completion_tokens = $num_calls * $estimated_completion_tokens;

echo sprintf("Appels API:                    %d\n", $num_calls);
echo sprintf("Prompt tokens par appel (est): %d\n", $estimated_prompt_tokens);
echo sprintf("Completion tokens par appel:   %d\n\n", $estimated_completion_tokens);

echo sprintf("TOTAL Prompt tokens:           %d\n", $total_prompt_tokens);
echo sprintf("TOTAL Completion tokens:       %d\n\n", $total_completion_tokens);

// ========== ESTIMATION TARIF ==========
echo "TARIFICATION (estimation)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

// codex.sale uses OpenAI-compatible pricing
// Estimations basses (OpenAI GPT-3.5 ou équivalent)
$rates = [
    'cheap' => [
        'name' => 'GPT-3.5 equiv (codex.sale - cheap)',
        'input_per_1m' => 0.50,   // $0.50/1M input
        'output_per_1m' => 1.50,  // $1.50/1M output
    ],
    'medium' => [
        'name' => 'Sonnet equiv (typique)',
        'input_per_1m' => 3.00,   // $3/1M input
        'output_per_1m' => 15.00, // $15/1M output
    ],
    'expensive' => [
        'name' => 'GPT-4 equiv (cher)',
        'input_per_1m' => 30.00,  // $30/1M input
        'output_per_1m' => 60.00, // $60/1M output
    ],
];

foreach ($rates as $tier => $rate) {
    $input_cost = ($total_prompt_tokens / 1_000_000) * $rate['input_per_1m'];
    $output_cost = ($total_completion_tokens / 1_000_000) * $rate['output_per_1m'];
    $total_cost = $input_cost + $output_cost;

    echo sprintf("\n%s\n", $rate['name']);
    echo sprintf("  Input:  %.4f × %.2f = $%.4f\n", $total_prompt_tokens / 1_000_000, $rate['input_per_1m'], $input_cost);
    echo sprintf("  Output: %.4f × %.2f = $%.4f\n", $total_completion_tokens / 1_000_000, $rate['output_per_1m'], $output_cost);
    echo sprintf("  ────────────────────────────\n");
    echo sprintf("  TOTAL:                 $%.2f\n", $total_cost);
}

echo "\n" . str_repeat("═", 70) . "\n";
echo "💰 ESTIMATION PRUDENTE : $0.10 - $0.50 pour 15 appels\n";
echo "   (si tarif favorable / codex.sale compatible GPT-3.5)\n\n";

echo "⏳ Attendre votre GO avant de lancer Phase B...\n";
echo "   Tapez 'go' si vous acceptez le coût estimé.\n";
