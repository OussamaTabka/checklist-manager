#!/usr/bin/env node

const { chromium } = require('playwright')

function classifyError(message) {
  const lower = String(message || '').toLowerCase()

  if (lower.includes('err_network_access_denied')) {
    return 'network_access_denied'
  }

  if (lower.includes('err_name_not_resolved') || lower.includes('enotfound') || lower.includes('dns')) {
    return 'dns'
  }

  if (lower.includes('cert') || lower.includes('ssl')) {
    return 'certificate'
  }

  if (lower.includes('timeout')) {
    return 'timeout'
  }

  if (lower.includes('net::') || lower.includes('econnrefused') || lower.includes('socket hang up')) {
    return 'network'
  }

  return 'unknown'
}

async function main() {
  const url = process.argv[2]
  if (!url) {
    console.error('Usage: node scripts/diagnose-url.cjs <url>')
    process.exit(2)
  }

  const browser = await chromium.launch({
    headless: true,
  })

  const page = await browser.newPage()

  try {
    const response = await page.goto(url, {
      timeout: 30000,
      waitUntil: 'domcontentloaded',
    })

    const payload = {
      url,
      reachable: true,
      status: response ? response.status() : null,
      title: await page.title(),
      error_type: null,
      error_message: null,
    }

    console.log(JSON.stringify(payload, null, 2))
    process.exit(0)
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error)
    const payload = {
      url,
      reachable: false,
      status: null,
      title: null,
      error_type: classifyError(message),
      error_message: message,
    }

    console.log(JSON.stringify(payload, null, 2))
    process.exit(1)
  } finally {
    await browser.close()
  }
}

if (require.main === module) {
  main().catch((error) => {
    const message = error instanceof Error ? error.message : String(error)
    console.error(JSON.stringify({
      url: process.argv[2] ?? null,
      reachable: false,
      status: null,
      title: null,
      error_type: 'unknown',
      error_message: message,
    }, null, 2))
    process.exit(1)
  })
}

module.exports = {
  classifyError,
}
