# AI Test Agent Integration - Quick Reference

## 🎯 5-Minute Start

```bash
# 1. Update .env
cat >> .env << 'EOF'
VITE_TEST_AGENT_URL=http://localhost:8000
VITE_APP_URL=http://localhost:8000
EOF

# 2. Start test agent (separate terminal)
docker-compose up

# 3. Start dev server
npm run dev

# 4. Then use component in Blade template
<run-tests-button 
  :test-cases="@json($testCases)"
  :checklist-id="{{ $checklist->id }}"
  @tests-complete="handleTestsComplete"
/>
```

---

## 📁 Files Created

| File | Location | Purpose |
|------|----------|---------|
| `testAgentService.js` | `resources/js/services/` | API communication |
| `RunTestsButton.vue` | `resources/js/components/` | UI component |
| `app.js` | `resources/js/` | Vue initialization |
| `TestResultController.php` | `app/Http/Controllers/` | Save results |
| Migration | `database/migrations/` | Schema update |

---

## 🔧 Environment Variables

| Variable | Value | Example |
|----------|-------|---------|
| `VITE_TEST_AGENT_URL` | API base URL | `http://localhost:8000` |
| `VITE_APP_URL` | App URL for testing | `http://localhost:8000` |

**⚠️ NO QUOTES in .env file**

---

## 📝 Database Schema

```sql
ALTER TABLE test_cases ADD COLUMN status ENUM('pending', 'passed', 'failed') DEFAULT 'pending';
ALTER TABLE test_cases ADD COLUMN last_run_at TIMESTAMP NULL;
ALTER TABLE test_cases ADD COLUMN run_count INT DEFAULT 0;
```

---

## 🎁 Component API

### Props
```javascript
props: {
  testCases: Array,        // Required: [{id, title, description}]
  checklistId: String      // Required: "123"
}
```

### Events
```javascript
@tests-complete="(results) => { /* {passed, failed, tests, timestamp} */ }"
@tests-failed="(error) => { /* error message string */ }"
```

### Usage
```blade
<run-tests-button 
  :test-cases="@json($testCases)"
  :checklist-id="{{ $checklist->id }}"
  @tests-complete="handleTestsComplete"
  @tests-failed="handleTestsFailed"
/>
```

---

## 🚀 Service Methods

### Submit Tests
```javascript
import testAgentService from './services/testAgentService';

const response = await testAgentService.submitTests(testCases, checklistId);
// Returns: { job_id: 'uuid', status: 'accepted' }
```

### Check Status
```javascript
const status = await testAgentService.getJobStatus(jobId);
// Returns: { job_id, status, results, error }
```

### Wait for Completion
```javascript
const completed = await testAgentService.waitForCompletion(jobId);
// Returns: { job_id, status: 'completed', results: {...} }
```

### Full Workflow
```javascript
const result = await testAgentService.runTestsWorkflow(
  testCases, 
  checklistId, 
  (progress) => console.log(progress)  // Optional progress callback
);
```

---

## 🔌 API Endpoints

### Test Agent API

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/health` | Health check |
| POST | `/run-tests` | Submit tests |
| GET | `/jobs/{id}` | Get job status |

### Laravel API

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/api/update-test-results` | Save results |
| GET | `/api/test-agent-health` | Check agent health |

---

## 🐛 Debug Commands

```bash
# Health check
curl http://localhost:8000/health

# Check database
php artisan tinker
>>> \App\Models\TestCase::where('checklist_id', 1)->get();

# View logs
tail -f storage/logs/laravel.log

# Check routes
php artisan route:list | grep test

# Rebuild assets
npm run build
```

---

## 🧪 Test Scenarios

### Scenario 1: Happy Path
```bash
# Test with 2-3 simple cases
# Expected: All pass ✅
# Duration: 30-60 seconds
```

### Scenario 2: Mixed Results
```bash
# Include some failing tests
# Expected: Some pass, some fail, mixed results ✅ Passed: 2, ❌ Failed: 1
# Duration: 1-2 minutes
```

