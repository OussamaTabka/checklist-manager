<?php

namespace App\Services;

use App\Models\ChecklistItem;
use App\Models\ChecklistItemHistory;
use Illuminate\Support\Facades\Auth;

class ChecklistItemHistoryService
{
    /**
     * Log a change to a checklist item (scenario)
     * 
     * @param ChecklistItem $item
     * @param string $fieldName
     * @param mixed $oldValue
     * @param mixed $newValue
     * @param string $changeType
     * @param string|null $notes
     * @return ChecklistItemHistory
     */
    public function logChange(
        ChecklistItem $item,
        string $fieldName,
        mixed $oldValue,
        mixed $newValue,
        string $changeType = 'updated',
        ?string $notes = null
    ): ChecklistItemHistory {
        return ChecklistItemHistory::create([
            'checklist_item_id' => $item->id,
            'changed_by' => Auth::id(),
            'field_name' => $fieldName,
            'old_value' => is_array($oldValue) ? json_encode($oldValue) : (string) $oldValue,
            'new_value' => is_array($newValue) ? json_encode($newValue) : (string) $newValue,
            'change_type' => $changeType,
            'notes' => $notes,
        ]);
    }

    /**
     * Update item and log the changes
     * 
     * @param ChecklistItem $item
     * @param array $data
     * @param string|null $notes
     * @return ChecklistItem
     */
    public function updateWithHistory(ChecklistItem $item, array $data, ?string $notes = null): ChecklistItem
    {
        foreach ($data as $fieldName => $newValue) {
            if ($item->$fieldName !== $newValue) {
                $oldValue = $item->$fieldName;
                
                // Log the change
                $this->logChange(
                    $item,
                    $fieldName,
                    $oldValue,
                    $newValue,
                    'updated',
                    $notes
                );
            }
        }

        // Update the item
        $item->update($data);

        return $item;
    }

    /**
     * Update item status and log the change
     * 
     * @param ChecklistItem $item
     * @param string $newStatus
     * @param string|null $notes
     * @return ChecklistItem
     */
    public function updateStatus(ChecklistItem $item, string $newStatus, ?string $notes = null): ChecklistItem
    {
        $oldStatus = $item->status;

        // Log the status change
        $this->logChange(
            $item,
            'status',
            $oldStatus,
            $newStatus,
            'status_changed',
            $notes
        );

        // Update status and tested info
        $item->update([
            'status' => $newStatus,
            'tested_by' => Auth::id(),
            'tested_at' => now(),
        ]);

        return $item;
    }

    /**
     * Get history for an item with user information
     * 
     * @param ChecklistItem $item
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getHistory(ChecklistItem $item)
    {
        return $item->history()
            ->with('changedBy:id,name,email')
            ->get();
    }

    /**
     * Get status change history for an item
     * 
     * @param ChecklistItem $item
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getStatusHistory(ChecklistItem $item)
    {
        return $item->history()
            ->statusChanges()
            ->with('changedBy:id,name,email')
            ->get();
    }
}
