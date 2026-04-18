<template>
  <div class="run-tests-button-container">
    <!-- Main Run Tests Button -->
    <button
      @click="handleRunTests"
      :disabled="isLoading || !hasTestCases"
      class="btn btn-primary run-tests-btn"
      :class="{ 'btn-lg': !isLoading }"
      type="button"
    >
      <span v-if="!isLoading" class="button-text">
        🚀 Run Tests
      </span>
      <span v-else class="button-text">
        ⏳ Running... {{ progress }}%
      </span>
    </button>

    <!-- Progress Bar -->
    <Transition name="fade">
      <div v-if="isLoading" class="progress-container mt-3">
        <div class="progress" style="height: 24px">
          <div
            class="progress-bar progress-bar-animated progress-bar-striped"
            role="progressbar"
            :aria-valuenow="progress"
            aria-valuemin="0"
            aria-valuemax="100"
            :style="{ width: progress + '%' }"
          >
            <span v-if="progress > 20" class="progress-text">{{ progress }}%</span>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Results - Success Alert -->
    <Transition name="slide-down">
      <div v-if="showResults && !error" class="results-container mt-3">
        <div
          :class="[
            'alert',
            allTestsPassed ? 'alert-success' : 'alert-warning',
            'alert-dismissible fade show'
          ]"
          role="alert"
        >
          <div class="results-header mb-2">
            <strong>
              <span v-if="allTestsPassed">✅ All Tests Passed!</span>
              <span v-else>⚠️ Some Tests Failed</span>
            </strong>
          </div>

          <div class="results-stats mb-2">
            <div class="stat-item">
              <strong class="text-success">✅ Passed:</strong> {{ results.passed }}
            </div>
            <div class="stat-item mt-2">
              <strong class="text-danger">❌ Failed:</strong> {{ results.failed }}
            </div>
            <div class="stat-item mt-2" v-if="results.timestamp">
              <strong>🕐 Completed:</strong> {{ formatTime(results.timestamp) }}
            </div>
            <div class="stat-item mt-2" v-if="results.successRate">
              <strong>📊 Success Rate:</strong> {{ results.successRate }}
            </div>
          </div>

          <!-- Test Details (optional) -->
          <div v-if="showTestDetails" class="test-details mt-3">
            <small class="text-muted d-block mb-2">
              <strong>Test Details:</strong>
            </small>
            <div v-for="test in results.tests" :key="`${test.name}-${test.status}`" class="test-item mb-2">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <span :class="['test-status', `status-${test.status}`]">
                    {{ test.status === 'passed' ? '✅' : '❌' }} {{ test.name }}
                  </span>
                  <div v-if="test.error" class="text-danger mt-1 small">
                    <em>{{ test.error }}</em>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- View Details Button -->
          <div class="mt-3">
            <button
              @click="toggleTestDetails"
              class="btn btn-sm btn-outline-secondary"
              type="button"
            >
              {{ showTestDetails ? '🔼 Hide Details' : '🔽 View Details' }}
            </button>
          </div>

          <button
            type="button"
            class="btn-close"
            @click="clearResults"
            aria-label="Close"
          />
        </div>
      </div>
    </Transition>

    <!-- Error Alert -->
    <Transition name="slide-down">
      <div v-if="error" class="error-container mt-3">
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <div class="error-header mb-2">
            <strong>❌ Error</strong>
          </div>
          <div class="error-message">
            {{ error }}
          </div>
          <div class="error-suggestions mt-2 small text-muted">
            <p v-if="error.includes('connect')" class="mb-1">
              💡 Suggestion: Make sure the test agent is running. 
              Run <code>docker-compose up</code> in the test agent directory.
            </p>
            <p v-if="error.includes('timeout')" class="mb-1">
              💡 Suggestion: Tests took too long. Check test agent logs or try simpler tests.
            </p>
            <p v-if="error.includes('not found')" class="mb-1">
              💡 Suggestion: Job ID might be invalid or expired. Try submitting tests again.
            </p>
          </div>
          <div class="mt-3">
            <button
              @click="handleRetry"
              class="btn btn-sm btn-outline-danger me-2"
              type="button"
            >
              🔄 Retry
            </button>
            <button
              @click="clearError"
              class="btn btn-close"
              aria-label="Close"
            />
          </div>
        </div>
      </div>
    </Transition>

    <!-- No Test Cases Warning -->
    <div v-if="!hasTestCases && !isLoading" class="alert alert-info alert-sm mt-3">
      <strong>ℹ️ Info:</strong> No test cases available. Add test cases to run tests.
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import testAgentService from '../services/testAgentService';

// Props
const props = defineProps({
  testCases: {
    type: Array,
    required: true,
    default: () => [],
  },
  checklistId: {
    type: [String, Number],
    required: true,
  },
});

// Events
const emit = defineEmits(['tests-complete', 'tests-failed']);

// Reactive State
const isLoading = ref(false);
const progress = ref(0);
const results = ref(null);
const error = ref(null);
const jobId = ref(null);
const showResults = ref(false);
const showTestDetails = ref(false);

// Computed
const hasTestCases = computed(() => {
  return Array.isArray(props.testCases) && props.testCases.length > 0;
});

const allTestsPassed = computed(() => {
  return results.value && results.value.failed === 0;
});

