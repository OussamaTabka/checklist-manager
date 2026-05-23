import { defineStore } from 'pinia'
import { ref } from 'vue'
import { localizeMessage } from '@/lib/localization'

export const useToastStore = defineStore('toast', () => {
  const visible = ref(false)
  const message = ref('')
  const type = ref('success')
  const duration = ref(3500)
  let timeoutId = null

  function clear() {
    visible.value = false
    message.value = ''

    if (timeoutId) {
      clearTimeout(timeoutId)
      timeoutId = null
    }
  }

  function show(nextMessage, nextType = 'success', nextDuration = 3500) {
    if (timeoutId) {
      clearTimeout(timeoutId)
      timeoutId = null
    }

    message.value = localizeMessage(nextMessage)
    type.value = nextType
    duration.value = nextDuration
    visible.value = true

    timeoutId = setTimeout(() => {
      visible.value = false
      timeoutId = null
    }, duration.value)
  }

  function success(nextMessage, nextDuration = 3500) {
    show(nextMessage, 'success', nextDuration)
  }

  function error(nextMessage, nextDuration = 4500) {
    show(nextMessage, 'error', nextDuration)
  }

  function info(nextMessage, nextDuration = 3500) {
    show(nextMessage, 'info', nextDuration)
  }

  return {
    visible,
    message,
    type,
    duration,
    clear,
    show,
    success,
    error,
    info,
  }
})
