/**
 * Test Agent Service
 * Handles all communication with the external AI Test Agent API
 */

const TEST_AGENT_URL = import.meta.env.VITE_TEST_AGENT_URL || 'http://localhost:8000';
const POLL_INTERVAL = 2000; // 2 seconds between polls
const DEFAULT_MAX_WAIT = 600000; // 10 minutes

export async function submitTests(testCases, checklistId) {
  try {
    console.log(`📤 Submitting ${testCases.length} test case(s)...`);

    if (!testCases || testCases.length === 0) {
      throw new Error('Test cases array is empty');
    }

    const requestBody = {
      checklist_id: String(checklistId),
      app_url: import.meta.env.VITE_APP_URL || 'http://localhost:8000',
      test_cases: testCases.map(test => ({
        title: test.title || 'Untitled Test',
        description: test.description || 'No description',
      })),
    };

    console.log('📋 Request payload:', JSON.stringify(requestBody));

    const response = await fetch(`${TEST_AGENT_URL}/run-tests`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify(requestBody),
    });

    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}));
      const errorMessage = errorData.detail || `HTTP ${response.status}`;
      throw new Error(`Failed to submit tests: ${errorMessage}`);
    }

    const data = await response.json();
    console.log(`✅ Job ID: ${data.job_id}`);
    return data;
  } catch (error) {
    console.error('❌ Test submission error:', error.message);
    throw error;
  }
}

export async function getJobStatus(jobId) {
  try {
    const response = await fetch(`${TEST_AGENT_URL}/jobs/${jobId}`, {
      method: 'GET',
      headers: {
        'Accept': 'application/json',
      },
    });

    if (response.status === 404) {
      throw new Error(`Job not found: ${jobId}`);
    }

    if (!response.ok) {
      throw new Error(`Failed to fetch job status: HTTP ${response.status}`);
    }

    const data = await response.json();
    return data;
  } catch (error) {
    console.error('❌ Job status error:', error.message);
    throw error;
  }
}

export async function waitForCompletion(jobId, maxWait = DEFAULT_MAX_WAIT) {
  try {
    console.log(`⏳ Polling job status...`);

    const startTime = Date.now();
    let pollCount = 0;

    while (true) {
      pollCount++;
      const elapsedTime = Date.now() - startTime;

      if (elapsedTime > maxWait) {
        throw new Error(`Tests took longer than 10 minutes`);
      }

      try {
        const jobStatus = await getJobStatus(jobId);
        const progress = Math.min(Math.round((elapsedTime / maxWait) * 100), 95);
        console.log(`📊 Poll #${pollCount}: ${jobStatus.status.toUpperCase()} (${progress}%)`);

        if (jobStatus.status === 'completed') {
          console.log('✅ Tests completed!');
          return jobStatus;
        }

        if (jobStatus.status === 'failed' || jobStatus.error) {
          throw new Error(`Test job failed`);
        }

        await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL));
      } catch (error) {
        if (error.message.includes('Job not found')) {
          throw error;
        }
        console.warn(`⚠️  Poll error (will retry): ${error.message}`);
        await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL));
      }
    }
  } catch (error) {
    console.error('❌ Completion polling error:', error.message);
    throw error;
  }
}

export async function checkHealth() {
  try {
    const response = await fetch(`${TEST_AGENT_URL}/health`, {
      method: 'GET',
      headers: {
        'Accept': 'application/json',
      },
    });

    if (!response.ok) {
      return false;
    }

    const data = await response.json();
    console.log('✅ Test Agent is healthy');
    return data.status === 'healthy';
  } catch (error) {
    console.error('❌ Health check failed:', error.message);
    return false;
  }
}

export default {
  submitTests,
  getJobStatus,
  waitForCompletion,
  checkHealth,
};
