<?php

namespace App\Services;

use App\Models\ChecklistItem;
use App\Models\ChecklistItemHistory;
use App\Models\ItemChange;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\VersionItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recovers test runs that got stuck in a non-terminal state.
 *
 * A watch-mode run executes inside the web process via ->afterResponse(). If
 * that process is killed mid-run (PHP max_execution_time FatalError, dev-server
 * restart, OOM, etc.) the job never reaches persistResult()/markBlocked(), so
 * the TestRun stays on "created"/"running" and the UI shows "Execution en
 * cours..." forever. This reaper finds those orphans, once they are older than
 * a safe threshold, and marks them blocked (timed out) so the UI clears.
 *
 * Per the chosen recovery policy it does NOT re-ingest any result.json left on
 * disk; it simply marks the run blocked with a timeout message.
 */
class StaleTestRunReaper
{
    private const NON_TERMINAL_STATUSES = ['created', 'running'];

    private const TIMEOUT_MESSAGE = 'Run timed out: the execution process ended before reporting a result. Marked blocked by runs:reap.';

    /**
     * @return array{reaped:int, runs:array<int, string>}
     */
    public function reap(int $olderThanMinutes): array
    {
        $minutes = max(1, $olderThanMinutes);
        $cutoff = Carbon::now()->subMinutes($minutes);

        $runs = TestRun::query()
            ->whereIn('status', self::NON_TERMINAL_STATUSES)
            ->get()
            ->filter(function (TestRun $run) use ($cutoff): bool {
                $reference = $run->started_at ?? $run->created_at;
                if (!$reference instanceof Carbon) {
                    // Without any timestamp we cannot judge age; treat as stale.
                    return true;
                }

                return $reference->lessThan($cutoff);
            });

        $summaries = [];
        foreach ($runs as $run) {
            // Capture identifying info BEFORE the update re-syncs the model state.
            $previousStatus = (string) $run->status;
            $startedLabel = optional($run->started_at ?? $run->created_at)->toDateTimeString() ?? 'unknown';

            $this->markRunBlocked($run);

            $summaries[] = sprintf(
                '%s (was "%s", started %s)',
                $run->run_id,
                $previousStatus,
                $startedLabel,
            );
        }

        return [
            'reaped' => count($summaries),
            'runs' => $summaries,
        ];
    }

    private function markRunBlocked(TestRun $run): void
    {
        $payload = is_array($run->request_payload) ? $run->request_payload : [];
        $testCaseId = (int) ($payload['test_case_id'] ?? 0);
        $targetType = (string) ($payload['target_type'] ?? 'version_item');

        $item = $targetType === 'checklist_item'
            ? ChecklistItem::find($testCaseId)
            : VersionItem::find($testCaseId);

        DB::transaction(function () use ($run, $item, $payload): void {
            $payload['last_error'] = self::TIMEOUT_MESSAGE;

            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'summary_total' => 1,
                'summary_passed' => 0,
                'summary_failed' => 0,
                'summary_blocked' => 1,
                'summary_skipped' => 0,
                'request_payload' => $payload,
            ]);

            if (!$item) {
                return;
            }

            $this->markItemBlocked($run, $item);

            TestResult::updateOrCreate(
                $item instanceof VersionItem
                    ? ['test_run_id' => $run->id, 'version_item_id' => $item->id]
                    : ['test_run_id' => $run->id, 'checklist_item_id' => $item->id],
                [
                    'status' => 'blocked',
                    'error_type' => 'timeout',
                    'error_message' => self::TIMEOUT_MESSAGE,
                    'duration_ms' => null,
                    'artifacts' => [
                        'trace' => [],
                        'screenshot' => [],
                        'video' => [],
                    ],
                    'result_payload' => [
                        'external_id' => $item->id,
                        'status' => 'blocked',
                        'error_type' => 'timeout',
                        'error_message' => self::TIMEOUT_MESSAGE,
                    ],
                    'executed_at' => now(),
                ],
            );
        });
    }

    private function markItemBlocked(TestRun $run, VersionItem|ChecklistItem $item): void
    {
        $oldStatus = (string) $item->status;

        if ($item instanceof ChecklistItem) {
            $displayOld = in_array($oldStatus, ['Passed', 'Failed', 'Blocked', 'Not Tested'], true)
                ? $oldStatus
                : 'Not Tested';

            $item->update([
                'status' => 'blocked',
                'tested_by' => $run->requested_by,
                'tested_at' => now(),
            ]);

            if ($displayOld !== 'Blocked') {
                ChecklistItemHistory::create([
                    'checklist_item_id' => $item->id,
                    'changed_by' => $run->requested_by,
                    'field_name' => 'status',
                    'old_value' => $displayOld,
                    'new_value' => 'Blocked',
                    'change_type' => 'status_changed',
                    'notes' => 'Marked blocked by runs:reap for run ' . $run->run_id . ' (timed out).',
                ]);
            }

            return;
        }

        $item->update([
            'status' => 'Blocked',
            'tested_by' => $run->requested_by,
            'tested_at' => now(),
        ]);

        if ($oldStatus !== 'Blocked') {
            ItemChange::create([
                'version_item_id' => $item->id,
                'changed_by' => $run->requested_by,
                'field_name' => 'status',
                'old_value' => $oldStatus,
                'new_value' => 'Blocked',
                'change_type' => 'status_changed',
                'notes' => 'Marked blocked by runs:reap for run ' . $run->run_id . ' (timed out).',
            ]);
        }
    }
}
