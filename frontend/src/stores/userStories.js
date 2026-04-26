import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { apiRequest } from '@/lib/api'

export const useUserStoriesStore = defineStore('userStories', () => {
  const stories = ref([])
  const currentStory = ref(null)
  const loading = ref(false)
  const error = ref(null)
  const generatorStatus = ref({
    available: [],
    current: null,
    error: null,
  })
  const suggestionsByStoryId = ref({})
  const agentResultsByStoryId = ref({})
  const isGenerating = ref(false)

  const groupedByStatus = computed(() => {
    const groups = {
      backlog: [],
      in_progress: [],
      ready_for_test: [],
      completed: [],
    }
    
    stories.value.forEach(story => {
      if (groups[story.status]) {
        groups[story.status].push(story)
      }
    })
    
    return groups
  })

  const byPriority = computed(() => ({
    critical: stories.value.filter(s => s.priority === 'critical'),
    high: stories.value.filter(s => s.priority === 'high'),
    medium: stories.value.filter(s => s.priority === 'medium'),
    low: stories.value.filter(s => s.priority === 'low'),
  }))

  async function fetchStories(projectId) {
    loading.value = true
    error.value = null
    
    try {
      const response = await apiRequest(`/projects/${projectId}/user-stories`)
      stories.value = response || []
    } catch (err) {
      error.value = err.message || 'Failed to fetch user stories'
      stories.value = []
    } finally {
      loading.value = false
    }
  }

  async function fetchStory(projectId, storyId) {
    loading.value = true
    error.value = null
    
    try {
      const response = await apiRequest(`/projects/${projectId}/user-stories/${storyId}`)
      currentStory.value = response
      return response
    } catch (err) {
      error.value = err.message || 'Failed to fetch user story'
      currentStory.value = null
    } finally {
      loading.value = false
    }
  }

  async function createStory(projectId, payload) {
    loading.value = true
    error.value = null
    
    try {
      const response = await apiRequest(`/projects/${projectId}/user-stories`, {
        method: 'POST',
        body: payload,
      })
      stories.value.push(response)
      return response
    } catch (err) {
      error.value = err.message || 'Failed to create user story'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateStory(projectId, storyId, payload) {
    loading.value = true
    error.value = null
    
    try {
      const response = await apiRequest(`/projects/${projectId}/user-stories/${storyId}`, {
        method: 'PUT',
        body: payload,
      })
      const index = stories.value.findIndex(s => s.id === storyId)
      if (index !== -1) {
        stories.value[index] = response
      }
      if (currentStory.value?.id === storyId) {
        currentStory.value = response
      }
      return response
    } catch (err) {
      error.value = err.message || 'Failed to update user story'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function deleteStory(projectId, storyId) {
    loading.value = true
    error.value = null
    
    try {
      await apiRequest(`/projects/${projectId}/user-stories/${storyId}`, {
        method: 'DELETE',
      })
      stories.value = stories.value.filter(s => s.id !== storyId)
      if (currentStory.value?.id === storyId) {
        currentStory.value = null
      }
    } catch (err) {
      error.value = err.message || 'Failed to delete user story'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function generateChecklistFromLLM(projectId, storyId) {
    return generateChecklistWithAgent(projectId, storyId)
  }

  async function generateChecklistWithAgent(projectId, storyId) {
    isGenerating.value = true
    error.value = null
    
    try {
      const response = await apiRequest(
        `/projects/${projectId}/user-stories/${storyId}/agent/generate-checklist`,
        { method: 'POST', body: {} }
      )

      if (response?.suggestions) {
        suggestionsByStoryId.value = {
          ...suggestionsByStoryId.value,
          [storyId]: response.suggestions,
        }
      }

      agentResultsByStoryId.value = {
        ...agentResultsByStoryId.value,
        [storyId]: {
          decision: response?.decision || null,
          reuse_summary: response?.reuse_summary || null,
          checklist: response?.checklist || null,
        },
      }

      const updatedStory = response.user_story || null

      if (currentStory.value?.id === storyId && updatedStory) {
        currentStory.value = updatedStory
      }

      const index = stories.value.findIndex(s => s.id === storyId)
      if (index !== -1 && updatedStory) {
        stories.value[index] = {
          ...stories.value[index],
          ...updatedStory,
        }
      }

      return response
    } catch (err) {
      error.value = err.message || 'Failed to generate checklist'
      throw err
    } finally {
      isGenerating.value = false
    }
  }

  async function fetchGeneratorStatus(projectId) {
    try {
      const response = await apiRequest(
        `/projects/${projectId}/user-stories/generators/status`
      )
      generatorStatus.value = response || {
        available: [],
        current: null,
        error: null,
      }
      return generatorStatus.value
    } catch (err) {
      generatorStatus.value = {
        available: [],
        current: null,
        error: err.message || 'Failed to fetch generator status',
      }
    }
  }

  async function attachChecklist(projectId, storyId, checklistId) {
    loading.value = true
    error.value = null
    
    try {
      const response = await apiRequest(
        `/projects/${projectId}/user-stories/${storyId}/attach-checklist`,
        {
          method: 'POST',
          body: { checklist_id: checklistId },
        }
      )
      
      if (currentStory.value?.id === storyId) {
        currentStory.value = response
      }
      
      const index = stories.value.findIndex(s => s.id === storyId)
      if (index !== -1) {
        stories.value[index] = response
      }
      
      return response
    } catch (err) {
      error.value = err.message || 'Failed to attach checklist'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function detachChecklist(projectId, storyId, checklistId) {
    loading.value = true
    error.value = null
    
    try {
      const response = await apiRequest(
        `/projects/${projectId}/user-stories/${storyId}/checklists/${checklistId}`,
        { method: 'DELETE' }
      )
      
      if (currentStory.value?.id === storyId) {
        currentStory.value = response
      }
      
      const index = stories.value.findIndex(s => s.id === storyId)
      if (index !== -1) {
        stories.value[index] = response
      }
      
      return response
    } catch (err) {
      error.value = err.message || 'Failed to detach checklist'
      throw err
    } finally {
      loading.value = false
    }
  }

  function resetCurrentStory() {
    currentStory.value = null
  }

  async function fetchChecklistSuggestions(projectId, storyId) {
    try {
      const response = await apiRequest(
        `/projects/${projectId}/user-stories/${storyId}/suggest-checklists`
      )

      suggestionsByStoryId.value = {
        ...suggestionsByStoryId.value,
        [storyId]: response,
      }

      return response
    } catch (err) {
      error.value = err.message || 'Failed to fetch checklist suggestions'
      throw err
    }
  }

  function clearError() {
    error.value = null
  }

  return {
    // State
    stories,
    currentStory,
    loading,
    error,
    isGenerating,
    generatorStatus,
    suggestionsByStoryId,
    agentResultsByStoryId,
    
    // Computed
    groupedByStatus,
    byPriority,
    
    // Actions
    fetchStories,
    fetchStory,
    createStory,
    updateStory,
    deleteStory,
    generateChecklistFromLLM,
    generateChecklistWithAgent,
    fetchChecklistSuggestions,
    fetchGeneratorStatus,
    attachChecklist,
    detachChecklist,
    resetCurrentStory,
    clearError,
  }
})
