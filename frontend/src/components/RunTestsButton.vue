<template>
  <div class="run-tests-container">
    <!-- Button -->
    <button
      @click="handleRunTests"
      :disabled="isLoading || !testCases || testCases.length === 0"
      :class="['btn', isLoading ? 'btn-secondary' : 'btn-primary']"
    >
      <span v-if="isLoading" class="spinner-border spinner-border-sm me-2"></span>
      {{ isLoading ? 'Running Tests...' : 'Run Tests' }}
    </button>

    <!-- Progress Bar -->
    <div v-if="isLoading" class="progress mt-3" style="height: 25px">
      <div
        class="progress-bar progress-bar-striped progress-bar-animated"
        role="progressbar"
        :style="{ width: progress + '%' }"
        :aria-valuenow="progress"
        aria-valuemin="0"
        aria-valuemax="100"
      >
        {{ progress }}%
      </div>
    </div>

    <!-- Results Alert (Success) -->
    <div v-if="showResults && !error && results" class="alert alert-success mt-3" role="alert">
      <h5 class="alert-heading">✅ Tests Completed Successfully!</h5>
      <hr />
      <p><strong>Passed:</strong> {{ results.passed }}</p>
      <p><strong>Failed:</strong> {{ results.failed }}</p>
      <p v-if="results.total" class="mb-0"><strong>Total:</strong> {{ results.total }}</p>
    </div>

    <!-- Error Alert -->
    <div v-if="error" class="alert alert-danger mt-3" role="alert">
      <h5 class="alert-heading">❌ Error</h5>
      <p class="mb-0">{{ error }}</p>
      <button
        @click="clearError"
        type="button"
        class="btn-close"
        aria-label="Close"
      ></button>
    </div>

    <!-- Health Check Warning -->
    <div v-if="showHealthWarning" class="alert alert-warning mt-3" role="alert">
      <small>
        ⚠️ Test Agent is not responding. Make sure it's running on
        {{ testAgentUrl }}
      </small>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { submitTests, getJobStatus, checkHealth } from '@/lib/testAgentService';
import { apiRequest } from '@/lib/api';

const props = defineProps({
  testCases: {
    type: Array,
    required: true,
  },
  checklistId: {
    type: [String, Number],
    required: true,
  },
});

const emit = defineEmits(['tests-complete', 'tests-failed']);

const isLoading = ref(false);
const progress = ref(0);
const error = ref('');
const results = ref(null);
const showResults = ref(false);
const showHealthWarning = ref(false);
const jobId = ref(null);
const testAgentUrl = import.meta.env.VITE_TEST_AGENT_URL || 'http://localhost:8000';

const hasTests = computed(() => {
  return props.testCases && props.testCases.length > 0;
});

onMounted(async () => {
  // Check if test agent is available on mount
  const isHealthy = await checkHealth();
  showHealthWarning.value = !isHealthy;
});

async function handleRunTests() {
  if (!hasTests.value) {
    error.value = 'No test cases available';
    return;
  }

  isLoading.value = true;
  error.value = '';
  results.value = null;
  showResults.value = false;
  progress.value = 10;

  try {
    // Step 1: Submit tests to external agent
    console.log('📤 Submitting test cases...');
    const submitResponse = await submitTests(props.testCases, props.checklistId);
    jobId.value = submitResponse.job_id;
    progress.value = 20;

    // Step 2: Poll for completion
    console.log(`⏳ Polling for job ${jobId.value}...`);
    let jobStatus = await pollUntilComplete(jobId.value);
    progress.value = 90;

    // Step 3: Extract results
    const testResults = {
      passed: jobStatus.results?.passed || 0,
      failed: jobStatus.results?.failed || 0,
      total: (jobStatus.results?.passed || 0) + (jobStatus.results?.failed || 0),
      tests: jobStatus.results?.tests || [],
      timestamp: new Date().toISOString(),
    };

    results.value = testResults;
    progress.value = 100;
    showResults.value = true;

    // Step 4: Save results to Laravel backend
    console.log('💾 Saving results to backend...');
    await saveResultsToBackend(testResults);
    progress.value = 100;

    // Emit event for parent component
    emit('tests-complete', testResults);

    console.log('✅ Test workflow completed');
  } catch (err) {
    error.value = err.message || 'Failed to run tests';
    console.error('❌ Test execution error:', err);
    emit('tests-failed', error.value);
  } finally {
    isLoading.value = false;
    // Reset progress after a delay
    setTimeout(() => {
      progress.value = 0;
    }, 2000);
  }
}

async function pollUntilComplete(id) {
  const maxWait = 600000; // 10 minutes
  const pollInterval = 2000; // 2 seconds
  const startTime = Date.now();
  let pollCount = 0;

  while (true) {
    pollCount++;
    const elapsedTime = Date.now() - startTime;

    if (elapsedTime > maxWait) {
      throw new Error('Tests took longer than 10 minutes');
    }

    try {
      const jobStatus = await getJobStatus(id);
      const progressPercent = Math.min(Math.round((elapsedTime / maxWait) * 80 + 20), 95);
      progress.value = progressPercent;

      console.log(`Poll #${pollCount}: ${jobStatus.status} (${progressPercent}%)`);

      if (jobStatus.status === 'completed') {
        console.log('✅ Job completed!');
        return jobStatus;
      }

      if (jobStatus.status === 'failed' || jobStatus.error) {
        throw new Error(jobStatus.error || 'Job failed');
      }

      await new Promise(resolve => setTimeout(resolve, pollInterval));
    } catch (err) {
      if (err.message.includes('Job not found')) {
        throw err;
      }
      console.warn(`⚠️  Poll attempt #${pollCount} failed, retrying...`);
      await new Promise(resolve => setTimeout(resolve, pollInterval));
    }
  }
}

async function saveResultsToBackend(testResults) {
  try {
    const payload = {
      checklist_id: props.checklistId,
      results: testResults,
    };

    const response = await apiRequest('POST', '/api/update-test-results', payload);
    console.log('✅ Results saved to backend:', response);
    return response;
  } catch (err) {
    console.warn('⚠️  Could not save results to backend:', err.message);
    // Don't throw - results were successfully obtained from test agent
  }
}

function clearError() {
  error.value = '';
}
</script>

<style scoped>
.run-tests-container {
  margin: 1rem 0;
}

button {
  font-weight: 500;
}

button:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.progress {
  border-radius: 0.25rem;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.progress-bar {
  font-weight: 500;
  min-width: 3rem;
}

.alert {
  margin-top: 1rem;
  border-radius: 0.25rem;
}
</style>