### Scenario 3: Network Error
```bash
# Stop test agent: docker-compose down
# Try to run tests
# Expected: Error message "Failed to connect to test agent"
# Restart: docker-compose up
```

### Scenario 4: Timeout
```bash
# Very complex tests (20+)
# Expected: Tests timeout after 10 minutes
# Action: Increase DEFAULT_MAX_WAIT or simplify tests
```

---

## 📊 Result Format

```javascript
{
  passed: 5,           // Number passed
  failed: 1,           // Number failed
  successRate: "83%",  // Percentage
  timestamp: "ISO string",
  tests: [
    {
      name: "Test 1",
      status: "passed" | "failed",
      error: "Optional error message"
    }
  ]
}
```

---

## 🎨 CSS Classes

| Class | Purpose |
|-------|---------|
| `.btn-primary` | Run Tests button |
| `.progress-bar` | Progress bar |
| `.alert-success` | Success notification |
| `.alert-danger` | Error notification |
| `.alert-warning` | Mixed results |
| `.badge-success` | Passed badge |
| `.badge-danger` | Failed badge |

---

## 📱 Responsive Design

Component works on:
- ✅ Desktop (1200px+)
- ✅ Tablet (768px - 1199px)
- ✅ Mobile (< 768px)

---

## 🔒 Security Notes

- ✅ CSRF token required for database update
- ✅ Input validation on backend
- ✅ Use HTTPS in production
- ⚠️ Rate limit API calls
- ⚠️ Authenticate users if needed
- ⚠️ Validate checklist_id ownership

---

## ⚡ Performance Tips

- Limit test cases to < 20 per run
- Use shorter test descriptions
- Monitor test agent CPU usage
- Set reasonable timeout (10 minutes default)
- Cache test case data if possible
- Use pagination for many tests

---

## 🚨 Common Errors Quick Fix

```
❌ "Failed to connect to test agent"
→ Check test agent is running: docker-compose up

❌ "Job not found"
→ Test agent might have restarted, try again

❌ "Timeout: Tests took longer than 10 minutes"
→ Simplify tests or increase timeout in service

❌ "Unexpected response format"
→ Check test agent version matches API docs

❌ "Validation failed" (database update)
→ Check CSRF token in request headers
```

---

## 📚 Console Logs

Watch for these in browser console (F12):

```
📤 Submitting X test case(s)...     // Indicates start
✅ Job ID: uuid                      // Indicates successful submission
⏳ Polling job status...             // Indicates polling started
📊 Poll #N: RUNNING (X%)            // Progress status
✅ Tests completed!                 // Success
❌ Error: message                    // Failure
```

---

## 🎯 Test Case Format

```javascript
{
  id: 1,                                              // Database ID
  title: "User can create checklist",                 // Short name
  description: "Verify user can create new checklist" // What to test
}
```

---

## 📞 Quick Support

| Issue | Solution |
|-------|----------|
| Button missing | Restart dev server + check app.js registration |
| Progress bar stuck | Check console for errors, restart test agent |
| No database update | Verify route exists, check CSRF token |
| Timeout errors | Simplify tests, increase timeout value |
| Connection refused | Start test agent, check URL in .env |

---

## ✅ Verification Checklist

After setup, verify:

- [ ] `npm run dev` shows no errors
- [ ] `http://localhost:8000/health` returns `{"status": "healthy"}`
- [ ] Component appears on page
- [ ] Button is enabled (if test cases exist)
- [ ] Console shows debug logs when clicked
- [ ] Progress bar animates 0-100%
- [ ] Results display with pass/fail counts
- [ ] Database test_cases table updates

---

## 🎓 Learning Resources

- Vue 3: https://vuejs.org/
- Laravel: https://laravel.com/docs
- FastAPI: https://fastapi.tiangolo.com/
- Vite: https://vitejs.dev/

---

**Last Updated:** April 12, 2026  
**Version:** 1.0.0  
**License:** MIT
