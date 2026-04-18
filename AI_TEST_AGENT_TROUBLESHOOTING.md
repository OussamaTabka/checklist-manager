# AI Test Agent Integration - Troubleshooting Guide

## 🔧 Common Issues & Solutions

---

## 1. "Failed to connect to test agent" Error

### Symptoms
- Red error alert when clicking "Run Tests"
- Console shows: `❌ Error: Failed to connect to test agent`
- Network tab shows connection refused

### Root Causes & Solutions

**Cause A: Test Agent is Not Running**
```bash
# Check if container is running
docker ps | grep test-agent

# Start test agent
cd ../ai-checklist-agent  # Navigate to test agent directory
docker-compose up

# Verify it's accessible
curl http://localhost:8000/health
```

**Cause B: Wrong URL in .env**
```bash
# Check your .env file
cat .env | grep VITE_TEST_AGENT_URL

# Should output something like:
# VITE_TEST_AGENT_URL=http://localhost:8000

# If incorrect, update it and restart dev server:
npm run dev
```

**Cause C: Test Agent is on Different Machine**
```bash
# If test agent is on another machine:
VITE_TEST_AGENT_URL=http://192.168.1.100:8000
# Replace IP with actual machine IP
```

**Cause D: Firewall Blocking Port 8000**
```bash
# On Windows, check firewall
netsh advfirewall firewall show rule name=all | findstr 8000

# On Mac/Linux
lsof -i :8000
```

---

## 2. Component Doesn't Appear on Page

### Symptoms
- Blade template has `<run-tests-button>` but button doesn't show
- No Vue errors in console
- HTML shows blank div

### Root Causes & Solutions

**Cause A: Vue App Not Mounted**
```blade
{{-- Check your Blade template for @vite directive --}}
@vite(['resources/js/app.js'])

{{-- And div with correct id --}}
<div id="app">
  <run-tests-button ... />
</div>

{{-- Both are REQUIRED! --}}
```

**Cause B: Component Not Registered in app.js**
```javascript
// resources/js/app.js should have:
import RunTestsButton from './components/RunTestsButton.vue';
app.component('RunTestsButton', RunTestsButton);

// Verify by checking app.js file:
cat resources/js/app.js | grep -i "RunTestsButton"
```

**Cause C: Dev Server Not Running**
```bash
# Check if Vite dev server is running
npm run dev

# You should see output like:
# ➜  Local:   http://localhost:5173/
# ➜  Press q to quit
```

**Cause D: Build Files Not Generated**
```bash
# Build Vue components
npm run build

# Or for development:
npm run dev
```

---

## 3. Progress Bar Doesn't Show

### Symptoms
- Button text changes to "Running..." but no progress bar appears
- Console shows: `⏳ Running... 0%` repeatedly but no HTML update

### Root Causes & Solutions

**Cause A: CSS Not Loaded**
```bash
# Verify Bootstrap CSS is included in your layout
# Check app.blade.php or layout.blade.php

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.x.x/dist/css/bootstrap.min.css">

# Or if using npm:
npm install bootstrap
# Then import in your main CSS file
```

**Cause B: Vite Not Recompiling**
```bash
# Stop dev server and restart
npm run dev

# Or clear cache
rm -rf node_modules/.vite
npm run dev
```

**Cause C: Component Script Not Running**
```javascript
// Check browser console for errors:
// F12 → Console tab

// Look for Vue-related errors like:
// [Vue warn]: ... template or render function not defined

// If found, rebuild:
npm run dev
```

---

## 4. "No test cases available" Message

### Symptoms
- Button is disabled (greyed out)
- Shows message: "No test cases available"

### Root Causes & Solutions

**Cause A: Test Cases Not Passed to Component**
```blade
{{-- Verify in Blade template --}}
<run-tests-button 
  :test-cases="@json($testCases)"  {{-- This is required --}}
  :checklist-id="{{ $checklist->id }}"
/>

{{-- And in your controller: --}}
public function show(Checklist $checklist) {
    $testCases = $checklist->testCases()->get();  // Must load test cases
    return view('checklists.show', compact('checklist', 'testCases'));
}
```

**Cause B: No Test Cases in Database**
```bash
# Check database
php artisan tinker

>>> \App\Models\TestCase::where('checklist_id', 1)->count();

# If 0, add test cases via UI or database:
>>> \App\Models\TestCase::create(['checklist_id' => 1, 'title' => 'Test 1', 'description' => 'Test']);
```

**Cause C: Test Cases Not Loading Properly**
```bash
# Debug in controller
public function show(Checklist $checklist) {
    $testCases = $checklist->testCases()->get();
    \Log::debug('Test cases:', $testCases->toArray());  // Check logs
    return view('checklists.show', compact('checklist', 'testCases'));
}

# Check logs:
tail -f storage/logs/laravel.log
```

---

## 5. Tests Submit But Never Complete (Infinite Loop)

