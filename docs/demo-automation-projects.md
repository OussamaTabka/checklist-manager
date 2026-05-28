# Demo Automation Projects

This seed adds realistic public-sandbox automation projects to IntelliTest so the checklist execution flow can be tested against real websites.

## Seeded Projects

### 1. Sauce Demo E-commerce Automation
- Base URL: `https://www.saucedemo.com`
- Focus: login, login rejection, sorting, add/remove cart item, checkout validation, successful checkout
- Known credentials:
  - `standard_user / secret_sauce`
  - `locked_out_user / secret_sauce`
  - `problem_user / secret_sauce`
  - `performance_glitch_user / secret_sauce`

### 2. Practice Login Automation
- Base URL: `https://practicetestautomation.com/practice-test-login/`
- Focus: valid login, invalid username, invalid password, logout
- Known credentials:
  - `student / Password123`

### 3. Automation Testing Practice Playground
- Base URL: `https://testautomationpractice.blogspot.com/`
- Focus: required fields, date input, file upload, static table, pagination, alerts, dynamic button behavior
- Sample upload file:
  - `backend/storage/app/testing/sample-upload.txt`

### 4. Automation Exercise E-commerce QA
- Base URL: `https://automationexercise.com/`
- Focus: home page, signup/login navigation, category navigation, search, product details, add to cart, contact form reachability

## What The Seeder Creates

- 4 public demo projects
- multiple user stories per project
- one or more checklists per user story
- multiple checklist items per checklist
- execution profiles with:
  - required inputs prefilled for the run modal
  - preconditions
  - expected observations
  - suggested generated-plan summary

## How To Run The Seeder

From `backend/`:

```bash
php artisan migrate
php artisan db:seed --class=DemoAutomationProjectsSeeder
```

The seeder is intentionally **not** registered in `DatabaseSeeder.php` by default, so it can be run manually when you want the public demo workspace.

## Default Users

The seeder ensures these accounts exist:

- Chef de projet: `chef@test.com / password123`
- Testeur: `testeur@test.com / password123`

The seeded tester is assigned to every demo project so checklist item execution is allowed by the current authorization rules.

## How To Launch An Automatic Test From The UI

1. Sign in as `testeur@test.com`.
2. Open one of the seeded projects.
3. Open a checklist and choose a checklist item.
4. Click `Lancer le test automatique`.
5. Enter the public base URL if the modal is not already using the correct one.
6. Review the prefilled required inputs.
7. Click `Lancer maintenant`.

Notes:
- The current UI stores the last used base URL locally, so it may default to an older value.
- Required inputs are prefilled from the item `execution_profile`.

## How To Interpret Failures

When a run fails, inspect:

- generated actions and assertions
- execution trace
- screenshots and trace archive
- failure source and error message

Typical root-cause classes:

- data issue
- wrong scenario
- wrong selector
- missing preflight
- actual demo-site issue

## Recommended First Smoke Tests

Start with these stable items:

1. `Verify successful login with standard_user`
2. `Verify valid login reaches the logged-in page`
3. `Verify the Automation Exercise home page loads`

These give quick feedback on:
- public site reachability
- selector robustness
- required-input prefill
- artifact collection
