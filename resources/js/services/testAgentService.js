/**
 * Test Agent Service
 * Handles all communication with the external AI Test Agent API
 * 
 * API Documentation: {BASE_URL}/docs
 * Default: http://localhost:8000
 */

const TEST_AGENT_URL = import.meta.env.VITE_TEST_AGENT_URL || 'http://localhost:8000';
const POLL_INTERVAL = 2000; // 2 seconds between polls
const DEFAULT_MAX_WAIT = 600000; // 10 minutes

/**
 * Submit test cases to the AI Test Agent
 * 
 * @param {Array} testCases - Array of test case objects with title and description
 * @param {String} checklistId - ID of the checklist from your database
 * @returns {Promise<Object>} Response with job_id
 * @throws {Error} If submission fails
 */
export async function submitTests(testCases, checklistId) {
  try {
    console.log(`📤 Submitting ${testCases.length} test case(s) for checklist ${checklistId}...`);

    if (!testCases || !Array.isArray(testCases) || testCases.length === 0) {
      throw new Error('Test cases array is empty or invalid');
    }

    if (!checklistId) {
      throw new Error('Checklist ID is required');
    }

    const requestBody = {
      checklist_id: String(checklistId),
      app_url: import.meta.env.VITE_APP_URL || 'http://localhost:8000',
      test_cases: testCases.map(test => ({
        title: test.title || 'Untitled Test',
        description: test.description || 'No description',
      })),
    };

    console.log('📋 Request payload:', JSON.stringify(requestBody, null, 2));

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
      const errorMessage = errorData.detail || `HTTP ${response.status}: ${response.statusText}`;
      throw new Error(`Failed to submit tests: ${errorMessage}`);
    }

    const data = await response.json();

    if (!data.job_id) {
      throw new Error('Invalid response: missing job_id');
    }

    console.log(`✅ Job ID: ${data.job_id}`);
    console.log(`📊 Status: ${data.status}`);
    console.log(`💬 Message: ${data.message}`);

    return data;
  } catch (error) {
    console.error('❌ Test submission error:', error.message);
    throw error;
  }
}

/**
 * Get the current status of a test job
 * 
 * @param {String} jobId - UUID of the job from submitTests response
 * @returns {Promise<Object>} Job status object with results (if completed)
 * @throws {Error} If request fails
 */
export async function getJobStatus(jobId) {
  try {
    if (!jobId || typeof jobId !== 'string') {
      throw new Error('Invalid job ID format');
    }

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
      const errorData = await response.json().catch(() => ({}));
      const errorMessage = errorData.detail || `HTTP ${response.status}`;
      throw new Error(`Failed to fetch job status: ${errorMessage}`);
    }

    const data = await response.json();

    return data;
  } catch (error) {
    console.error('❌ Job status error:', error.message);
    throw error;
  }
}

/**
 * Poll job status until completion
 * 
 * Shows progress updates and handles timeouts
 * 
 * @param {String} jobId - UUID of the job
 * @param {Number} maxWait - Maximum wait time in milliseconds (default: 10 minutes)
 * @returns {Promise<Object>} Completed job object with results
 * @throws {Error} If timeout exceeded or job fails
 */
export async function waitForCompletion(jobId, maxWait = DEFAULT_MAX_WAIT) {
  try {
    console.log(`⏳ Polling job status (max wait: ${maxWait / 1000}s)...`);

    const startTime = Date.now();
    let pollCount = 0;

    // Poll until completion or timeout
    while (true) {
      pollCount++;
      const elapsedTime = Date.now() - startTime;

      // Check timeout
      if (elapsedTime > maxWait) {
        const maxMinutes = Math.floor(maxWait / 60000);
        throw new Error(
          `Test execution timeout: Tests took longer than ${maxMinutes} minutes. ` +
          `Check test agent logs for details.`
        );
      }

      try {
        const jobStatus = await getJobStatus(jobId);

        // Calculate progress (0-100%)
        const progress = Math.min(Math.round((elapsedTime / maxWait) * 100), 95);

        console.log(
          `📊 Poll #${pollCount}: ${jobStatus.status.toUpperCase()} (${progress}%) - ` +
          `${(elapsedTime / 1000).toFixed(1)}s elapsed`
        );

        // Job completed
        if (jobStatus.status === 'completed') {
          console.log('✅ Tests completed!');
          console.log(`📈 Results summary:`, jobStatus.results?.summary || 'N/A');
          return jobStatus;
        }

        // Job failed
        if (jobStatus.status === 'failed' || jobStatus.error) {
          const errorMessage = jobStatus.error || 'Unknown error occurred';
          throw new Error(`Test job failed: ${errorMessage}`);
        }

        // Still running, wait before next poll
        await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL));
      } catch (error) {
        // Don't retry on 404 (job not found)
        if (error.message.includes('Job not found')) {
          throw error;
        }

        // For other errors, log and continue polling (transient network errors)
        console.warn(`⚠️  Poll error (will retry): ${error.message}`);
        await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL));
      }
    }
  } catch (error) {
    console.error('❌ Completion polling error:', error.message);
    throw error;
  }
}

/**
 * Health check - verify API is running
 * 
 * @returns {Promise<Boolean>} true if healthy, false otherwise
 */
export async function checkHealth() {
  try {
    const response = await fetch(`${TEST_AGENT_URL}/health`, {
      method: 'GET',
      headers: {
        'Accept': 'application/json',
      },
    });

    if (!response.ok) {
      console.warn(`⚠️  Health check failed: HTTP ${response.status}`);
      return false;
    }

    const data = await response.json();
    console.log('✅ Test Agent is healthy:', data);
    return data.status === 'healthy';
  } catch (error) {
    console.error('❌ Health check failed:', error.message);
    return false;
  }
}

/**
 * Complete test execution workflow:
 * 1. Submit tests
 * 2. Poll until completion
 * 3. Return results
 * 
 * @param {Array} testCases - Array of test case objects
 * @param {String} checklistId - Checklist ID
 * @param {Function} onProgress - Callback for progress updates {progress, status}
 * @returns {Promise<Object>} Completed job with results
 * @throws {Error} If any step fails
 */
export async function runTestsWorkflow(testCases, checklistId, onProgress = null) {
  try {
    // Step 1: Submit tests
    const submitResponse = await submitTests(testCases, checklistId);
    const jobId = submitResponse.job_id;

    if (onProgress) {
      onProgress({ progress: 20, status: 'submitted' });
    }

    // Step 2: Wait for completion with progress tracking
    let lastProgress = 20;
    const completionPromise = waitForCompletion(jobId);

    // Update progress periodically while waiting
    const progressInterval = setInterval(() => {
      lastProgress = Math.min(lastProgress + 5, 90);
      if (onProgress) {
        onProgress({ progress: lastProgress, status: 'processing' });
      }
    }, POLL_INTERVAL * 2);

    try {
      const completedJob = await completionPromise;
      clearInterval(progressInterval);

      if (onProgress) {
        onProgress({ progress: 100, status: 'completed' });
      }

      return completedJob;
    } catch (error) {
      clearInterval(progressInterval);
      throw error;
    }
  } catch (error) {
    console.error('❌ Test workflow error:', error.message);
    throw error;
  }
}

export default {
  submitTests,
  getJobStatus,
  waitForCompletion,
  checkHealth,
  runTestsWorkflow,
};
