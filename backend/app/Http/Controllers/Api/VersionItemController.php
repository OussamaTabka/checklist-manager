<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VersionItem;
use Illuminate\Http\Request;

class VersionItemController extends Controller
{
    public function updateStatus(Request $request, VersionItem $versionItem)
    {
        $data = $request->validate([
            'status' => ['required', 'in:Not Tested,Passed,Failed,Blocked'],
        ]);

        if ($data['status'] === 'Not Tested') {
            $versionItem->update([
                'status' => 'Not Tested',
                'tested_by' => null,
                'tested_at' => null,
            ]);
        } else {
            $versionItem->update([
                'status' => $data['status'],
                'tested_by' => auth()->id(),
                'tested_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Status updated',
            'item' => $versionItem->fresh(), // pour renvoyer les valeurs mises à jour
        ]);
    }
}