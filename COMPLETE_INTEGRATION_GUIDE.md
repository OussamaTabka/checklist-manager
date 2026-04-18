# Complete Code Example - All Integration Pieces Together

This file shows all code pieces in one place for reference and verification.

---

## 1️⃣ .env Configuration

```env
# .env (root of project)
VITE_TEST_AGENT_URL=http://localhost:8000
VITE_APP_URL=http://localhost:8000

# Production:
# VITE_TEST_AGENT_URL=https://test-agent.yourdomain.com
# VITE_APP_URL=https://yourdomain.com
```

---

## 2️⃣ app.js - Vue Initialization

```javascript
// resources/js/app.js
import { createApp } from 'vue';
import RunTestsButton from './components/RunTestsButton.vue';

const app = createApp({
    template: '<div id="app"><slot /></div>',
});

// Register RunTestsButton component globally
app.component('RunTestsButton', RunTestsButton);

// Mount app
app.mount('#app');

export default app;
```

---

## 3️⃣ routes/api.php - Laravel Routes

```php
// routes/api.php
use App\Http\Controllers\TestResultController;

// Update test results after tests complete
Route::post('/update-test-results', [TestResultController::class, 'updateResults'])
    ->name('api.update-test-results');

// Optional: Health check
Route::get('/test-agent-health', [TestResultController::class, 'checkHealth'])
    ->name('api.test-agent-health');
```

---

## 4️⃣ TestResultController.php - Full Controller

```php
<?php
// app/Http/Controllers/TestResultController.php

namespace App\Http\Controllers;

use App\Models\Checklist;
use App\Models\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class TestResultController extends Controller
{
    public function updateResults(Request $request)
    {
        try {
            $validated = $request->validate([
                'checklist_id' => 'required|integer|exists:checklists,id',
                'results' => 'required|array',
                'results.passed' => 'required|integer|min:0',
                'results.failed' => 'required|integer|min:0',
                'results.tests' => 'required|array',
                'results.tests.*.id' => 'required|integer',
                'results.tests.*.status' => 'required|in:passed,failed,pending',
                'results.tests.*.title' => 'required|string',
            ]);

            $checklistId = $validated['checklist_id'];
            $results = $validated['results'];
            $now = Carbon::now();

            $checklist = Checklist::findOrFail($checklistId);

            $updatedCount = 0;
            foreach ($results['tests'] as $testResult) {
                $testCase = TestCase::where('id', $testResult['id'])
                    ->where('checklist_id', $checklistId)
                    ->first();

                if ($testCase) {
                    $testCase->update([
                        'status' => $testResult['status'],
                        'last_run_at' => $now,
                        'run_count' => ($testCase->run_count ?? 0) + 1,
                    ]);
                    $updatedCount++;
                }
            }

            \Log::info("Test results updated for checklist {$checklistId}");

            return response()->json([
                'success' => true,
                'message' => "Test results updated successfully ({$updatedCount} test cases)",
                'updated_count' => $updatedCount,
                'timestamp' => $now->toIso8601String(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'details' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Test results update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage() ?: 'Failed to update test results',
            ], 500);
        }
    }

    public function checkHealth()
    {
        try {
            $testAgentUrl = env('VITE_TEST_AGENT_URL', 'http://localhost:8000');
            $response = Http::timeout(5)->get("{$testAgentUrl}/health");

            if ($response->ok()) {
                return response()->json([
                    'healthy' => true,
                    'api_url' => $testAgentUrl,
                ]);
            }

            return response()->json([
                'healthy' => false,
                'error' => "HTTP {$response->status()}",
            ], 503);
        } catch (\Exception $e) {
            return response()->json([
                'healthy' => false,
                'error' => 'Cannot reach test agent',
            ], 503);
        }
    }
}
```

---

## 5️⃣ ChecklistController.php - Show Method

```php
// app/Http/Controllers/ChecklistController.php
public function show(Checklist $checklist)
{
    // Load test cases
    $testCases = $checklist->testCases()->get();

    return view('checklists.show', [
        'checklist' => $checklist,
        'testCases' => $testCases,
    ]);
}
```

---

## 6️⃣ Blade Template - Usage

