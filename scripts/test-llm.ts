import 'dotenv/config'

async function testLLM() {
  const apiKey = process.env.OPENAI_API_KEY || process.env.CODEX_API_KEY
  const baseUrl = process.env.OPENAI_BASE_URL || 'https://codex.sale/v1/chat/completions'
  const model = process.env.CODEX_MODEL || 'gpt-5.4'

  if (!apiKey) {
    console.error('❌ OPENAI_API_KEY not found')
    process.exit(1)
  }

  const apiKeyPreview = `${apiKey.substring(0, 4)}...${apiKey.substring(apiKey.length - 4)}`
  const startTime = Date.now()

  console.log('🧪 LLM Connectivity Test')
  console.log('========================')
  console.log(`URL: ${baseUrl}`)
  console.log(`Model: ${model}`)
  console.log(`API Key: ${apiKeyPreview}`)
  console.log('')

  try {
    const response = await fetch(baseUrl, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${apiKey}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        model,
        messages: [
          { role: 'user', content: "Say 'pong' and nothing else." }
        ],
        max_tokens: 10,
        temperature: 0.1,
      }),
    })

    const duration = Date.now() - startTime
    const responseText = await response.text()

    console.log(`⏱️  Response time: ${duration}ms`)
    console.log(`HTTP Status: ${response.status}`)
    console.log('')

    if (!response.ok) {
      console.error(`❌ HTTP Error ${response.status}`)
      console.error('Response body:')
      console.error(responseText)
      process.exit(1)
    }

    const responseBody = JSON.parse(responseText)
    console.log('✅ Response received (HTTP 200)')
    console.log('Response body:')
    console.log(JSON.stringify(responseBody, null, 2))
    console.log('')

    // Validate response structure
    if (!responseBody.choices || !responseBody.choices[0]?.message?.content) {
      console.error('❌ Invalid response structure')
      process.exit(1)
    }

    console.log(`✅ Valid OpenAI response structure`)
    console.log(`Content: "${responseBody.choices[0].message.content}"`)
    console.log('')
    console.log('🎉 LLM connectivity test PASSED')
    process.exit(0)
  } catch (error) {
    const duration = Date.now() - startTime
    console.error(`❌ Request failed (${duration}ms)`)
    console.error(error instanceof Error ? error.message : String(error))
    process.exit(1)
  }
}

testLLM()
