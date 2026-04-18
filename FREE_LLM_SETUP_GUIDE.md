# Free LLM Test Case Generation Setup Guide

**No API keys required. No paid services. 100% free & open-source!** 🔥

## Overview

This guide shows how to set up free LLM-based test case generation using:
- **Ollama** or **LM Studio** (Local LLM)
- **EvoMaster** (API testing - optional)
- **Fallback** (Rule-based - always works)

## Architecture

```
User Story
    ↓
TestCaseGenerationService (Factory Pattern)
    ↓
    ├─ LocalLLMGenerator (Ollama/LM Studio) ⭐ Recommended
    ├─ EvoMasterGenerator (Optional)
    └─ FallbackGenerator (Always available)
    ↓
Checklist with Test Cases
```

## ⚡ Quick Start (5 minutes)

### Step 1: Install Ollama

**Windows/Mac/Linux:**
```bash
# Download from https://ollama.ai
# Or via Homebrew (Mac):
brew install ollama

# Or via Linux:
curl https://ollama.ai/install.sh | sh
```

### Step 2: Pull a Model

```bash
# Pull Mistral (recommended - fast & smart)
ollama pull mistral

# Or other models:
ollama pull llama2          # Meta's Llama2
ollama pull neural-chat     # Intel's neural-chat
ollama pull starling        # Starling model
```

### Step 3: Start Ollama

```bash
# This starts the API server on http://localhost:11434
ollama serve
```

### Step 4: Configure Backend

Edit `backend/.env`:
```env
TEST_GENERATION_PROVIDER=local-llm
LLM_API_URL=http://localhost:11434
LLM_MODEL=mistral
```

### Step 5: Done! 🎉

Generate test cases:
```bash
POST /api/projects/1/user-stories/1/generate-from-arxis
```

## Detailed Setup per Platform

### Windows

1. **Install Ollama**
   - Download from https://ollama.ai
   - Run installer
   - Ollama starts automatically in background

2. **Pull Model**
   ```powershell
   ollama pull mistral
   ```

3. **Start Server** (if not running)
   ```powershell
   ollama serve
   ```

4. **Verify**
   ```powershell
   Invoke-WebRequest http://localhost:11434/api/tags
   ```

### Mac

1. **Install via Homebrew**
   ```bash
   brew install ollama
   ```

2. **Pull Model**
   ```bash
   ollama pull mistral
   ```

3. **Start Server**
   ```bash
   ollama serve
   ```

### Linux

1. **Install**
   ```bash
   curl https://ollama.ai/install.sh | sh
   ```

2. **Pull Model**
   ```bash
   ollama pull mistral
   ```

3. **Start Server**
   ```bash
   ollama serve
   ```

   Or as a systemd service:
   ```bash
   sudo systemctl start ollama
   ```

## Recommended Models

| Model | Size | Speed | Quality | Best For |
|-------|------|-------|---------|----------|
| **Mistral** | 7B | ⚡⚡⚡ Fast | ⭐⭐⭐ | General - Recommended |
| Llama2 | 13B | ⚡⚡ Moderate | ⭐⭐⭐⭐ | Good quality/speed tradeoff |
| Neural-Chat | 7B | ⚡⚡⚡ Fast | ⭐⭐⭐ | Conversation-optimized |
| Starling | 7B | ⚡⚡⚡ Fast | ⭐⭐⭐⭐ | Better reasoning |

**Recommendation:** Start with **Mistral** - it's fast, free, and produces excellent test cases!

```bash
ollama pull mistral
```

## Advanced: Use LM Studio

**LM Studio** is an alternative with a GUI:

1. Download from https://lmstudio.ai
2. Download a model through the UI
3. Start local server (usually on `http://localhost:1234`)
4. Configure `.env`:
   ```env
   LLM_API_URL=http://localhost:1234
   LLM_MODEL=local-model  # or your model name
   ```

## Advanced: EvoMaster Setup (Optional)

For more sophisticated API testing:

1. **Download EvoMaster**
   ```bash
   git clone https://github.com/WebCheckingCode/EvoMaster.git
   cd EvoMaster
   mvn clean install
   ```

2. **Run EvoMaster**
   ```bash
   java -jar core/target/evomaster.jar --port 40898
   ```

3. **Configure `.env`**
   ```env
   TEST_GENERATION_PROVIDER=evomaster
   EVOMASTER_URL=http://localhost
   EVOMASTER_PORT=40898
   ```

