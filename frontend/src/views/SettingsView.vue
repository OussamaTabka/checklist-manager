<script setup>
import { ref, computed } from 'vue'
import { useSettingsStore } from '@/stores/settings'
import { t } from '@/lib/translations'
import { Moon, Globe, ArrowLeft, ChevronDown } from 'lucide-vue-next'
import { useRouter } from 'vue-router'

const router = useRouter()
const settingsStore = useSettingsStore()

const showNotification = ref(false)
const notificationMessage = ref('')
const expandedItem = ref('language')

const currentLanguageLabel = computed(() => {
  const lang = settingsStore.languages.find((item) => item.code === settingsStore.language)
  return lang ? `${lang.flag} ${lang.name}` : 'Français'
})

function isExpanded(item) {
  return expandedItem.value === item
}

function toggleItem(item) {
  expandedItem.value = expandedItem.value === item ? null : item
}

function showSavedMessage(message) {
  notificationMessage.value = message
  showNotification.value = true
  setTimeout(() => {
    showNotification.value = false
  }, 2000)
}

function changeLanguage(langCode) {
  settingsStore.setLanguage(langCode)
  showSavedMessage(t('msg.saved', settingsStore.language))
}

function toggleDarkMode() {
  settingsStore.toggleDarkMode()
  const message = settingsStore.darkMode
    ? t('settings.darkModeEnabled', settingsStore.language)
    : t('settings.darkModeDisabled', settingsStore.language)

  showSavedMessage(message)
}

function goBack() {
  router.back()
}
</script>

<template>
  <div class="min-h-screen dark:bg-gray-900 transition-colors duration-200">
    <div class="bg-white dark:bg-gray-800 shadow">
      <div class="max-w-7xl mx-auto px-4 py-6 flex items-center gap-4">
        <button
          @click="goBack"
          class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition"
        >
          <ArrowLeft :size="20" class="text-gray-700 dark:text-gray-300" />
        </button>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">
          {{ t('settings.title', settingsStore.language) }}
        </h1>
      </div>
    </div>

    <Transition name="slide">
      <div
        v-if="showNotification"
        class="fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50"
      >
        {{ notificationMessage }}
      </div>
    </Transition>

    <div class="max-w-2xl mx-auto px-4 py-8">
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-100 dark:border-gray-700 overflow-hidden">
        <div class="border-b border-gray-200 dark:border-gray-700">
          <button
            class="w-full flex items-center justify-between px-6 py-5 hover:bg-gray-50 dark:hover:bg-gray-700/40 transition"
            @click="toggleItem('language')"
          >
            <div class="flex items-center gap-3 text-left">
              <Globe :size="20" class="text-blue-600 dark:text-blue-400" />
              <div>
                <p class="font-semibold text-gray-900 dark:text-gray-100">
                  {{ t('settings.language', settingsStore.language) }}
                </p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                  {{ currentLanguageLabel }}
                </p>
              </div>
            </div>
            <ChevronDown
              :size="18"
              class="text-gray-500 dark:text-gray-400 transition-transform"
              :class="{ 'rotate-180': isExpanded('language') }"
            />
          </button>

          <Transition name="expand">
            <div v-if="isExpanded('language')" class="px-6 pb-5">
              <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-2">
                <button
                  v-for="lang in settingsStore.languages"
                  :key="lang.code"
                  @click="changeLanguage(lang.code)"
                  :class="{
                    'bg-blue-600 text-white': settingsStore.language === lang.code,
                    'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600': settingsStore.language !== lang.code,
                  }"
                  class="px-4 py-3 rounded-lg font-medium transition flex items-center justify-center gap-2"
                >
                  <span class="text-xl">{{ lang.flag }}</span>
                  <span>{{ lang.name }}</span>
                </button>
              </div>
            </div>
          </Transition>
        </div>

        <div>
          <button
            class="w-full flex items-center justify-between px-6 py-5 hover:bg-gray-50 dark:hover:bg-gray-700/40 transition"
            @click="toggleItem('darkMode')"
          >
            <div class="flex items-center gap-3 text-left">
              <Moon :size="20" class="text-orange-500" />
              <div>
                <p class="font-semibold text-gray-900 dark:text-gray-100">
                  {{ t('settings.darkMode', settingsStore.language) }}
                </p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                  {{ settingsStore.darkMode
                    ? t('settings.darkModeEnabled', settingsStore.language)
                    : t('settings.darkModeDisabled', settingsStore.language)
                  }}
                </p>
              </div>
            </div>
            <ChevronDown
              :size="18"
              class="text-gray-500 dark:text-gray-400 transition-transform"
              :class="{ 'rotate-180': isExpanded('darkMode') }"
            />
          </button>

          <Transition name="expand">
            <div v-if="isExpanded('darkMode')" class="px-6 pb-5">
              <div class="flex items-center justify-between rounded-lg bg-gray-50 dark:bg-gray-700/40 px-4 py-4">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-200">
                  {{ t('settings.darkMode', settingsStore.language) }}
                </p>

                <button
                  @click="toggleDarkMode"
                  :class="{
                    'bg-blue-600': settingsStore.darkMode,
                    'bg-gray-300 dark:bg-gray-600': !settingsStore.darkMode,
                  }"
                  class="relative inline-flex h-8 w-14 items-center rounded-full transition-colors"
                >
                  <span
                    :class="{
                      'translate-x-7': settingsStore.darkMode,
                      'translate-x-1': !settingsStore.darkMode,
                    }"
                    class="inline-block h-6 w-6 transform rounded-full bg-white transition-transform"
                  />
                </button>
              </div>
            </div>
          </Transition>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.slide-enter-active,
.slide-leave-active {
  transition: all 0.3s ease;
}

.slide-enter-from,
.slide-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

.expand-enter-active,
.expand-leave-active {
  transition: all 0.2s ease;
}

.expand-enter-from,
.expand-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
