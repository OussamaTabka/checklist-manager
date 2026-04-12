"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createRunInLaravel = createRunInLaravel;
exports.publishBatchResults = publishBatchResults;
exports.patchVersionItemsFallback = patchVersionItemsFallback;
function apiHeaders(token) {
    return {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
        'Content-Type': 'application/json',
    };
}
async function createRunInLaravel(config, runRequest) {
    if (!config.laravelBaseUrl || !config.laravelToken || !config.projectVersionId) {
        throw new Error('Laravel create run requires LARAVEL_BASE_URL, LARAVEL_TOKEN and PROJECT_VERSION_ID');
    }
    const payload = {
        schema_version: '1.0',
        project_version_id: config.projectVersionId,
        base_url: runRequest.target.base_url,
        mode: config.runMode,
        cases: runRequest.cases.map((c) => ({
            external_id: c.external_id,
            title: c.title,
            steps: c.steps,
            asserts: c.asserts,
        })),
    };
    const createRunUrl = `${config.laravelBaseUrl}/test-runs`;
    const response = await fetch(createRunUrl, {
        method: 'POST',
        headers: apiHeaders(config.laravelToken),
        body: JSON.stringify(payload),
    });
    const body = (await response.json().catch(() => ({})));
    if (!response.ok) {
        throw new Error(`Create run failed (${response.status}): ${JSON.stringify(body)}`);
    }
    if (typeof body.run_id !== 'string' || body.run_id.trim() === '') {
        throw new Error(`Create run response does not contain run_id: ${JSON.stringify(body)}`);
    }
    return {
        run_id: body.run_id,
        status: typeof body.status === 'string' ? body.status : 'created',
    };
}
function toBatchArtifacts(artifacts) {
    return {
        trace: artifacts.trace_path ? [artifacts.trace_path] : [],
        screenshot: artifacts.screenshot_path ? [artifacts.screenshot_path] : [],
        video: artifacts.video_path ? [artifacts.video_path] : [],
        raw_paths: artifacts,
    };
}
async function publishBatchResults(config, runId, result) {
    if (!config.laravelBaseUrl || !config.laravelToken) {
        throw new Error('Batch publish requires LARAVEL_BASE_URL and LARAVEL_TOKEN');
    }
    const payload = {
        schema_version: result.schema_version,
        run_id: result.run_id,
        runner_run_id: result.runner_run_id,
        status: result.status,
        summary: result.summary,
        results: result.results.map((item) => ({
            external_id: item.external_id,
            status: item.status,
            duration_ms: item.duration_ms,
            attempt: item.attempt,
            error_type: item.error_type,
            error_message: item.error_message,
            artifacts: toBatchArtifacts(item.artifacts),
        })),
    };
    const publishResultsUrl = `${config.laravelBaseUrl}/test-runs/${encodeURIComponent(runId)}/results`;
    const response = await fetch(publishResultsUrl, {
        method: 'POST',
        headers: apiHeaders(config.laravelToken),
        body: JSON.stringify(payload),
    });
    const body = (await response.json().catch(() => ({})));
    if (!response.ok) {
        throw new Error(`Batch publish failed (${response.status}): ${JSON.stringify(body)}`);
    }
}
function mapCaseStatusToVersionItemStatus(status) {
    switch (status) {
        case 'passed':
            return 'Passed';
        case 'failed':
            return 'Failed';
        case 'blocked':
            return 'Blocked';
        case 'skipped':
            return 'Not Tested';
        default:
            return 'Not Tested';
    }
}
async function patchVersionItemsFallback(config, result) {
    if (!config.laravelBaseUrl || !config.laravelToken) {
        throw new Error('Fallback patch requires LARAVEL_BASE_URL and LARAVEL_TOKEN');
    }
    for (const item of result.results) {
        const payload = {
            status: mapCaseStatusToVersionItemStatus(item.status),
        };
        const patchVersionItemUrl = `${config.laravelBaseUrl}/version-items/${item.external_id}/status`;
        const response = await fetch(patchVersionItemUrl, {
            method: 'PATCH',
            headers: apiHeaders(config.laravelToken),
            body: JSON.stringify(payload),
        });
        const body = (await response.json().catch(() => ({})));
        if (!response.ok) {
            throw new Error(`Fallback patch failed for item ${item.external_id} (${response.status}): ${JSON.stringify(body)}`);
        }
    }
}
