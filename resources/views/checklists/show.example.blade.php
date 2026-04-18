{{-- Example Blade Template for Checklist Show View --}}
{{-- File Location: resources/views/checklists/show.blade.php --}}

@extends('layouts.app')

@section('content')
<div class="container py-4">
  <div class="row">
    <div class="col-lg-8">
      {{-- Checklist Header --}}
      <div class="card mb-4">
        <div class="card-header bg-primary text-white">
          <h2 class="mb-0">{{ $checklist->title }}</h2>
        </div>
        <div class="card-body">
          <p class="text-muted">{{ $checklist->description }}</p>
          <small class="text-muted">
            Created: {{ $checklist->created_at->format('M d, Y') }}
          </small>
        </div>
      </div>

      {{-- Test Cases Section --}}
      <div class="card mb-4">
        <div class="card-header">
          <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Test Cases ({{ count($testCases) }})</h4>
            <a href="{{ route('checklists.add-test-case', $checklist->id) }}" class="btn btn-sm btn-success">
              + Add Test Case
            </a>
          </div>
        </div>
        <div class="card-body">
          {{-- Vue Application Container --}}
          {{-- This div is where Vue components will be mounted --}}
          <div id="app">
            {{-- Run Tests Button Component --}}
            {{-- Props:
                - :test-cases: Array of test case objects from controller
                - :checklist-id: ID of the current checklist
                - @tests-complete: Event when tests successfully complete
                - @tests-failed: Event when tests fail
            --}}
            <run-tests-button 
              :test-cases="@json($testCases)"
              :checklist-id="{{ $checklist->id }}"
              @tests-complete="handleTestsComplete"
              @tests-failed="handleTestsFailed"
            />
          </div>

          {{-- Test Cases Table --}}
          <div class="table-responsive mt-4">
            @if(count($testCases) > 0)
              <table class="table table-hover">
                <thead class="table-light">
                  <tr>
                    <th>Test Name</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Last Run</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach($testCases as $testCase)
                    <tr>
                      <td>
                        <strong>{{ $testCase->title }}</strong>
                      </td>
                      <td class="text-muted small">
                        {{ Str::limit($testCase->description, 50) }}
                      </td>
                      <td>
                        @if($testCase->status === 'passed')
                          <span class="badge bg-success">✅ Passed</span>
                        @elseif($testCase->status === 'failed')
                          <span class="badge bg-danger">❌ Failed</span>
                        @else
                          <span class="badge bg-secondary">⏳ Pending</span>
                        @endif
                      </td>
                      <td>
                        @if($testCase->last_run_at)
                          <small class="text-muted">
                            {{ $testCase->last_run_at->diffForHumans() }}
                          </small>
                        @else
                          <small class="text-muted">Never</small>
                        @endif
                      </td>
                      <td>
                        <a href="{{ route('checklists.edit-test-case', [$checklist->id, $testCase->id]) }}" 
                           class="btn btn-sm btn-outline-primary">
                          Edit
                        </a>
                        <form action="{{ route('checklists.delete-test-case', [$checklist->id, $testCase->id]) }}" 
                              method="POST" style="display: inline;">
                          @csrf
                          @method('DELETE')
                          <button type="submit" class="btn btn-sm btn-outline-danger" 
                                  onclick="return confirm('Delete this test case?')">
                            Delete
                          </button>
                        </form>
                      </td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            @else
              <div class="alert alert-info">
                <strong>ℹ️ No test cases yet.</strong> 
                <a href="{{ route('checklists.add-test-case', $checklist->id) }}">Add your first test case</a>
              </div>
            @endif
          </div>
        </div>
      </div>

      {{-- Checklist Items Section --}}
      <div class="card">
        <div class="card-header">
          <div class="d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Checklist Items</h4>
            <a href="{{ route('checklists.add-item', $checklist->id) }}" class="btn btn-sm btn-success">
              + Add Item
            </a>
          </div>
        </div>
        <div class="card-body">
          {{-- Your existing checklist items content --}}
        </div>
      </div>
    </div>

    {{-- Sidebar --}}
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">📊 Statistics</h5>
        </div>
        <div class="card-body">
          <div class="mb-3">
            <small class="text-muted">Total Test Cases</small>
            <p class="h4">{{ count($testCases) }}</p>
          </div>
          <div class="mb-3">
            <small class="text-muted">Passed</small>
            <p class="h4 text-success">
              {{ $testCases->where('status', 'passed')->count() }}
            </p>
          </div>
          <div class="mb-3">
            <small class="text-muted">Failed</small>
            <p class="h4 text-danger">
              {{ $testCases->where('status', 'failed')->count() }}
            </p>
          </div>
          <div>
            <small class="text-muted">Pending</small>
            <p class="h4 text-secondary">
              {{ $testCases->where('status', 'pending')->count() }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- Include Vue.js application --}}
<div id="app-container"></div>
@vite(['resources/js/app.js'])

{{-- Inline script for handling test result events from Vue component --}}
<script>
(function() {
  'use strict';

  /**
   * Handle successful test completion
   * Called when RunTestsButton emits 'tests-complete' event
   */
  window.handleTestsComplete = function(results) {
    console.log('✅ Tests completed successfully!', results);

    // Show success notification to user
    showNotification('success', `Tests completed! ${results.passed} passed, ${results.failed} failed.`);

    // Send results to server to update database
    updateTestResults({
      checklist_id: {{ $checklist->id }},
      results: results
    });

    // Optional: Auto-reload page to show updated test statuses
    setTimeout(() => {
      location.reload();
    }, 2000);
  };

  /**
   * Handle test execution failure
   * Called when RunTestsButton emits 'tests-failed' event
   */
  window.handleTestsFailed = function(errorMessage) {
    console.error('❌ Test execution failed:', errorMessage);

    // Show error notification
    showNotification('danger', `Test execution failed: ${errorMessage}`);
  };

  /**
   * Send test results to Laravel backend
   * Updates database with test results and statuses
   */
  function updateTestResults(data) {
    fetch('{{ route('api.update-test-results') }}', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
      },
      body: JSON.stringify(data),
    })
      .then(response => response.json())
      .then(data => {
        console.log('📊 Server response:', data);
        if (data.success) {
          console.log('✅ Test results saved to database');
        } else {
          console.error('❌ Failed to save results:', data.error);
        }
      })
      .catch(error => {
        console.error('❌ Error updating test results:', error);
        showNotification('danger', 'Failed to save results to database');
      });
  }

  /**
   * Show notification toast/alert
   */
  function showNotification(type, message) {
    // Bootstrap Toast approach (requires Bootstrap 5)
    const alertHtml = `
      <div class="alert alert-${type} alert-dismissible fade show" role="alert">
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    `;

    const alertContainer = document.createElement('div');
    alertContainer.className = 'position-fixed top-0 end-0 p-3';
    alertContainer.style.zIndex = '9999';
    alertContainer.innerHTML = alertHtml;
    
    document.body.appendChild(alertContainer);

    // Auto-remove after 5 seconds
    setTimeout(() => {
      alertContainer.remove();
    }, 5000);
  }

  console.log('✅ Test event handlers initialized');
})();
</script>
