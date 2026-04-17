import express from 'express'
import { chromium } from 'playwright'

const app = express()

app.use(express.json({ limit: '1mb' }))

app.use((err, _req, res, next) => {
  if (err instanceof SyntaxError && 'body' in err) {
    return res.status(400).json({ error: 'Invalid JSON payload.' })
  }

  return next(err)
})

const PORT = normalizePort(process.env.PORT) ?? 3001
const NAVIGATION_TIMEOUT_MS = normalizeInt(process.env.NAVIGATION_TIMEOUT_MS) ?? 30_000
const ACTION_TIMEOUT_MS = normalizeInt(process.env.ACTION_TIMEOUT_MS) ?? 10_000

let browserPromise = null

function normalizePort(value) {
  if (value === undefined || value === null || value === '') return null
  const port = Number(value)
  if (!Number.isInteger(port) || port <= 0 || port > 65535) return null
  return port
}

function normalizeInt(value) {
  if (value === undefined || value === null || value === '') return null
  const n = Number(value)
  if (!Number.isFinite(n) || n <= 0) return null
  return Math.floor(n)
}

function parseAndValidateUrl(rawUrl) {
  if (typeof rawUrl !== 'string') {
    return { ok: false, error: 'Field "url" must be a string.' }
  }

  const trimmed = rawUrl.trim()
  if (!trimmed) {
    return { ok: false, error: 'Field "url" is required.' }
  }

  if (trimmed.length > 2048) {
    return { ok: false, error: 'URL is too long (max 2048 characters).' }
  }

  let parsed
  try {
    parsed = new URL(trimmed)
  } catch {
    return { ok: false, error: 'Invalid URL.' }
  }

  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
    return { ok: false, error: 'URL must start with http:// or https://.' }
  }

  if (parsed.username || parsed.password) {
    return { ok: false, error: 'URL must not include username or password.' }
  }

  return { ok: true, url: parsed.toString() }
}

async function getBrowser() {
  if (!browserPromise) {
    browserPromise = chromium.launch({ headless: true })
  }

  try {
    return await browserPromise
  } catch (error) {
    browserPromise = null
    throw error
  }
}

async function analyzePage(url) {
  const browser = await getBrowser()
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    userAgent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
  })

  try {
    const page = await context.newPage()
    page.setDefaultNavigationTimeout(NAVIGATION_TIMEOUT_MS)
    page.setDefaultTimeout(ACTION_TIMEOUT_MS)

    await page.goto(url, { waitUntil: 'domcontentloaded' })

    try {
      await page.waitForLoadState('networkidle', { timeout: Math.min(5_000, NAVIGATION_TIMEOUT_MS) })
    } catch {
    }

    const data = await page.evaluate(() => {
      const title = document.title || ''

      const buttonTags = Array.from(document.querySelectorAll('button'))
        .map((btn) => (btn.innerText || btn.textContent || '').trim())
        .filter(Boolean)

      const inputButtons = Array.from(
        document.querySelectorAll('input[type="button"], input[type="submit"], input[type="reset"]'),
      )
        .map((input) => (input.getAttribute('value') || input.getAttribute('aria-label') || '').trim())
        .filter(Boolean)

      const buttons = [...buttonTags, ...inputButtons]

      const inputs = Array.from(document.querySelectorAll('input')).map((input) => {
        const typeAttr = input.getAttribute('type')
        return {
          name: input.getAttribute('name') || '',
          type: typeAttr ? typeAttr.toLowerCase() : 'text',
        }
      })

      const forms = Array.from(document.querySelectorAll('form')).map((form) => {
        const methodAttr = form.getAttribute('method')
        return {
          id: form.getAttribute('id') || '',
          name: form.getAttribute('name') || '',
          action: form.getAttribute('action') || '',
          method: (methodAttr || 'GET').toUpperCase(),
        }
      })

      const links = Array.from(document.querySelectorAll('a'))
        .map((a) => {
          const hrefAttr = a.getAttribute('href')
          if (!hrefAttr) return null
          const text = (a.innerText || a.textContent || '').trim()
          const href = a.href || hrefAttr
          return { text, href }
        })
        .filter(Boolean)

      return { title, buttons, inputs, forms, links }
    })

    return data
  } finally {
    await context.close()
  }
}

app.post('/analyze', async (req, res) => {
  const parsed = parseAndValidateUrl(req.body?.url)
  if (!parsed.ok) {
    return res.status(422).json({ error: parsed.error })
  }

  try {
    const result = await analyzePage(parsed.url)
    return res.json(result)
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown error'

    const payload = { error: 'Playwright analysis failed.' }
    if (process.env.NODE_ENV !== 'production') {
      payload.details = message
    }

    return res.status(500).json(payload)
  }
})

app.listen(PORT, () => {
  console.log(`Playwright service listening on http://localhost:${PORT}`)
})

process.on('SIGINT', async () => {
  try {
    const browser = await browserPromise
    await browser?.close()
  } finally {
    process.exit(0)
  }
})