### Symptoms
- Progress bar stuck at 40-50%
- Console shows: `📊 Poll #1, #2, #3...` indefinitely
- Test agent running (health check works)

### Root Causes & Solutions

**Cause A: Job ID is Invalid**
```bash
# Check test agent health
curl http://localhost:8000/health

# Manually test job endpoint:
curl http://localhost:8000/jobs/550e8400-e29b-41d4-a716-446655440000

# If returns 404, job doesn't exist
```

**Cause B: Test Agent Processing Too Slow**
```bash
# Check test agent logs
docker-compose logs test-agent

# Look for stuck processes:
docker exec test-agent ps aux

# If tests are very complex, increase timeout in service:
// resources/js/services/testAgentService.js
const DEFAULT_MAX_WAIT = 900000; // 15 minutes (increased from 10)
```

**Cause C: Network Issue Between Frontend and Test Agent**
```bash
# Add logging to service to see actual responses:
// In testAgentService.js, add detailed logging:
console.log('Full response:', JSON.stringify(jobStatus, null, 2));

# Then check browser console for actual status values
```

---

## 6. Results Show But Database Doesn't Update

### Symptoms
- "✅ Tests completed!" message appears
- Results display correctly (passed: 5, failed: 0)
- But test statuses in database remain "pending"
- Page needs refresh to see changes

### Root Causes & Solutions

**Cause A: Route Not Defined**
```bash
# Check routes are registered:
php artisan route:list | grep update-test-results

# Should show:
# POST   /api/update-test-results

# If not found, add to routes/api.php:
Route::post('/update-test-results', [TestResultController::class, 'updateResults']);
```

**Cause B: Controller Not Found**
```bash
# Check controller exists:
ls app/Http/Controllers/TestResultController.php

# If missing, create with:
php artisan make:controller TestResultController
```

**Cause C: CSRF Token Missing**
```javascript
// In Blade template's event handler, must include CSRF token:
fetch('/api/update-test-results', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
        {{-- This line is CRITICAL! --}}
    },
    body: JSON.stringify({...})
})
```

**Cause D: Route Expects Different Format**
```bash
# Test manually with curl:
curl -X POST http://localhost:8000/api/update-test-results \
  -H "Content-Type: application/json" \
  -d '{
    "checklist_id": 1,
    "results": {
      "passed": 5,
      "failed": 0,
      "tests": [{"id": 1, "status": "passed", "title": "Test 1"}]
    }
  }'

# Check response - should be 200 with {"success": true}
```

---

## 7. "500 Server Error" When Updating Results

### Symptoms
- Results display correctly
- But POST to update database returns 500 error
- Database shows no updates

### Root Causes & Solutions

**Cause A: Validation Failed**
```bash
# Check Laravel logs:
tail -f storage/logs/laravel.log

# Look for validation error like:
# [2026-04-12 21:30:00] local.ERROR: ValidationException: ...

# Common validation fails:
- checklist_id doesn't exist in database
- results.tests has invalid structure
- Missing required fields in request body
```

**Cause B: Checklist Doesn't Exist**
```bash
# Verify checklist exists:
php artisan tinker
>>> \App\Models\Checklist::find(1);

# If null, checklist was deleted or ID is wrong
```

**Cause C: Model Relationship Missing**
```php
// Verify TestCase model has correct relationship:
// app/Models/TestCase.php
class TestCase extends Model {
    public function checklist() {
        return $this->belongsTo(Checklist::class);
    }
}

// And Checklist model:
class Checklist extends Model {
    public function testCases() {
        return $this->hasMany(TestCase::class);
    }
}
```

**Cause D: Column Doesn't Exist**
```bash
# Check if status column exists:
php artisan tinker
>>> \Illuminate\Support\Facades\Schema::hasColumn('test_cases', 'status');

# If false, run migration:
php artisan migrate

# Or add manually:
php artisan make:migration add_status_column_to_test_cases --table=test_cases
```

---

## 8. "Test Agent responded with unexpected format" Error

### Symptoms
- Tests seem to complete but error appears
- Console shows: `❌ Error: Unexpected response format from test agent`

### Root Causes & Solutions

**Cause A: Test Agent Version Mismatch**
```bash
# Check test agent API docs:
curl http://localhost:8000/docs

# Compare response format with what service expects
# Update testAgentService.js if API changed
```

**Cause B: Empty Results**
```bash
# Modify service to handle missing data:
// resources/js/services/testAgentService.js
if (!data.job_id) {
    console.error('Response:', JSON.stringify(data, null, 2));
    throw new Error('Invalid response: missing job_id');
}

# Check console output to see actual response
```

**Cause C: Encoding Issue**
```bash
# Test with proper headers:
curl -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  http://localhost:8000/health

# If you see garbled output, encoding is wrong
```

---

## 9. "Timeout: Tests took too long" Error

### Symptoms
- Progress bar reaches 100% but doesn't complete
- Error message after ~10 minutes
- Tests actually completed on test agent side

### Root Causes & Solutions

