import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { initRuntimeTranslations } from '@/lib/runtimeTranslations'

import App from './App.vue'
import router from './router'
import './styles.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

const auth = useAuthStore(pinia)
const settings = useSettingsStore(pinia)

if (typeof window !== 'undefined') {
  settings.loadSettings()
	initRuntimeTranslations(pinia)
}

if (typeof window !== 'undefined') {
	window.addEventListener('auth:unauthorized', async () => {
		auth.clear()

		if (router.currentRoute.value.name !== 'login') {
			await router.push({ name: 'login' })
		}
	})
}

app.mount('#app')
