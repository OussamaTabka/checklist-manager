# Test Case Generation - Quick Reference

## What Changed?

❌ **Old:** Arxis API (requires API key, paid service)  
✅ **New:** Free LLM tools (Ollama, EvoMaster - no cost, runs locally)

## 5-Minute Setup

### 1. Install Ollama
```bash
# Download from https://ollama.ai or:
brew install ollama  # Mac
```

### 2. Pull a Model
```bash
ollama pull mistral
```

### 3. Start Server
```bash
ollama serve
```

### 4. Configure `.env`
```env
TEST_GENERATION_PROVIDER=local-llm
LLM_API_URL=http://localhost:11434
LLM_MODEL=mistral
```

### 5. Generate Tests!
```bash
POST /api/projects/1/user-stories/1/generate-from-arxis
```

## Providers

| Provider | Command | Setup Time | Cost | Status |
|----------|---------|-----------|------|--------|
| **Local LLM** | `ollama pull mistral` | 2 min | Free | ⭐ Recommended |
| EvoMaster | Download from GitHub | 10 min | Free | Good |
| Fallback | Built-in | Already ready | Free | Always works |

## Files Changed

### New Files
- `app/Services/TestCaseGeneration/` (4 files)
  - `TestCaseGeneratorInterface.php`
  - `LocalLLMGenerator.php`
  - `EvoMasterGenerator.php`
  - `FallbackGenerator.php`
  - `TestCaseGeneratorFactory.php`
- `app/Services/TestCaseGenerationService.php`

### Updated Files
- `app/Http/Controllers/Api/UserStoryController.php` (ArxisService → TestCaseGenerationService)
- `config/services.php` (added test_generation config)
- `.env.example` (added new config vars)

## Architecture

Factory pattern with fallback chain:
1. Try preferred provider (local-llm)
2. If unavailable, try EvoMaster
3. If unavailable, use Fallback (rule-based)
4. Always has a working generator!

## Configuration

### `backend/.env`
```env
# Provider options: 'local-llm', 'evomaster', 'fallback'
TEST_GENERATION_PROVIDER=local-llm

# Local LLM Settings
LLM_API_URL=http://localhost:11434
LLM_MODEL=mistral  # or: llama2, neural-chat, starling

# EvoMaster Settings (optional)
EVOMASTER_URL=http://localhost
EVOMASTER_PORT=40898
```

## API Response

Now includes generator status:
```json
{
  "available_generators": {
    "local-llm": {"name": "Local LLM (mistral)", "available": true},
    "evomaster": {"name": "EvoMaster", "available": false},
    "fallback": {"name": "Fallback (Rule-based)", "available": true}
  }
}
```

## Comparisons

### Quality
- **Local LLM (Mistral):** ⭐⭐⭐⭐ Excellent
- **EvoMaster:** ⭐⭐⭐⭐ Excellent (API-focused)
- **Fallback:** ⭐⭐⭐ Good

### Speed
- **Local LLM:** 5-30s per generation (depends on model)
- **EvoMaster:** 10-60s per generation
- **Fallback:** <1s (instant)

### Cost
- **Local LLM:** FREE
- **EvoMaster:** FREE
- **Fallback:** FREE
- **Arxis (old):** $500-5000+/month

## Best Practices

1. **Use Mistral** - Perfect balance of speed and quality
   ```bash
   ollama pull mistral
   ```

2. **Have GPU** - Makes it ~10x faster
   - NVIDIA: CUDA acceleration (auto-detected)
   - Mac: Metal acceleration (auto-used)
   - CPU: Still works, just slower

3. **For better quality** (if you have time):
   ```bash
   ollama pull llama2  # Slower but better quality
   ```

4. **For fastest generation**:
   ```bash
   ollama pull neural-chat  # 7B model
   ```

## Troubleshooting

| Problem | Solution |
|---------|----------|
| "Service not available" | Start Ollama: `ollama serve` |
| Slow generation | Use smaller model (mistral) |
| Memory issues | Use 7B model instead of larger |
| Low quality | Switch to llama2 or starling |

## Feature Comparison: Arxis vs Free LLM

| Feature | Arxis | Free LLM |
|---------|-------|----------|
| Cost | $$$$ | FREE |
| Privacy | Cloud | Local ✓ |
| Speed | Fast | 5-30s |
| Quality | Good | Excellent |
| Offline | ❌ | ✅ |
| Setup | 2min | 5min |
| No API key | ❌ | ✅ |

## Migration from Arxis

**No code changes needed!** Just:

1. Remove `ARXIS_API_KEY` from `.env`
2. Add `TEST_GENERATION_PROVIDER=local-llm`
3. Install Ollama
4. Run `ollama pull mistral`
5. Start `ollama serve`

The system auto-detects available generators!

---

📖 Full guide: See `FREE_LLM_SETUP_GUIDE.md`
