<script setup>
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { localizeError, tr } from '@/lib/localization'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useToastStore } from '@/stores/toast'

const auth = useAuthStore()
const settings = useSettingsStore()
const toast = useToastStore()
const route = useRoute()
const router = useRouter()

const form = reactive({
  token: typeof route.query.token === 'string' ? route.query.token : '',
  email: typeof route.query.email === 'string' ? route.query.email : '',
  password: '',
  password_confirmation: '',
})

const isSubmitting = ref(false)
const errorMessage = ref('')

const canSubmit = computed(() => Boolean(form.token && form.email))

async function onSubmit() {
  errorMessage.value = ''
  isSubmitting.value = true

  try {
    const response = await auth.setPassword({ ...form })
    toast.success(response?.message || tr('password_initialized_success', {}, settings.language))

    setTimeout(() => {
      router.push({
        name: 'login',
        query: {
          message: tr('password_initialized_login', {}, settings.language),
        },
      })
    }, 1000)
  } catch (error) {
    errorMessage.value = localizeError(error, 'error_generic', settings.language)
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <section class="page page-auth-md">
    <div class="card stack">
      <div>
        <h1>Initialiser mon mot de passe</h1>
        <p class="muted">Choisissez votre nouveau mot de passe pour activer votre compte IntelliTest.</p>
      </div>

      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

      <p v-if="form.email" class="muted">Compte concerne : <strong>{{ form.email }}</strong></p>

      <form class="stack" @submit.prevent="onSubmit">
        <div class="field">
          <label>Nouveau mot de passe</label>
          <input v-model="form.password" type="password" required minlength="8" autocomplete="new-password" />
        </div>

        <div class="field">
          <label>Confirmer le mot de passe</label>
          <input
            v-model="form.password_confirmation"
            type="password"
            required
            minlength="8"
            autocomplete="new-password"
          />
        </div>

        <button class="btn btn-primary" type="submit" :disabled="isSubmitting || !canSubmit">
          {{ isSubmitting ? 'Initialisation en cours...' : 'Initialiser mon mot de passe' }}
        </button>
      </form>

      <div>
        <RouterLink :to="{ name: 'login' }" class="muted">Retour a la connexion</RouterLink>
      </div>
    </div>
  </section>
</template>
