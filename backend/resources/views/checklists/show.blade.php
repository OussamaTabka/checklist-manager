<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $checklist->name ?? 'Checklist Details' }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    @vite(['resources/js/app.js'])
</head>
<body class="bg-light">
@php
    $testCases = $testCases ?? ($checklist->items ?? collect());
@endphp

<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h1 class="h3 mb-1">{{ $checklist->name ?? 'Checklist' }}</h1>
            <p class="text-muted mb-0">{{ $checklist->description ?? 'Run AI-powered UI tests for this checklist.' }}</p>
        </div>
        <span class="badge text-bg-secondary">{{ $testCases->count() }} test case(s)</span>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div id="app">
                <run-tests-button
                    :test-cases="@json($testCases->map(fn ($item) => ['id' => $item->id, 'title' => $item->title, 'description' => $item->description]))"
                    :checklist-id="@json((string) $checklist->id)"
                    @tests-complete="handleTestsComplete"
                    @tests-failed="handleTestsFailed"
                />
            </div>
            <div id="test-agent-feedback" class="mt-3"></div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <strong>Test Results</strong>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" id="test-results-table">
                <thead class="table-light">
                <tr>
                    <th>Test Name</th>
                    <th>Status</th>
                    <th>Last Run</th>
                    <th>Last Run Duration</th>
                </tr>
                </thead>
                <tbody>
                @forelse($testCases as $testCase)
                    <tr data-test-id="{{ $testCase->id }}" data-test-title="{{ Str::lower($testCase->title) }}">
                        <td>{{ $testCase->title }}</td>
                        <td data-col="status">
                            @if($testCase->status === 'passed')
                                <span class="badge text-bg-success">✅ Passed</span>
                            @elseif($testCase->status === 'failed')
                                <span class="badge text-bg-danger">❌ Failed</span>
                            @else
                                <span class="badge text-bg-secondary">⏳ Pending</span>
                            @endif
                        </td>
                        <td data-col="last_run_at">
                            @if($testCase->last_run_at)
                                {{ $testCase->last_run_at->format('Y-m-d H:i:s') }}
                            @else
                                -
                            @endif
                        </td>
                        <td data-col="last_duration">
                            @if(isset($testCase->last_run_duration_ms) && $testCase->last_run_duration_ms !== null)
                                {{ number_format($testCase->last_run_duration_ms / 1000, 2) }}s
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">No test cases found.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(() => {
    const feedbackEl = document.getElementById('test-agent-feedback');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const checklistId = Number('{{ $checklist->id }}');

    const dateFormatter = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    function renderStatusBadge(status) {
        if (status === 'passed') {
            return '<span class="badge text-bg-success">✅ Passed</span>';
        }

        if (status === 'failed') {
            return '<span class="badge text-bg-danger">❌ Failed</span>';
        }

        return '<span class="badge text-bg-secondary">⏳ Pending</span>';
    }

    function setFeedback(type, message) {
        feedbackEl.innerHTML = `<div class="alert alert-${type} mb-0">${message}</div>`;
    }

    function clearFeedbackAfter(delayMs = 6000) {
        window.setTimeout(() => {
            feedbackEl.innerHTML = '';
        }, delayMs);
    }

    function updateTableRows(testRows, timestamp) {
        if (!Array.isArray(testRows)) {
            return;
        }

        const runTimestamp = timestamp ? new Date(timestamp) : new Date();
        const runLabel = Number.isNaN(runTimestamp.getTime())
            ? timestamp
            : dateFormatter.format(runTimestamp);

        testRows.forEach((test) => {
            const byId = test.id
                ? document.querySelector(`#test-results-table tr[data-test-id="${String(test.id)}"]`)
                : null;

            const byTitle = !byId && test.title
                ? document.querySelector(`#test-results-table tr[data-test-title="${String(test.title).trim().toLowerCase()}"]`)
                : null;

            const row = byId || byTitle;
            if (!row) {
                return;
            }

            const statusCell = row.querySelector('[data-col="status"]');
            const lastRunCell = row.querySelector('[data-col="last_run_at"]');

            if (statusCell) {
                statusCell.innerHTML = renderStatusBadge(test.status);
            }

            if (lastRunCell) {
                lastRunCell.textContent = runLabel || '-';
            }
        });
    }

    async function persistResults(resultsPayload) {
        const payload = {
            checklist_id: checklistId,
            results: {
                passed: resultsPayload.passed,
                failed: resultsPayload.failed,
                total: resultsPayload.tests?.length || 0,
                timestamp: resultsPayload.timestamp,
                tests: (resultsPayload.tests || []).map((test) => ({
                    id: test.id || null,
                    title: test.title,
                    status: test.status,
                    error: test.error || null,
                })),
            },
        };

        const response = await fetch('/api/test-results', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(payload),
        });

        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(body.error || body.message || 'Failed to update test results in Laravel.');
        }

        return body;
    }

    window.handleTestsComplete = async function handleTestsComplete(results) {
        setFeedback('info', 'Saving test results to the database...');
        updateTableRows(results.tests, results.timestamp);

        try {
            await persistResults(results);
            setFeedback(
                results.failed > 0 ? 'warning' : 'success',
                `Test run finished. Passed: ${results.passed}, Failed: ${results.failed}.`
            );
            clearFeedbackAfter();
        } catch (error) {
            setFeedback('danger', error.message || 'Failed to save test results.');
        }
    };

    window.handleTestsFailed = function handleTestsFailed(message) {
        setFeedback('danger', message || 'Test execution failed.');
    };
})();
</script>
</body>
</html>
