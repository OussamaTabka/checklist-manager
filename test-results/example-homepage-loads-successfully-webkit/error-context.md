# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: example.spec.js >> homepage loads successfully
- Location: tests\e2e\example.spec.js:3:1

# Error details

```
Error: expect(page).toHaveTitle(expected) failed

Expected pattern: /Checklist|Dashboard/
Received string:  "Vite App"
Timeout: 5000ms

Call log:
  - Expect "toHaveTitle" with timeout 5000ms
    8 × unexpected value "Vite App"

```

# Page snapshot

```yaml
- generic [active] [ref=e1]:
  - main [ref=e4]
  - generic [ref=e5]:
    - generic "Toggle devtools panel" [ref=e6] [cursor=pointer]:
      - img [ref=e7]
    - generic "Toggle Component Inspector" [ref=e12] [cursor=pointer]:
      - img [ref=e13]
```

# Test source

```ts
  1  | import { test, expect } from '@playwright/test';
  2  | 
  3  | test('homepage loads successfully', async ({ page }) => {
  4  |   await page.goto('/');
> 5  |   await expect(page).toHaveTitle(/Checklist|Dashboard/);
     |                      ^ Error: expect(page).toHaveTitle(expected) failed
  6  | });
  7  | 
  8  | test('can navigate to login page', async ({ page }) => {
  9  |   await page.goto('/');
  10 |   // Add navigation test based on your app structure
  11 |   await expect(page).toHaveURL(/./);
  12 | });
  13 | 
```