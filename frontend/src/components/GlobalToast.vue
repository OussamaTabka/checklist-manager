<script setup>
import { computed } from 'vue'
import { AlertCircle, CheckCircle2 } from 'lucide-vue-next'
import { localizeMessage } from '@/lib/localization'
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()
const visibleMessage = computed(() => localizeMessage(toast.message))
</script>

<template>
  <Teleport to="body">
    <transition name="global-toast">
      <div v-if="toast.visible" :class="['global-toast', `global-toast-${toast.type}`]">
        <CheckCircle2 v-if="toast.type === 'success'" :size="18" />
        <AlertCircle v-else :size="18" />
        <span>{{ visibleMessage }}</span>
      </div>
    </transition>
  </Teleport>
</template>

<style scoped>
.global-toast-enter-active,
.global-toast-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.global-toast-enter-from,
.global-toast-leave-to {
  opacity: 0;
  transform: translate(-50%, -8px);
}

.global-toast {
  position: fixed;
  top: 1rem;
  left: 50%;
  transform: translateX(-50%);
  z-index: 9999;
  display: flex;
  align-items: center;
  gap: 0.65rem;
  width: fit-content;
  max-width: min(calc(100vw - 2rem), 36rem);
  padding: 0.9rem 1rem;
  border-radius: 0.95rem;
  border: 1px solid rgba(148, 163, 184, 0.18);
  box-shadow: 0 18px 38px -28px rgba(15, 23, 42, 0.35);
  backdrop-filter: blur(12px);
}

.global-toast-success {
  color: #166534;
  background: rgba(236, 253, 245, 0.98);
  border-color: #86efac;
}

.global-toast-error {
  color: #b42318;
  background: rgba(255, 241, 242, 0.98);
  border-color: #fda4af;
}

.global-toast-info {
  color: #0b3b66;
  background: rgba(219, 234, 254, 0.98);
  border-color: #60a5fa;
  font-weight: 600;
}

@media (max-width: 960px) {
  .global-toast {
    left: 1rem;
    right: 1rem;
    transform: none;
    width: auto;
    max-width: none;
  }

  .global-toast-enter-from,
  .global-toast-leave-to {
    transform: translateY(-8px);
  }
}
</style>