## Configuration Options

### backend/.env

```env
# Choose provider (auto-falls back if unavailable)
TEST_GENERATION_PROVIDER=local-llm

# Local LLM (Ollama/LM Studio)
LLM_API_URL=http://localhost:11434
LLM_MODEL=mistral

# EvoMaster
EVOMASTER_URL=http://localhost
EVOMASTER_PORT=40898
```

## Usage Examples

### Generate Test Cases

```bash
curl -X POST http://localhost:8000/api/projects/1/user-stories/1/generate-from-arxis \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json"
```

**Response:**
```json
{
  "message": "Checklist generated successfully",
  "checklist": {
    "id": 1,
    "name": "Generated (Local LLM (mistral)): User can login",
    "category": "ai-generated",
    "items": [
      {
        "id": 1,
        "name": "Happy Path: User can login",
        "description": "Test the main/happy path scenario",
        "expected_result": "Feature works as expected",
        "criticality": "High"
      }
    ]
  },
  "available_generators": {
    "local-llm": {
      "name": "Local LLM (mistral)",
      "available": true
    },
    "evomaster": {
      "name": "EvoMaster",
      "available": false
    },
    "fallback": {
      "name": "Fallback (Rule-based)",
      "available": true
    }
  }
}
```

## Troubleshooting

### "Local LLM service not available"

**Check if Ollama is running:**
```bash
# Windows
tasklist | findstr ollama

# Mac/Linux
ps aux | grep ollama
```

**Start Ollama:**
```bash
# Windows - should auto-start, or manually start from Applications
# Mac/Linux
ollama serve
```

**Test connection:**
```bash
curl http://localhost:11434/api/tags
```

### Slow Response Time

Use a smaller model:
```bash
ollama pull neural-chat  # 7B - faster
ollama pull miqu  # 7B - very fast
```

### Low Quality Test Cases

Try a better model:
```bash
ollama pull llama2           # Better quality, slower
ollama pull starling         # Improved reasoning
```

### Out of Memory Error

Use a smaller model or reduce context size. GPU is helpful:

```bash
# Check if GPU is available (Ollama uses GPU automatically if available)
ollama list  # Shows GPU usage
```

## Performance Tips

1. **Use smaller models for speed:**
   - `mistral` (7B) - Great balance
   - `neural-chat` (7B) - Very fast
   - `miqu` (7B) - Ultra fast

2. **Use GPU acceleration:**
   - Ollama auto-detects and uses GPU (NVIDIA, AMD, Apple Metal)
   - ~10x faster with GPU

3. **Increase timeout for complex stories:**
   Edit `LocalLLMGenerator.php`:
   ```php
   Http::timeout(180)->post(...)  // 3 minutes
   ```

## API Endpoints

### Get Test Generators Status
```http
GET /api/projects/{project}/user-stories/generators
```

Response:
```json
{
  "local-llm": {
    "name": "Local LLM (mistral)",
    "available": true
  },
  "evomaster": {
    "name": "EvoMaster",
    "available": false
  },
  "fallback": {
    "name": "Fallback (Rule-based)",
    "available": true
  }
}
```

## Comparison: Paid vs Free

| Feature | Paid Service | Free LLM |
|---------|--------------|----------|
| **Cost** | $500-5000+/mo | Free |
| **Privacy** | Cloud hosted | Local/Private |
| **Speed** | Depends | 5-30 seconds per generation |
| **Customization** | Limited | Full control |
| **Setup** | Easy | 5 minutes |
| **Offline** | ❌ | ✅ Yes |
| **Test Cases** | Good | Excellent |
| **Quality Control** | Limited | Full |

## Next Steps

1. ✅ Install Ollama
2. ✅ Pull a model (`mistral` recommended)
3. ✅ Start Ollama server
4. ✅ Configure `.env`
5. 🚀 Generate test cases!

## Resources

- **Ollama:** https://ollama.ai
- **LM Studio:** https://lmstudio.ai
- **EvoMaster:** https://github.com/WebCheckingCode/EvoMaster
- **Available Models:** https://ollama.ai/library

## Support

If tests fail to generate:

1. Check if Ollama/LLM is running
2. Verify API connection with `curl http://localhost:11434/api/tags`
3. Check backend logs: `tail -f storage/logs/laravel.log`
4. User story should have description + acceptance criteria

---

**Enjoy free, private, offline test case generation!** 🎉
