# Run Tests Button - Setup Guide

## Quick Start

The Run Tests button has been added to your checklist-manager project! Here's how to use it.

## Files Added

### Frontend (Vue.js)
- `frontend/src/lib/testAgentService.js` - Service for communicating with the test agent API
- `frontend/src/components/RunTestsButton.vue` - Vue component with button, progress bar, and results display
- `frontend/.env.local` - Environment variables for test agent configuration

### Backend (Laravel)
- `backend/app/Http/Controllers/TestResultController.php` - Saves test results to database
- `backend/database/migrations/2024_04_12_add_test_columns_to_checklist_items.php` - Adds test tracking columns
- `backend/routes/api.php` - API endpoints (updated)
- `backend/app/Models/ChecklistItem.php` - Model updated with test fields

### Blade Template (Optional)
- `backend/resources/views/checklists/show.blade.php` - Example Blade template showing component integration

## Installation Steps

### 1. Install Dependencies
```bash
cd frontend
npm install
```

### 2. Run Database Migration
```bash
cd backend
php artisan migrate
```

This adds these columns to the `checklist_items` table:
- `status` - Test execution status (pending, passed, failed)
- `last_run_at` - Timestamp of last test run
- `run_count` - Number of times tested

### 3. Configure Environment Variables

**Frontend** (`frontend/.env.local`):
```env
VITE_TEST_AGENT_URL=http://localhost:8000
VITE_APP_URL=http://localhost:5173
```

Update these values for your environment:
- `VITE_TEST_AGENT_URL` - Where your Python FastAPI test agent is running
- `VITE_APP_URL` - Your frontend application URL (needed for browser tests)

**Backend** - No additional config needed, but ensure Laravel can reach the test agent

## Usage in Vue Components

### Using RunTestsButton in ChecklistsView

Add the component to any view where you want to show the Run Tests button:

```vue
<script setup>
import RunTestsButton from '@/components/RunTestsButton.vue'
// ... your existing imports

// Your checklist and items data
const checklists = ref([])
const selectedChecklistId = ref(null)
const testCases = ref([])
</script>

<template>
  <div>
    <!-- Your existing checklist selection/management code -->
    
    <!-- Add the Run Tests Button -->
    <div v-if="selectedChecklistId && testCases.length > 0">
      <RunTestsButton 
        :test-cases="testCases"
        :checklist-id="selectedChecklistId"
        @tests-complete="handleTestsComplete"
        @tests-failed="handleTestsFailed"
      />
    </div>
  </div>
</template>

<script setup>
// ... other setup code

// Handle test completion event
function handleTestsComplete(results) {
  console.log('Tests completed!', results)
  // results.passed, results.failed, results.total, results.tests
  
  // Optionally refresh your checklist data
  // fetchChecklistItems(selectedChecklistId.value)
}

// Handle test failure event
function handleTestsFailed(errorMessage) {
  console.error('Test failed:', errorMessage)
}
</script>
```

### Component Props

| Prop | Type | Required | Description |
|------|------|----------|-------------|
| `testCases` | Array | Yes | Array of test case objects with `title` and `description` |
| `checklistId` | String or Number | Yes | ID of the checklist being tested |

**Example test cases array:**
```javascript
[
  { title: 'Login test', description: 'User can login with valid credentials' },
  { title: 'Dashboard loads', description: 'Dashboard displays after login' },
  { title: 'Create checklist', description: 'User can create a new checklist' }
]
```

### Component Events

| Event | Payload | Description |
|-------|---------|-------------|
| `tests-complete` | `{ passed, failed, total, tests, timestamp }` | Emitted when tests finish successfully |
| `tests-failed` | `error message string` | Emitted when test execution fails |

**Handling events:**
```vue
<RunTestsButton 
  @tests-complete="handleComplete"
  @tests-failed="handleFailed"
/>
```

## Workflow

1. **User clicks "Run Tests" button**
   - Component becomes disabled and shows loading spinner
   - Progress bar appears (0-100%)

