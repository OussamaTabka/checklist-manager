<template>
    <div class="run-tests-widget">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <button
                type="button"
                class="btn btn-primary"
                :disabled="isButtonDisabled"
                :aria-busy="loading ? 'true' : 'false'"
                @click="handleRunTests"
            >
                {{ buttonLabel }}
            </button>

            <button
                v-if="loading"
                type="button"
                class="btn btn-outline-secondary"
                @click="cancelRun"
            >
                Cancel
            </button>
        </div>

        <div
            v-if="loading"
            class="progress mt-3"
            style="height: 22px"
            role="progressbar"
            :aria-valuenow="progress"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="Test execution progress"
        >
            <div
                class="progress-bar progress-bar-striped progress-bar-animated"
                :style="{ width: `${progress}%` }"
            >
                {{ progress }}%
            </div>
        </div>

        <div v-if="results" class="alert mt-3" :class="resultAlertClass" role="status">
            <div class="fw-bold mb-1">
                <span v-if="!hasFailures">✅ All tests passed</span>
                <span v-else>⚠️ Tests completed with failures</span>
            </div>
            <div>✅ Passed: {{ results.passed }}</div>
            <div>❌ Failed: {{ results.failed }}</div>
            <div v-if="completionTimestamp">🕒 Completed: {{ completionTimestamp }}</div>

            <div class="mt-2 d-flex gap-2">
                <button
                    v-if="results.tests.length"
                    type="button"
                    class="btn btn-sm btn-outline-dark"
                    @click="showDetails = !showDetails"
                >
                    {{ showDetails ? 'Hide Details' : 'View Details' }}
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="clearResults">
                    Dismiss
                </button>
            </div>

            <ul v-if="showDetails && results.tests.length" class="list-group list-group-flush mt-3">
                <li
                    v-for="test in results.tests"
                    :key="`${test.id ?? test.title}-${test.status}`"
                    class="list-group-item px-0"
                >
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span class="fw-semibold">{{ test.title }}</span>
                        <span class="badge" :class="test.status === 'passed' ? 'text-bg-success' : 'text-bg-danger'">
                            {{ test.status === 'passed' ? '✅ Passed' : '❌ Failed' }}
                        </span>
                    </div>
                    <div v-if="test.error" class="text-danger small mt-1">{{ test.error }}</div>
                </li>
            </ul>
        </div>

        <div v-if="error" class="alert alert-danger mt-3" role="alert">
            <div class="fw-bold mb-1">❌ Test execution error</div>
            <div>{{ error }}</div>
            <div class="d-flex gap-2 mt-2">
                <button type="button" class="btn btn-sm btn-outline-danger" @click="handleRunTests" :disabled="loading">
                    Retry
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="dismissError">
                    Dismiss
                </button>
            </div>
        </div>

        <div v-if="!hasTestCases && !loading" class="alert alert-info mt-3 mb-0" role="status">
            No test cases available. Add test cases before running tests.
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import testAgentService from '../services/testAgentService';

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

const loading = ref(false);
const progress = ref(0);
const pollCount = ref(0);
const jobId = ref('');
const results = ref(null);
const error = ref('');
const showDetails = ref(false);
const abortController = ref(null);
let resetProgressTimeout = null;

const hasTestCases = computed(() => Array.isArray(props.testCases) && props.testCases.length > 0);
const isButtonDisabled = computed(() => loading.value || !hasTestCases.value);
const buttonLabel = computed(() => (loading.value ? `⏳ Running... ${progress.value}%` : '🚀 Run Tests'));
const hasFailures = computed(() => (results.value ? results.value.failed > 0 : false));
const resultAlertClass = computed(() => (hasFailures.value ? 'alert-warning' : 'alert-success'));
const completionTimestamp = computed(() => {
    if (!results.value?.timestamp) {
        return '';
    }

    const parsed = new Date(results.value.timestamp);
    return Number.isNaN(parsed.getTime()) ? results.value.timestamp : parsed.toLocaleString();
});

function dismissError() {
    error.value = '';
}

function clearResults() {
    results.value = null;
    showDetails.value = false;
}

