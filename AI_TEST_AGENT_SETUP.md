# AI Test Agent Integration - Complete Setup Guide

## 📋 Quick Overview

This integration enables your Laravel + Vue 3 application to submit test cases to an external AI Test Agent API and display real-time test execution results. Users click "Run Tests" and see progress with detailed results upon completion.

---

## 🚀 QUICK START (5 minutes)

### 1. Update .env File
Add these two lines to your `.env` file:
```env
VITE_TEST_AGENT_URL=http://localhost:8000
VITE_APP_URL=http://localhost:8000
```

### 2. Start Test Agent
```bash
# From your test agent project directory
docker-compose up
```

### 3. Install/Update Frontend Dependencies
```bash
# In your project root
npm install
```

### 4. Start Development Server
```bash
npm run dev
```

### 5. Copy Components
The following files have already been created:
- ✅ `resources/js/services/testAgentService.js` - API service layer
- ✅ `resources/js/components/RunTestsButton.vue` - Vue component
- ✅ `resources/js/app.js` - Vue app initialization

---

## 📁 FILE-BY-FILE SETUP

### File 1: `.env` Configuration
**Location:** Root of project

```env
# Copy these lines to your .env file
VITE_TEST_AGENT_URL=http://localhost:8000
VITE_APP_URL=http://localhost:8000
```

**⚠️ Important:**
- NO QUOTES around values
- Restart dev server after changing: `npm run dev`
- Make sure test agent is running at that URL

---

### File 2: Database Schema
**Location:** `database/migrations/`

Ensure your `test_cases` table has these columns:

```php
Schema::create('test_cases', function (Blueprint $table) {
    $table->id();
    $table->foreignId('checklist_id')->constrained()->onDelete('cascade');
    $table->string('title');
    $table->text('description');
    $table->enum('status', ['pending', 'passed', 'failed'])->default('pending');
    $table->timestamp('last_run_at')->nullable();
    $table->integer('run_count')->default(0);
    $table->timestamps();
});
```

If these columns don't exist, create a migration:
```bash
php artisan make:migration add_test_execution_columns_to_test_cases --table=test_cases
```

Migration file:
```php
public function up()
{
    Schema::table('test_cases', function (Blueprint $table) {
        $table->enum('status', ['pending', 'passed', 'failed'])->default('pending')->after('description');
        $table->timestamp('last_run_at')->nullable()->after('status');
        $table->integer('run_count')->default(0)->after('last_run_at');
    });
}

public function down()
{
    Schema::table('test_cases', function (Blueprint $table) {
        $table->dropColumn(['status', 'last_run_at', 'run_count']);
    });
}
```

---

### File 3: Laravel Routes
**Location:** `routes/api.php`

Add these routes:

```php
use App\Http\Controllers\TestResultController;

// Update test results after tests complete
Route::post('/update-test-results', [TestResultController::class, 'updateResults'])
    ->middleware('auth') // Add auth if needed
    ->name('api.update-test-results');

// Optional: Health check
Route::get('/test-agent-health', [TestResultController::class, 'checkHealth'])
    ->name('api.test-agent-health');
```

---

### File 4: Laravel Controller
**Location:** `app/Http/Controllers/TestResultController.php`

```php
<?php

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
                }
            }

            \Log::info("Test results updated for checklist {$checklistId}");

            return response()->json([
                'success' => true,
                'message' => 'Test results updated successfully',
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
                'error' => 'Failed to update test results',
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

### File 5: Blade Template
**Location:** `resources/views/checklists/show.blade.php`

Key sections to add/update:

```blade
@section('content')
<div class="container py-4">
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
    <table class="table mt-4">
        <thead>
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
                <td>{{ $test->title }}</td>
                <td>
                    @if($test->status === 'passed')
                        <span class="badge bg-success">✅ Passed</span>
                    @elseif($test->status === 'failed')
                        <span class="badge bg-danger">❌ Failed</span>
                    @else
                        <span class="badge bg-secondary">⏳ Pending</span>
                    @endif
                </td>
                <td>{{ $test->last_run_at?->diffForHumans() ?? 'Never' }}</td>
                <td>
                    <a href="#" class="btn btn-sm btn-outline-primary">Edit</a>
                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- Include Vue App --}}