2. **Submit tests to external agent**
   - Frontend → POST `/run-tests` on test agent
   - Gets back a `job_id`

3. **Poll for results**
   - Frontend → GET `/jobs/{job_id}` every 2 seconds
   - Progress bar updates
   - Maximum 10 minutes timeout

4. **Save results to Laravel backend**
   - Frontend → POST `/api/update-test-results`
   - Backend updates `checklist_items` table with status
   - `status` field set to 'passed' or 'failed'
   - `last_run_at` updated to current time
   - `run_count` incremented

5. **Display results**
   - Alert shows: "✅ Tests Completed! 5 passed, 2 failed"
   - Or error: "❌ Error: Connection refused"

## API Endpoints

### Frontend → Test Agent

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/run-tests` | POST | Submit test cases for execution |
| `/jobs/{id}` | GET | Get job status and results |
| `/health` | GET | Check if test agent is running |

**Request example:**
```json
POST /run-tests
{
  "checklist_id": "1",
  "app_url": "http://localhost:5173",
  "test_cases": [
    { "title": "Test 1", "description": "Description" }
  ]
}
```

**Response example:**
```json
{
  "job_id": "abc-def-123",
  "status": "pending"
}
```

### Frontend → Laravel Backend

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/update-test-results` | POST | Save test results to database |
| `/api/test-agent-health` | GET | Check if test agent is accessible |

**Request example:**
```json
POST /api/update-test-results
{
  "checklist_id": 1,
  "results": {
    "passed": 5,
    "failed": 2,
    "total": 7,
    "tests": [
      { "title": "Test 1", "status": "passed" },
      { "title": "Test 2", "status": "failed", "error": "..." }
    ]
  }
}
```

## Troubleshooting

### Error: "Test Agent is not responding"
- Make sure your Python FastAPI test agent is running
- Default URL: `http://localhost:8000`
- Check `VITE_TEST_AGENT_URL` in `.env.local`

### Error: "No test cases available"
- Ensure test cases array has at least one element
- Each test case should have `title` and optionally `description`

### Error: "Failed to save results to backend"
- Check that Laravel backend is running
- Verify routes are registered: `php artisan route:list | grep test`
- Check Laravel logs: `tail -f storage/logs/laravel.log`

### Progress bar stuck at certain percentage
- Test agent is taking longer than expected
- Maximum wait time is 10 minutes
- Check test agent logs for errors

## Testing Locally

1. **Start the test agent** (Python FastAPI):
```bash
python -m pytest tests/ --with-agent
# or if you have a test agent running separately
cd test-agent && python main.py
```

2. **Start the Laravel backend**:
```bash
cd backend
php artisan serve
```

3. **Start the Vue frontend**:
```bash
cd frontend
npm run dev
```

4. **Use the button**:
- Navigate to a checklist with test items
- Click "Run Tests" button
- Watch the progress bar
- See results appear

## Production Deployment

For production:

1. **Update environment variables**:
   - `VITE_TEST_AGENT_URL` → Your production test agent URL
   - `VITE_APP_URL` → Your production frontend URL

2. **Update Laravel routes**:
   - Ensure `/api/update-test-results` is protected by authentication
   - Current: Protected by `role:admin|chef|admin_contenus|testeur`

3. **Test agent deployment**:
   - Deploy your test agent separately (Python)
   - Update `VITE_TEST_AGENT_URL` to point to deployed agent
   - Ensure proper CORS headers on test agent for cross-origin requests

4. **Database**:
   - Run migrations on production: `php artisan migrate`

## Next Steps

- Add the RunTestsButton to your ChecklistsView
- Test locally first before production deployment
- Monitor test results in database
- Add additional UI features as needed

## Support Files

For detailed information:
- See `backend/resources/views/checklists/show.blade.php` for full Blade template example
- TestResultController handles backend logic
- RunTestsButton.vue has full component implementation with error handling

---

Questions? Check the component comments and function JSDoc for detailed explanations!