function cancelRun() {
    if (abortController.value) {
        abortController.value.abort();
    }
}

function toNumber(value, fallback = 0) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
}

function normalizeStatus(status) {
    return status === 'passed' ? 'passed' : 'failed';
}

function resolveTestCaseId(testName, fallbackIndex) {
    const lookup = String(testName || '').trim().toLowerCase();

    if (!lookup) {
        return props.testCases[fallbackIndex]?.id ?? null;
    }

    const match = props.testCases.find((item) => String(item?.title || '').trim().toLowerCase() === lookup);
    return match?.id ?? props.testCases[fallbackIndex]?.id ?? null;
}

function buildResultPayload(job) {
    const payload = job?.results && typeof job.results === 'object' ? job.results : {};
    const summary = payload.summary && typeof payload.summary === 'object' ? payload.summary : {};
    const rawTests = Array.isArray(payload.results) ? payload.results : [];

    const tests = rawTests.map((entry, index) => {
        const title = String(entry?.test_name || entry?.title || `Test ${index + 1}`);
        return {
            id: entry?.id ?? resolveTestCaseId(title, index),
            title,
            status: normalizeStatus(entry?.status),
            error: entry?.error || null,
            timestamp: entry?.timestamp || null,
        };
    });

    const passed = toNumber(summary.passed, tests.filter((test) => test.status === 'passed').length);
    const failed = toNumber(summary.failed, tests.filter((test) => test.status === 'failed').length);

    return {
        passed,
        failed,
        total: toNumber(payload.total_tests, tests.length),
        tests,
        timestamp: payload.timestamp || new Date().toISOString(),
        jobId: job.job_id,
    };
}

async function handleRunTests() {
    if (!hasTestCases.value) {
        const message = 'No test cases available. Please add test cases before running tests.';
        error.value = message;
        emit('tests-failed', message);
        return;
    }

    error.value = '';
    results.value = null;
    showDetails.value = false;
    loading.value = true;
    progress.value = 10;
    pollCount.value = 0;

    try {
        const submission = await testAgentService.submitTests(props.testCases, String(props.checklistId));
        jobId.value = submission.job_id;
        progress.value = 20;

        abortController.value = new AbortController();

        const completedJob = await testAgentService.waitForCompletion(jobId.value, 600_000, {
            pollIntervalMs: 2_000,
            signal: abortController.value.signal,
            onPoll: ({ pollCount: currentPoll, progress: estimatedProgress, status }) => {
                pollCount.value = currentPoll;
                progress.value = Math.max(20, Math.min(90, estimatedProgress));
                console.log(`📊 Poll #${currentPoll}: ${status} (${progress.value}%)`);
            },
        });

        progress.value = 90;
        const parsedResults = buildResultPayload(completedJob);

        results.value = parsedResults;
        progress.value = 100;

        console.log(`✅ Tests completed: ${parsedResults.passed} passed, ${parsedResults.failed} failed`);

        emit('tests-complete', {
            passed: parsedResults.passed,
            failed: parsedResults.failed,
            tests: parsedResults.tests,
            timestamp: parsedResults.timestamp,
        });
    } catch (runError) {
        const message = runError?.name === 'AbortError'
            ? 'Test run cancelled by user.'
            : runError?.message || 'Failed to run tests.';

        error.value = message;
        console.error(`❌ Error: ${message}`, runError);
        emit('tests-failed', message);
    } finally {
        loading.value = false;
        abortController.value = null;

        if (resetProgressTimeout) {
            clearTimeout(resetProgressTimeout);
        }

        resetProgressTimeout = window.setTimeout(() => {
            progress.value = 0;
        }, 2_000);
    }
}

onBeforeUnmount(() => {
    if (abortController.value) {
        abortController.value.abort();
    }

    if (resetProgressTimeout) {
        clearTimeout(resetProgressTimeout);
    }
});
</script>

<style scoped>
.run-tests-widget {
    width: 100%;
}

.progress-bar {
    transition: width 0.3s ease;
}

.list-group-item {
    border-left: 0;
    border-right: 0;
}
</style>
