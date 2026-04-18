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
  - main [ref=e4]:
    - generic [ref=e6]:
      - generic [ref=e7]:
        - heading "Sign in" [level=1] [ref=e8]
        - paragraph [ref=e9]: Use your account credentials to access Checklist Manager.
      - generic [ref=e10]:
        - generic [ref=e11]:
          - generic [ref=e12]: Email
          - textbox [ref=e13]
        - generic [ref=e14]:
          - generic [ref=e15]: Password
          - textbox [ref=e16]
        - link "Forgot password?" [ref=e18]:
          - /url: /forgot-password
        - button "Sign in" [ref=e19]
  - generic [ref=e20]:
    - generic "Toggle devtools panel" [ref=e21] [cursor=pointer]:
      - img [ref=e22]
    - generic "Toggle Component Inspector" [ref=e27] [cursor=pointer]:
      - img [ref=e28]
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