// Methods
const handleRunTests = async () => {
  try {
    // Validate input
    if (!hasTestCases.value) {
      error.value = 'No test cases available. Please add test cases before running tests.';
      return;
    }

    // Reset state
    clearResults();
    clearError();
    isLoading.value = true;
    progress.value = 0;
    showTestDetails.value = false;

    console.log('🎯 Starting test execution workflow...');

    // Step 1: Submit tests
    progress.value = 10;
    console.log('📤 Submitting test cases...');

    const submitResponse = await testAgentService.submitTests(
      props.testCases,
      props.checklistId
    );

    jobId.value = submitResponse.job_id;
    progress.value = 20;
    console.log('✅ Tests submitted, job ID:', jobId.value);

    // Step 2: Wait for completion
    progress.value = 30;
    console.log('⏳ Waiting for test execution...');

    const completedJob = await testAgentService.waitForCompletion(jobId.value);

    // Process results
    progress.value = 95;
    console.log('📊 Processing results...');

    const apiResults = completedJob.results;

    if (!apiResults) {
      throw new Error('No results returned from test agent');
    }

    // Format results for component display
    results.value = {
      passed: apiResults.summary?.passed || 0,
      failed: apiResults.summary?.failed || 0,
      successRate: apiResults.summary?.success_rate || '0%',
      timestamp: apiResults.timestamp,
      tests: (apiResults.results || []).map(test => ({
        name: test.test_name || test.test_file || 'Unknown test',
        status: test.status || 'unknown',
        error: test.error || null,
      })),
    };

    progress.value = 100;
    showResults.value = true;

    console.log('✅ Tests completed successfully');
    console.log('📈 Results:', results.value);

    // Emit event for parent component
    emit('tests-complete', results.value);

    // Hide loading state after results display
    setTimeout(() => {
      isLoading.value = false;
    }, 500);
  } catch (err) {
    console.error('❌ Test execution failed:', err);

    // Determine user-friendly error message
    let userMessage = err.message;

    if (err.message.includes('Failed to connect') || err.message.includes('fetch')) {
      userMessage = 'Failed to connect to test agent. Check if server is running (http://localhost:8000)';
    } else if (err.message.includes('timeout')) {
      userMessage = 'Test execution timeout. Tests took longer than 10 minutes.';
    } else if (err.message.includes('not found')) {
      userMessage = 'Job not found. Test agent may have restarted. Please try again.';
    }

    error.value = userMessage;
    isLoading.value = false;
    progress.value = 0;

    // Emit failed event
    emit('tests-failed', userMessage);
  }
};

const handleRetry = () => {
  clearError();
  handleRunTests();
};

const clearResults = () => {
  results.value = null;
  showResults.value = false;
  showTestDetails.value = false;
};

const clearError = () => {
  error.value = null;
};

const toggleTestDetails = () => {
  showTestDetails.value = !showTestDetails.value;
};

const formatTime = (timestamp) => {
  if (!timestamp) return '';
  try {
    const date = new Date(timestamp);
    return date.toLocaleString();
  } catch {
    return timestamp;
  }
};

// Lifecycle
onMounted(() => {
  console.log('🏗️ RunTestsButton component mounted');
  console.log('📋 Test cases:', props.testCases);
  console.log('📂 Checklist ID:', props.checklistId);
});
</script>

<style scoped>
.run-tests-button-container {
  position: relative;
  width: 100%;
}

.run-tests-btn {
  font-weight: 600;
  letter-spacing: 0.5px;
  transition: all 0.3s ease;
  min-width: 150px;
}

.run-tests-btn:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.run-tests-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.button-text {
  display: inline-block;
  user-select: none;
}

/* Progress Bar */
.progress-container {
  animation: slideDown 0.3s ease-out;
}

.progress {
  background-color: #e9ecef;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.progress-bar {
  background: linear-gradient(90deg, #0d6efd, #0dcaf0);
  font-weight: 600;
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  transition: width 0.6s ease;
}

.progress-text {
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}

/* Results */
.results-container {
  animation: slideDown 0.4s ease-out;
}

.results-header {
  font-size: 16px;
}

.results-stats {
  background-color: #f8f9fa;
  padding: 12px;
  border-radius: 6px;
  border-left: 4px solid #0d6efd;
}

.stat-item {
  font-size: 14px;
  line-height: 1.6;
}

.stat-item code {
  background-color: #e9ecef;
  padding: 2px 6px;
  border-radius: 3px;
  font-family: 'Courier New', monospace;
}

/* Test Details */
.test-details {
  background-color: #f8f9fa;
  padding: 12px;
  border-radius: 6px;
  max-height: 400px;
  overflow-y: auto;
}

.test-item {
  padding: 8px;
  background-color: white;
  border-radius: 4px;
  border-left: 3px solid #dee2e6;
}

.test-item.status-passed {
  border-left-color: #198754;
}

.test-item.status-failed {
  border-left-color: #dc3545;
}

.test-status {
  font-weight: 500;
  font-size: 13px;
}

/* Error Alert */
.error-container {
  animation: slideDown 0.4s ease-out;
}

.error-header {
  font-size: 16px;
}

.error-message {
  background-color: #f8d7da;
  padding: 10px;
  border-radius: 4px;
  border-left: 4px solid #dc3545;
  font-size: 14px;
  line-height: 1.5;
}

.error-suggestions {
  background-color: #f8f9fa;
  padding: 10px;
  border-radius: 4px;
  border-left: 4px solid #ffc107;
  line-height: 1.6;
}

.error-suggestions code {
  background-color: #e9ecef;
  padding: 2px 6px;
  border-radius: 3px;
  font-family: 'Courier New', monospace;
  color: #555;
}

/* Alert overrides */
.alert {
  border: none;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.alert-sm {
  padding: 10px 12px;
  font-size: 12px;
}

/* Transitions */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.3s ease;
}

.slide-down-enter-from {
  opacity: 0;
  transform: translateY(-10px);
}

.slide-down-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

/* Responsive */
@media (max-width: 576px) {
  .run-tests-btn {
    width: 100%;
  }

  .results-stats {
    font-size: 13px;
  }

  .test-details {
    max-height: 300px;
  }
}
</style>
