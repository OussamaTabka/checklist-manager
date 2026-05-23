<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('user_stories', 'story_id')) {
            return;
        }

        $stories = DB::table('user_stories')
            ->select(['id', 'project_id', 'story_id'])
            ->orderBy('id')
            ->get();

        $usedReferences = [];

        foreach ($stories as $story) {
            $normalizedReference = $this->normalizeReference($story->story_id);

            if ($normalizedReference !== null) {
                $usedReferences[$normalizedReference] = true;
                continue;
            }

            $baseReference = sprintf('US-%d-%d', (int) $story->project_id, (int) $story->id);
            $candidateReference = $baseReference;
            $suffix = 1;

            while (isset($usedReferences[$this->normalizeReference($candidateReference)])) {
                $candidateReference = $baseReference . '-' . $suffix;
                $suffix++;
            }

            DB::table('user_stories')
                ->where('id', $story->id)
                ->update(['story_id' => $candidateReference]);

            $usedReferences[$this->normalizeReference($candidateReference)] = true;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left empty: do not erase backfilled references.
    }

    private function normalizeReference(?string $reference): ?string
    {
        $trimmedReference = trim((string) $reference);

        if ($trimmedReference === '') {
            return null;
        }

        return mb_strtolower($trimmedReference);
    }
};
