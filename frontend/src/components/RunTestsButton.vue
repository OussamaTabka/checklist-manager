<template>
  <div class="run-tests-container">
    <button
      @click="handleRunTests"
      :disabled="isLoading || !testCases || testCases.length === 0"
      :class="['btn', isLoading ? 'btn-secondary' : 'btn-primary']"
    >
      <span v-if="isLoading" class="spinner-border spinner-border-sm me-2"></span>
      {{ isLoading ? 'Exécution des tests...' : 'Lancer les tests' }}
    </button>

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

    <div v-if="showResults && !error && results" class="alert alert-success mt-3" role="alert">
      <h5 class="alert-heading">Tests exécutés avec succès</h5>
      <hr />
      <p><strong>Réussis :</strong> {{ results.passed }}</p>
      <p><strong>Échoués :</strong> {{ results.failed }}</p>
      <p v-if="results.total" class="mb-0"><strong>Total :</strong> {{ results.total }}</p>
    </div>

    <div v-if="error" class="alert alert-danger mt-3" role="alert">
      <h5 class="alert-heading">Erreur d’exécution</h5>
      <p class="mb-0">{{ error }}</p>
      <button
        @click="clearError"
        type="button"
        class="btn-close"
        aria-label="Fermer"
      ></button>
    </div>

    <div v-if="showHealthWarning" class="alert alert-warning mt-3" role="alert">
      <small>
        Le service d’exécution ne répond pas. Vérifiez qu’il est bien démarré sur
        {{ testAgentUrl }}
      </small>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { submitTests, getJobStatus, checkHealth } from '@/lib/testAgentService'
import { apiRequest } from '@/lib/api'

const props = defineProps({
  testCases: {
    type: Array,
    required: true,
  },
  checklistId: {
    type: [String, Number],
    required: true,
  },
})

const emit = defineEmits(['tests-complete', 'tests-failed'])

const isLoading = ref(false)
const progress = ref(0)
const error = ref('')
const results = ref(null)
const showResults = ref(false)
const showHealthWarning = ref(false)
const jobId = ref(null)
const testAgentUrl = import.meta.env.VITE_TEST_AGENT_URL || 'http://localhost:8000'

const hasTests = computed(() => {
  return props.testCases && props.testCases.length > 0
})

onMounted(async () => {
  const isHealthy = await checkHealth()
  showHealthWarning.value = !isHealthy
})

async function handleRunTests() {
  if (!hasTests.value) {
    error.value = 'Aucun cas de test disponible.'
    return
  }

  isLoading.value = true
  error.value = ''
  results.value = null
  showResults.value = false
  progress.value = 10

  try {
    const submitResponse = await submitTests(props.testCases, props.checklistId)
    jobId.value = submitResponse.job_id
    progress.value = 20

    const jobStatus = await pollUntilComplete(jobId.value)
    progress.value = 90

    const testResults = {
      passed: jobStatus.results?.passed || 0,
      failed: jobStatus.results?.failed || 0,
      total: (jobStatus.results?.passed || 0) + (jobStatus.results?.failed || 0),
      tests: jobStatus.results?.tests || [],
      timestamp: new Date().toISOString(),
    }

    results.value = testResults
    progress.value = 100
    showResults.value = true

    await saveResultsToBackend(testResults)

    emit('tests-complete', testResults)
  } catch (err) {
    error.value = err.message || 'Impossible d’exécuter les tests.'
    emit('tests-failed', error.value)
  } finally {
    isLoading.value = false
    setTimeout(() => {
      progress.value = 0
    }, 2000)
  }
}

async function pollUntilComplete(id) {
  const maxWait = 600000
  const pollInterval = 2000
  const startTime = Date.now()

  while (true) {
    const elapsedTime = Date.now() - startTime

    if (elapsedTime > maxWait) {
      throw new Error('L’exécution des tests a dépassé 10 minutes.')
    }

    try {
      const jobStatus = await getJobStatus(id)
      const progressPercent = Math.min(Math.round((elapsedTime / maxWait) * 80 + 20), 95)
      progress.value = progressPercent

      if (jobStatus.status === 'completed') {
        return jobStatus
      }

      if (jobStatus.status === 'failed' || jobStatus.error) {
        throw new Error(jobStatus.error || 'L’exécution a échoué.')
      }

      await new Promise((resolve) => setTimeout(resolve, pollInterval))
    } catch (err) {
      if (err.message.includes('Job not found')) {
        throw err
      }
      await new Promise((resolve) => setTimeout(resolve, pollInterval))
    }
  }
}

async function saveResultsToBackend(testResults) {
  try {
    const payload = {
      checklist_id: props.checklistId,
      results: testResults,
    }

    const response = await apiRequest('POST', '/api/update-test-results', payload)
    return response
  } catch {
    return null
  }
}

function clearError() {
  error.value = ''
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
