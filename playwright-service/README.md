# Playwright Service

Small Express + Playwright service that analyzes a webpage and returns structured JSON.

## What it does

`POST /analyze`

Request body:

```json
{ "url": "https://example.com" }
```

Response:

```json
{
  "title": "...",
  "buttons": ["..."],
  "inputs": [{ "name": "...", "type": "..." }],
  "forms": [{ "id": "...", "name": "...", "action": "...", "method": "..." }],
  "links": [{ "text": "...", "href": "..." }]
}
```

## Setup

From the repository root:

```bash
cd playwright-service
npm install

# Install browser binaries (safe to run even if already installed)
npx playwright install chromium
```

## Run

```bash
# Dev (auto-reload)
npm run dev

# Or production
npm start
```

Service defaults to `http://localhost:3001`.

Optional env vars:

- `PORT` (default `3001`)
- `NAVIGATION_TIMEOUT_MS` (default `30000`)
- `ACTION_TIMEOUT_MS` (default `10000`)

## Test the endpoint

PowerShell:

```powershell
Invoke-RestMethod -Method Post -Uri http://localhost:3001/analyze -ContentType application/json -Body '{"url":"https://example.com"}'
```

curl:

```bash
curl -X POST http://localhost:3001/analyze \
  -H "Content-Type: application/json" \
  -d '{"url":"https://example.com"}'
```