**Cause A: Tests Are Genuinely Slow**
```javascript
// Increase timeout in testAgentService.js:
const DEFAULT_MAX_WAIT = 900000; // 15 minutes instead of 10

// Or pass custom timeout from component:
await testAgentService.waitForCompletion(jobId, 900000);
```

**Cause B: Test Agent Has Internal Issue**
```bash
# Check test agent logs:
docker-compose logs test-agent

# Look for errors like:
# - Memory issues
# - Stuck processes
# - Browser window hangs

# Restart test agent:
docker-compose restart
```

**Cause C: Polling Not Working**
```javascript
// Add more detailed logging:
console.log('Full response:', JSON.stringify(jobStatus, null, 2));

// Check if status values are correct:
// Should be: "running", "completed", "failed"
```

---

## 10. Browser Console Shows No Logs

### Symptoms
- No console output at all
- Can't debug what's happening
- Component seems silent

### Root Causes & Solutions

**Cause A: Console Logs Removed in Production Build**
```javascript
// In development, logs should work
// Check if you're in production mode
console.log('Test');  // Should appear

// If using minified build, rebuild:
npm run dev  // Development with logs
```

**Cause B: Browser Console Not Visible**
```bash
# Open browser DevTools:
# Windows/Linux: F12
# Mac: Cmd + Option + I

# Or right-click → Inspect → Console tab

# Verify Vue component console output is visible
```

**Cause C: Error Event Listeners Missing**
```javascript
// Add global error handler:
window.addEventListener('error', function(event) {
    console.error('Global error:', event.message);
});

// Check if errors are being caught:
try {
    // ... code ...
} catch (error) {
    console.error('Caught error:', error);  // Must show
}
```

---

## 📋 Quick Diagnostic Checklist

Run through this when things aren't working:

### Environment
- [ ] Test agent running: `docker-compose ps`
- [ ] Dev server running: `npm run dev` (shows http://localhost:5173)
- [ ] .env has correct URL: `grep VITE_TEST_AGENT_URL .env`
- [ ] No errors after restarting dev server

### Vue Component
- [ ] Component file exists: `ls resources/js/components/RunTestsButton.vue`
- [ ] Service file exists: `ls resources/js/services/testAgentService.js`
- [ ] app.js imports component: `grep RunTestsButton resources/js/app.js`
- [ ] Button appears on page (no CSS/layout issues)

### API & Test Agent
- [ ] Health check works: `curl http://localhost:8000/health`
- [ ] Can submit tests: `curl -X POST http://localhost:8000/run-tests ...`
- [ ] GET job status works: `curl http://localhost:8000/jobs/uuid`

### Database & Backend
- [ ] TestCase model exists: `ls app/Models/TestCase.php`
- [ ] test_cases table has columns: `php artisan tinker` → `Schema::getColumnListing('test_cases')`
- [ ] Route exists: `php artisan route:list | grep update-test-results`
- [ ] Controller exists: `ls app/Http/Controllers/TestResultController.php`

### Blade Template
- [ ] Template has `<div id="app">`
- [ ] Template has `@vite(['resources/js/app.js'])`
- [ ] Component receives @json($testCases)
- [ ] Component receives checklist ID

### Network & Logs
- [ ] F12 → Network tab shows API calls
- [ ] F12 → Console tab shows debug logs
- [ ] Laravel logs show activity: `tail -f storage/logs/laravel.log`

---

## 🆘 When All Else Fails

### Nuclear Option: Total Reset
```bash
# 1. Stop everything
npm run dev  # Ctrl+C to stop
docker-compose down  # Stop containers

# 2. Clear caches
rm -rf node_modules/.vite
rm -rf storage/app/*
php artisan cache:clear

# 3. Restart everything
npm run dev  # In one terminal
docker-compose up  # In another (test agent directory)

# 4. Try again
# Navigate to your checklist -> click Run Tests
```

### Enable Debug Mode
```php
// In config/app.php or .env
APP_DEBUG=true
LOG_LEVEL=debug

// Then watch logs:
tail -f storage/logs/laravel.log
```

### Test Agent Logs
```bash
# See what test agent is doing
docker-compose logs -f test-agent

# Or if attached:
docker exec -it test-agent bash
# Then inside: tail -f /app/logs/app.log
```

---

## 📞 Getting Help

**Before asking for help, collect:**
1. Full error message (screenshot or copy exact text)
2. Browser console logs (F12 → Console, select all and copy)
3. Laravel logs (tail storage/logs/laravel.log)
4. Test agent logs (docker-compose logs test-agent)
5. Your .env settings (hide sensitive data)
6. What you've already tried

**Debug like a pro:**
1. Check browser console first (F12)
2. Check Laravel logs
3. Check test agent logs
4. Test each component independently
5. Use curl to test API directly
6. Use php artisan tinker to query database

---

**Remember: Most issues are either:**
- Test agent not running
- Environment variables wrong  
- Component not registered
- CSRF token missing
- Database column doesn't exist

Good luck! 🚀
