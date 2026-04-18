const DEFAULT_AGENT_URL = 'http://localhost:8000';
const DEFAULT_MAX_WAIT_MS = 600_000;
const DEFAULT_POLL_INTERVAL_MS = 2_000;

const TEST_AGENT_URL = (import.meta.env.VITE_TEST_AGENT_URL || DEFAULT_AGENT_URL).replace(/\/+$/, '');

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function extractApiError(payload, fallbackMessage) {
    if (payload && typeof payload === 'object') {
        if (typeof payload.detail === 'string' && payload.detail.trim() !== '') {
            return payload.detail;
        }

        if (typeof payload.message === 'string' && payload.message.trim() !== '') {
            return payload.message;
        }

        if (typeof payload.error === 'string' && payload.error.trim() !== '') {
            return payload.error;
        }
    }

    return fallbackMessage;
}

async function requestJson(path, options = {}, timeoutMs = 15_000) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

    if (options.signal) {
        if (options.signal.aborted) {
            controller.abort();
        } else {
            options.signal.addEventListener('abort', () => controller.abort(), { once: true });
        }
    }

    try {
        const response = await fetch(`${TEST_AGENT_URL}${path}`, {
            ...options,
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                ...(options.body ? { 'Content-Type': 'application/json' } : {}),
                ...(options.headers || {}),
            },
        });

        let payload = null;
        const rawText = await response.text();

        if (rawText) {
            try {
                payload = JSON.parse(rawText);
            } catch {
                throw new Error('Unexpected response format from test agent.');
            }
        }

        if (!response.ok) {
            const fallbackMessage = `Test agent request failed (${response.status}).`;
            throw new Error(extractApiError(payload, fallbackMessage));
        }

        if (!payload || typeof payload !== 'object') {
            throw new Error('Unexpected response format from test agent.');
        }

        return payload;
    } catch (error) {
        if (error.name === 'AbortError') {
            throw new Error('Request to test agent timed out.');
        }

        if (error instanceof TypeError) {
            throw new Error('Failed to connect to test agent. Check if server is running.');
        }

        throw error;
    } finally {
        clearTimeout(timeoutId);
    }
}

const testAgentService = {
    /**
     * Submit test cases to the external AI test agent.
     */
    async submitTests(testCases, checklistId) {
        if (!Array.isArray(testCases) || testCases.length === 0) {
            throw new Error('Please provide at least one test case before running tests.');
        }

        if (!checklistId && checklistId !== 0) {
            throw new Error('Checklist ID is required to run tests.');
        }

        const normalizedCases = testCases.map((item, index) => {
            const title = String(item?.title || '').trim();
            const description = String(item?.description || '').trim();

            if (!title || !description) {
                throw new Error(`Test case #${index + 1} is missing a title or description.`);
            }

            return {
                title,
                description,
            };
        });

        const payload = {
            checklist_id: String(checklistId),
            app_url: import.meta.env.VITE_APP_URL || window.location.origin,
            test_cases: normalizedCases,
        };

        if (import.meta.env.VITE_TEST_AGENT_WEBHOOK_URL) {
            payload.webhook_url = import.meta.env.VITE_TEST_AGENT_WEBHOOK_URL;
        }

        console.log(`📤 Submitting ${normalizedCases.length} test cases...`);

        const response = await requestJson('/run-tests', {
            method: 'POST',
            body: JSON.stringify(payload),
        });

        if (typeof response.job_id !== 'string' || response.job_id.trim() === '') {
            throw new Error('Unexpected response format from test agent. Missing job_id.');
        }

        console.log(`✅ Job ID: ${response.job_id}`);
        return response;
    },

    /**
     * Get status and results for a submitted job.
     */
    async getJobStatus(jobId) {
        if (!jobId || typeof jobId !== 'string') {
            throw new Error('A valid job ID is required to check job status.');
        }

        const response = await requestJson(`/jobs/${encodeURIComponent(jobId)}`, {
            method: 'GET',
        });

        if (typeof response.status !== 'string') {
            throw new Error('Unexpected response format from test agent. Missing status field.');
        }

        return response;
    },

    /**
     * Poll the test agent until the job completes, fails, or reaches timeout.
     */
    async waitForCompletion(jobId, maxWait = DEFAULT_MAX_WAIT_MS, options = {}) {
        if (!jobId || typeof jobId !== 'string') {
            throw new Error('A valid job ID is required to wait for completion.');
        }

        const pollIntervalMs = Number(options.pollIntervalMs || DEFAULT_POLL_INTERVAL_MS);
        const onPoll = typeof options.onPoll === 'function' ? options.onPoll : null;
        const signal = options.signal;

        console.log('⏳ Polling job status...');

        const startedAt = Date.now();
        let pollCount = 0;
        let consecutiveConnectionFailures = 0;

        while (true) {
            if (signal?.aborted) {
                throw new DOMException('Test run cancelled by user.', 'AbortError');
            }

            const elapsed = Date.now() - startedAt;
            if (elapsed > maxWait) {
                throw new Error('Tests took too long to complete (10+ minutes). Check test agent logs and retry.');
            }

            try {
                pollCount += 1;
                const job = await this.getJobStatus(jobId);
                consecutiveConnectionFailures = 0;

                const progress = Math.min(95, Math.max(20, Math.round((elapsed / maxWait) * 100)));
                console.log(`📊 Poll #${pollCount}: ${job.status} (${progress}%)`);

                if (onPoll) {
                    onPoll({
                        pollCount,
                        elapsed,
                        progress,
                        status: job.status,
                        payload: job,
                    });
                }

                if (job.status === 'completed') {
                    return job;
                }

                if (job.status === 'failed') {
                    throw new Error(extractApiError(job, 'Test execution failed on the test agent.'));
                }

                if (job.error) {
                    throw new Error(extractApiError(job, 'Test job returned an error from the test agent.'));
                }
            } catch (error) {
                const message = error?.message || 'Unknown error while polling test agent.';

                if (/Job not found/i.test(message)) {
                    throw error;
                }

                if (/Failed to connect to test agent/i.test(message)) {
                    consecutiveConnectionFailures += 1;
                    if (consecutiveConnectionFailures >= 3) {
                        throw new Error('Failed to connect to test agent. Check if server is running.');
                    }
                } else if (error?.name === 'AbortError') {
                    throw error;
                } else if (/Unexpected response format from test agent/i.test(message)) {
                    throw error;
                } else if (/Test execution failed|returned an error/i.test(message)) {
                    throw error;
                }
            }

            await sleep(pollIntervalMs);
        }
    },
};

export default testAgentService;
