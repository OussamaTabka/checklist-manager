<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiRequest, ensureCsrfCookie, withQuery } from '@/lib/api'
import { localizeError, tr } from '@/lib/localization'
import { useSettingsStore } from '@/stores/settings'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const settings = useSettingsStore()
const toast = useToastStore()

const selector = ref(typeof route.query.selector === 'string' ? route.query.selector : '')
const token = ref(typeof route.query.token === 'string' ? route.query.token : '')

const validating = ref(true)
const isSubmitting = ref(false)
const errorMessage = ref('')
const invitationInfo = ref(null)

const form = reactive({
  password: '',
  password_confirmation: '',
})

async function validateInvitationLink() {
  errorMessage.value = ''
  validating.value = true

  if (!selector.value || !token.value) {
    validating.value = false
    errorMessage.value = localizeError({ message: 'Invalid invitation link.' }, 'error_generic', settings.language)
    return
  }

  try {
    const data = await apiRequest(withQuery('/invitations/validate', {
      selector: selector.value,
      token: token.value,
    }))

    invitationInfo.value = data?.user || null
  } catch (error) {
    errorMessage.value = localizeError(error, 'error_generic', settings.language)
  } finally {
    validating.value = false
  }
}

async function onSubmit() {
  errorMessage.value = ''
  isSubmitting.value = true

  try {
    await ensureCsrfCookie()

    const data = await apiRequest('/invitations/accept', {
      method: 'POST',
      body: {
        selector: selector.value,
        token: token.value,
        password: form.password,
        password_confirmation: form.password_confirmation,
      },
    })

    toast.success(data?.message || tr('account_activated_success', {}, settings.language))

    setTimeout(() => {
      router.push({ name: 'login' })
    }, 1200)
  } catch (error) {
    errorMessage.value = localizeError(error, 'error_generic', settings.language)
  } finally {
    isSubmitting.value = false
  }
}

onMounted(async () => {
  await validateInvitationLink()
})
</script>

<template>
  <section class="page page-auth-lg">
    <div class="card stack">
      <div>
        <h1>Set up your account</h1>
        <p class="muted">Choose your password to activate your account.</p>
      </div>

      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

      <p v-if="validating" class="muted">Validating invitation link...</p>

      <div v-else-if="invitationInfo" class="stack">
        <p class="muted">
          Invitation for <strong>{{ invitationInfo.name }}</strong> ({{ invitationInfo.email }})
        </p>
        <p class="muted">Role: {{ invitationInfo.role || 'testeur' }}</p>

        <form class="stack" @submit.prevent="onSubmit">
          <div class="field">
            <label>New password</label>
            <input v-model="form.password" type="password" minlength="8" required autocomplete="new-password" />
          </div>

          <div class="field">
            <label>Confirm password</label>
            <input
              v-model="form.password_confirmation"
              type="password"
              minlength="8"
              required
              autocomplete="new-password"
            />
          </div>

          <button class="btn btn-primary" type="submit" :disabled="isSubmitting">
            {{ isSubmitting ? 'Activating...' : 'Activate account' }}
          </button>
        </form>
      </div>

      <div>
        <RouterLink :to="{ name: 'login' }" class="muted">Back to sign in</RouterLink>
      </div>
    </div>
  </section>
</template>
