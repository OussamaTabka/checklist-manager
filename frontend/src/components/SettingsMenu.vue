<script setup>
import { ref, computed } from 'vue'
import { useSettingsStore } from '@/stores/settings'
import { useRouter } from 'vue-router'
import { Settings, Moon, Sun, Globe } from 'lucide-vue-next'
import { t } from '@/lib/translations'

const router = useRouter()
const settingsStore = useSettingsStore()
const isOpen = ref(false)

const currentLanguage = computed(() => {
  const lang = settingsStore.languages.find(l => l.code === settingsStore.language)
  return lang?.flag || '🇫🇷'
})

function openSettings() {
  router.push({ name: 'settings' })
  isOpen.value = false
}

function toggleDarkMode() {
  settingsStore.toggleDarkMode()
}

function changeLanguage(langCode) {
  settingsStore.setLanguage(langCode)
}

function closeMenu() {
  isOpen.value = false
}
</script>

<template>
  <div class="relative">
    <!-- Settings Button -->
    <button
      @click="isOpen = !isOpen"
      class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition relative group"
      :title="t('settings.title', settingsStore.language)"
    >
      <Settings :size="20" class="text-gray-700 dark:text-gray-300" />
      <span class="absolute left-0 bottom-full mb-2 bg-gray-900 dark:bg-gray-700 text-white text-xs py-1 px-2 rounded whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
        {{ t('settings.title', settingsStore.language) }}
      </span>
    </button>

    <!-- Dropdown Menu -->
    <Transition name="menu">
      <div
        v-if="isOpen"
        @click:outside="closeMenu"
        class="absolute right-0 mt-2 w-56 bg-white dark:bg-gray-800 rounded-lg shadow-xl z-50 border border-gray-200 dark:border-gray-700 overflow-hidden"
      >
        <!-- Language Section -->
        <div class="p-3 border-b border-gray-200 dark:border-gray-700">
          <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider flex items-center gap-1">
            <Globe :size="14" />
            {{ t('settings.language', settingsStore.language) }}
          </p>
          <div class="mt-2 flex gap-2">
            <button
              v-for="lang in settingsStore.languages"
              :key="lang.code"
              @click="changeLanguage(lang.code); closeMenu()"
              :class="{
                'bg-blue-600 text-white': settingsStore.language === lang.code,
                'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600': settingsStore.language !== lang.code,
              }"
              class="flex-1 px-2 py-1 rounded text-sm font-medium transition"
              :title="lang.name"
            >
              {{ lang.flag }}
            </button>
          </div>
        </div>

        <!-- Dark Mode Section -->
        <div class="p-3 border-b border-gray-200 dark:border-gray-700">
          <button
            @click="toggleDarkMode"
            class="w-full flex items-center justify-between p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded transition"
          >
            <span class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
              <component :is="settingsStore.darkMode ? Moon : Sun" :size="16" />
              {{ settingsStore.darkMode ? 'Dark Mode' : 'Light Mode' }}
            </span>
            <div
              :class="{
                'bg-blue-600': settingsStore.darkMode,
                'bg-gray-300 dark:bg-gray-600': !settingsStore.darkMode,
              }"
              class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors"
            >
              <span
                :class="{
                  'translate-x-5': settingsStore.darkMode,
                  'translate-x-0.5': !settingsStore.darkMode,
                }"
                class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
              />
            </div>
          </button>
        </div>

        <!-- Settings Link -->
        <button
          @click="openSettings"
          class="w-full px-4 py-2 text-left text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition flex items-center gap-2"
        >
          <Settings :size="16" />
          {{ t('settings.title', settingsStore.language) }}
        </button>
      </div>
    </Transition>

    <!-- Overlay for closing -->
    <Transition name="fade">
      <div
        v-if="isOpen"
        @click="closeMenu"
        class="fixed inset-0 z-40"
      />
    </Transition>
  </div>
</template>

<style scoped>
.menu-enter-active,
.menu-leave-active {
  transition: all 0.2s ease;
}

.menu-enter-from,
.menu-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