@vite(['resources/js/app.js'])

{{-- Event Handlers --}}
<script>
window.handleTestsComplete = function(results) {
    console.log('✅ Tests completed!', results);
    
    // Send to server
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
        console.log('✅ Results saved to database');
        location.reload();
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

### File 6: Controller for Rendering Template
**Location:** `app/Http/Controllers/ChecklistController.php`

Update the `show` method:

```php
public function show(Checklist $checklist)
{
    // Load test cases for the checklist
    $testCases = $checklist->testCases()->get();

    return view('checklists.show', [
        'checklist' => $checklist,
        'testCases' => $testCases,
    ]);
}
```

---

## 🧪 TESTING THE INTEGRATION

### Step 1: Verify Test Agent is Running
```bash
curl http://localhost:8000/health
```

Should return:
```json
{
  "status": "healthy",
  "timestamp": "2026-04-12T21:30:00.000000"
}
```

### Step 2: Start Application
```bash
npm run dev      # Start Vite dev server
php artisan serve  # Start Laravel (if not using Valet/Homestead)
```

### Step 3: Navigate to Checklist
1. Go to your checklist detail page
2. Look for "🚀 Run Tests" button
3. Make sure test cases are listed in the table

### Step 4: Run Tests
1. Click "🚀 Run Tests" button
2. Watch progress bar (should show 0-100%)
3. Wait for tests to complete (2-10 minutes depending on tests)
4. See results: ✅ Passed: X, ❌ Failed: Y
5. Check console (F12) for debug logs

### Step 5: Verify Database Update
```bash
# Query the database to confirm results were saved
php artisan tinker

>>> \App\Models\TestCase::where('checklist_id', 1)->get();
```

Should show updated `status` and `last_run_at` columns.

---

## 🔍 DEBUGGING GUIDE

### Problem: "Can't connect to test agent"

**Solution:**
1. Verify test agent is running:
   ```bash
   docker-compose up
   ```

2. Check URL is correct in `.env`:
   ```env
   VITE_TEST_AGENT_URL=http://localhost:8000
   ```

3. Test manually:
   ```bash
   curl http://localhost:8000/health
   ```

4. Check test agent logs:
   ```bash
   docker-compose logs
   ```

---

### Problem: Progress bar doesn't appear

**Solution:**
1. Check browser console (F12 → Console tab)
2. Look for errors in console
3. Verify testAgentService.js is imported correctly
4. Check that Vue component is registered in app.js

---

### Problem: Results don't save to database

**Solution:**
1. Check browser Network tab (F12 → Network)
2. Look for POST to `/api/update-test-results`
3. Check response - should be 200 with success: true
4. Verify route is registered in `routes/api.php`
5. Check Laravel logs: `tail -f storage/logs/laravel.log`

---

### Problem: "Job not found" error

**Solution:**
1. Test agent server may have restarted
2. Job ID might have expired (older than test agent retention)
3. Try submitting tests again

---

### Problem: Tests timeout at 10 minutes

**Solution:**
1. This is by design - max execution time is 600 seconds
2. Try with fewer or simpler test cases
3. Check test agent logs for slow tests
4. Increase timeout in `testAgentService.js`:
   ```javascript
   const DEFAULT_MAX_WAIT = 900000; // 15 minutes
   ```

---

## 📊 MONITORING & LOGGING

### Console Output
Open browser DevTools (F12) and watch console:

```
📤 Submitting 3 test case(s)...
✅ Job ID: 550e8400-e29b-41d4-a716-446655440000
⏳ Polling job status...
📊 Poll #1: RUNNING (30%) - 4.2s elapsed
📊 Poll #2: RUNNING (40%) - 6.4s elapsed
✅ Tests completed!
📈 Results: {passed: 5, failed: 0}
```

### Laravel Logs
```bash
tail -f storage/logs/laravel.log
```

Look for entries like:
```
[2026-04-12 21:30:00] local.INFO: Test results updated for checklist 1 [...]
```

### Database
```bash
php artisan tinker

>>> \App\Models\TestCase::where('checklist_id', 1)->get(['id', 'status', 'last_run_at', 'run_count']);
```

---

## 🚢 PRODUCTION DEPLOYMENT

### Before Deploying:

1. **Update .env for production:**
   ```env
   VITE_TEST_AGENT_URL=https://test-agent.yourdomain.com
   VITE_APP_URL=https://yourdomain.com
   ```

2. **Ensure test agent is deployed:**
   - Deploy test agent to production server or cloud
   - Make sure it's accessible at the URL above
   - Set up health monitoring

3. **Build production assets:**
   ```bash
   npm run build
   ```

4. **Verify SSL/TLS:**
   - Use HTTPS for API calls
   - Configure proper CORS headers

5. **Set up rate limiting:**
   - Limit requests to API to prevent abuse
   - Add per-user rate limits if needed

6. **Enable error tracking:**
   - Set up Sentry, Rollbar, or similar
   - Monitor test agent health continuously

7. **Configure logging:**
   - Enable proper logging in production
   - Set up log rotation
   - Monitor for errors in logs

---

## 📚 API DOCUMENTATION

### Test Agent Endpoints

#### Health Check
```
GET /health
Response: { "status": "healthy", "timestamp": "..." }
```

#### Submit Tests
```
POST /run-tests
Body: {
  "checklist_id": "123",
  "app_url": "http://localhost:8000",
  "test_cases": [
    {"title": "Test 1", "description": "..."}
  ]
}
Response: { "job_id": "uuid", "status": "accepted", "message": "..." }
```

#### Check Job Status
```
GET /jobs/{job_id}
Response: {
  "job_id": "uuid",
  "status": "running|completed|failed",
  "results": {...},
  "error": null
}
```

Full docs at: `http://localhost:8000/docs`

---

## 🎓 COMPONENT USAGE

### Basic Usage
```blade
<run-tests-button 
  :test-cases="@json($testCases)"
  :checklist-id="{{ $checklist->id }}"
  @tests-complete="handleTestsComplete"
  @tests-failed="handleTestsFailed"
/>
```

### Props
- `testCases` (Array) - Test case objects: `{id, title, description}`
- `checklistId` (String|Number) - ID from database

### Events
- `tests-complete` - Emitted with results: `{passed, failed, tests, timestamp}`
- `tests-failed` - Emitted with error message string

---

## ✅ CHECKLIST

- [ ] Added .env variables
- [ ] Created/updated database migration
- [ ] Created TestResultController
- [ ] Added routes to `routes/api.php`
- [ ] Updated Blade template
- [ ] Updated ChecklistController show method
- [ ] Started test agent (`docker-compose up`)
- [ ] Running dev server (`npm run dev`)
- [ ] Test button appears on checklist page
- [ ] Can click and run tests
- [ ] See progress bar
- [ ] Results display correctly
- [ ] Database updates with new statuses
- [ ] No errors in browser console

---

## 📞 TROUBLESHOOTING CHECKLIST

If something doesn't work:

1. **Check browser console** - F12 → Console tab
2. **Verify test agent running** - curl http://localhost:8000/health
3. **Verify .env updated** - npm run dev (restart after changes)
4. **Check Network tab** - F12 → Network, look for API calls
5. **Check Laravel logs** - tail -f storage/logs/laravel.log
6. **Verify component registered** - Search for "RunTestsButton" in app.js
7. **Database has columns** - Check test_cases table for status, last_run_at, run_count

---

## 📖 ADDITIONAL RESOURCES

- **Vue 3 Docs:** https://vuejs.org/
- **Laravel Docs:** https://laravel.com/docs
- **Vite Docs:** https://vitejs.dev/
- **FastAPI Docs:** https://fastapi.tiangolo.com/
- **Bootstrap 5 Docs:** https://getbootstrap.com/docs/5.0/

---

## 🎉 Success Indicators

When everything is working properly, you should see:

1. ✅ "🚀 Run Tests" button on checklist page
2. ✅ Button shows "⏳ Running... X%" when clicked
3. ✅ Progress bar animates from 0% to 100%
4. ✅ Results display: "✅ Passed: 5" and "❌ Failed: 0"
5. ✅ Timestamp shows when tests completed
6. ✅ Database updates - test statuses change from "pending" to "passed/failed"
7. ✅ Browser console shows helpful debug logs
8. ✅ No errors in console (F12)
9. ✅ Test cases table badges update after refresh

---

**That's it! You now have a fully functional AI Test Agent integration! 🚀**