```blade
{{-- resources/views/checklists/show.blade.php --}}
@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="row">
        <div class="col-lg-8">
            {{-- Checklist Header --}}
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h2 class="mb-0">{{ $checklist->title }}</h2>
                </div>
                <div class="card-body">
                    <p class="text-muted">{{ $checklist->description }}</p>
                </div>
            </div>

            {{-- Test Cases Section --}}
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">Test Cases ({{ count($testCases) }})</h4>
                        <a href="{{ route('checklists.add-test-case', $checklist->id) }}" class="btn btn-sm btn-success">
                            + Add
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    {{-- Vue App Container --}}
                    <div id="app">
                        {{-- Run Tests Button Component --}}
                        <run-tests-button 
                          :test-cases="@json($testCases)"
                          :checklist-id="{{ $checklist->id }}"
                          @tests-complete="handleTestsComplete"
                          @tests-failed="handleTestsFailed"
                        />
                    </div>

                    {{-- Test Cases Table --}}
                    <div class="table-responsive mt-4">
                        @if(count($testCases) > 0)
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Test Name</th>
                                        <th>Status</th>
                                        <th>Last Run</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($testCases as $test)
                                    <tr>
                                        <td><strong>{{ $test->title }}</strong></td>
                                        <td>
                                            @if($test->status === 'passed')
                                                <span class="badge bg-success">✅ Passed</span>
                                            @elseif($test->status === 'failed')
                                                <span class="badge bg-danger">❌ Failed</span>
                                            @else
                                                <span class="badge bg-secondary">⏳ Pending</span>
                                            @endif
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                {{ $test->last_run_at?->diffForHumans() ?? 'Never' }}
                                            </small>
                                        </td>
                                        <td>
                                            <a href="#" class="btn btn-sm btn-outline-primary">Edit</a>
                                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            <div class="alert alert-info">
                                ℹ️ No test cases yet. 
                                <a href="{{ route('checklists.add-test-case', $checklist->id) }}">Add test case</a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Include Vue App --}}
@vite(['resources/js/app.js'])

{{-- Event Handlers --}}
<script>
window.handleTestsComplete = function(results) {
    console.log('✅ Tests completed!', results);

    fetch('/api/update-test-results', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({
            checklist_id: {{ $checklist->id }},
            results: results
        }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            console.log('✅ Results saved');
            setTimeout(() => location.reload(), 1500);
        }
    })
    .catch(err => console.error('❌ Error:', err));
};

window.handleTestsFailed = function(error) {
    console.error('❌ Tests failed:', error);
};
</script>
@endsection
```

---

## 7️⃣ Database Migration

```php
<?php
// database/migrations/2026_04_12_000000_add_test_execution_columns_to_test_cases.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_cases', function (Blueprint $table) {
            if (!Schema::hasColumn('test_cases', 'status')) {
                $table->enum('status', ['pending', 'passed', 'failed'])
                    ->default('pending')
                    ->after('description');
            }
            if (!Schema::hasColumn('test_cases', 'last_run_at')) {
                $table->timestamp('last_run_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('test_cases', 'run_count')) {
                $table->integer('run_count')->default(0)->after('last_run_at');
            }
            $table->index('status');
            $table->index('last_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('test_cases', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['last_run_at']);
            $table->dropColumn(['status', 'last_run_at', 'run_count']);
        });
    }
};
```

---

## 8️⃣ Model Relationships

```php
// app/Models/Checklist.php
public function testCases()
{
    return $this->hasMany(TestCase::class);
}
```

```php
// app/Models/TestCase.php
public function checklist()
{
    return $this->belongsTo(Checklist::class);
}
```

---

## 9️⃣ Execution Flow Diagram

```
User clicks "Run Tests"
        ↓
[RunTestsButton Component]
- Validates test cases exist
- Sets loading = true
- Calls submitTests()
        ↓
[testAgentService.submitTests()]
- POST /run-tests
- Get job_id
- Return success
        ↓
[RunTestsButton Component]
- progress = 20%
- Calls waitForCompletion(job_id)
        ↓
[testAgentService.waitForCompletion()]
- Poll GET /jobs/{job_id} every 2 seconds
- Update progress 30-95%
- When status = "completed", return results
        ↓
[RunTestsButton Component]
- Parse results
- progress = 100%
- Show results UI (✅ Passed: 5, ❌ Failed: 0)
- Emit 'tests-complete' event
        ↓
[Blade event handler]
- Listen for 'tests-complete'
- POST to /api/update-test-results
- Save to database
        ↓
[TestResultController.updateResults()]
- Validate data
- Update test_cases table (status, last_run_at)
- Return success response
        ↓
[Blade JavaScript]
- Receive success
- Reload page to show updated badges
        ↓
✅ Page shows updated test statuses
```

---

## 🔟 Summary of Changes

### Files Created:
1. ✅ `resources/js/services/testAgentService.js`
2. ✅ `resources/js/components/RunTestsButton.vue`
3. ✅ `resources/js/app.js`
4. ✅ `app/Http/Controllers/TestResultController.php`
5. ✅ `database/migrations/2026_04_12_000000_add_test_execution_columns_to_test_cases.php`

### Files Modified:
1. ✅ `.env` - Added VITE_TEST_AGENT_URL and VITE_APP_URL
2. ✅ `routes/api.php` - Added test result routes
3. ✅ `resources/views/checklists/show.blade.php` - Integrated component
4. ✅ `app/Http/Controllers/ChecklistController.php` - Updated show method
5. ✅ `app/Models/TestCase.php` - Added relationship
6. ✅ `app/Models/Checklist.php` - Added relationship

### New Commands:
```bash
# Run migration
php artisan migrate

# Reset if needed
php artisan migrate:rollback

# Rebuild assets
npm run dev
npm run build
```

---

## ✅ Verification Steps

1. **Database**: Check columns exist
   ```bash
   php artisan tinker
   >>> \Illuminate\Support\Facades\Schema::getColumnListing('test_cases')
   ```

2. **Routes**: Verify registered
   ```bash
   php artisan route:list | grep test
   ```

3. **Component**: Verify on page
   - Navigate to checklist
   - Should see "🚀 Run Tests" button

4. **Test**: Execute workflow
   - Click button
   - Watch progress
   - See results
   - Check database updated

5. **Logs**: Watch for successful execution
   - Browser console (F12)
   - Laravel logs (tail -f storage/logs/laravel.log)

---

**That's everything! Copy sections as needed for your project.**
