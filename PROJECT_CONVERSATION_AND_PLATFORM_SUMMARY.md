# IntelliTest Project Summary

## Overview

IntelliTest is a QA management and execution platform built to:

- manage projects, user stories, reusable checklists, and test items
- suggest and generate QA checklists from user stories
- enforce review and validation workflows before attachment
- execute test cases automatically with a `Run Test` flow
- track item status, history, comments, and execution artifacts

The stack is split into:

- `backend/`: Laravel API, business logic, migrations, services
- `frontend/`: Vue + Vite application
- `playwright-*`: automated execution tooling used by the test runner flow

---

## Main Functional Areas

### 1. Authentication and Roles

Main roles used in the project:

- `admin`
- `chef`
- `admin_contenus`
- `testeur`

Important role behavior:

- `chef`: manages projects, validates drafts, attaches checklists, creates versions
- `admin_contenus`: manages checklist content
- `testeur`: executes tests and updates results

---

### 2. User Stories

User stories include QA-oriented structure such as:

- `title`
- `description`
- `as_a`
- `i_want_that`
- `so_that`
- `acceptance_criteria`
- `business_rules`
- `scenarios`
- `priority`
- `status`

Story status values:

- `backlog`
- `in_progress`
- `ready_for_test`
- `completed`

---

### 3. Checklists

There are two important checklist concepts:

- reusable checklist templates
- project/story drafts and approved attached checklists

Checklist fields include:

- `name`
- `description`
- `category`
- `priority`
- `status`
- `template_scope`
- `lifecycle_status`
- `generated_from`
- `source_user_story_id`

Checklist lifecycle:

- `draft`
- `approved`
- `archived`

Checklist origin:

- `manual`
- `ai`
- `reuse`

---

### 4. Checklist Items / Test Cases

Each checklist contains items that behave like test cases.

Each item supports:

- `title`
- `description`
- `priority`
- `criticality`
- `status`
- `tested_by`
- `tested_at`

Item execution status shown to users:

- `Not Tested`
- `Passed`
- `Failed`
- `Blocked`

Stored values may differ slightly in the database for checklist items:

- `pending`
- `passed`
- `failed`
- `blocked`

---

### 5. Project Versions and Execution

Projects can create execution-ready versions from checklists.

A project version:

- copies a checklist into executable `VersionItem`s
- allows test execution in the project workspace
- tracks progress statistics
- supports exports

Version item execution includes:

- manual status updates
- automatic `Run Test`
- comments
- history
- traceability
- artifact links

---

## Important Workflow Rules Implemented

### Review Before Attach

The platform now enforces:

- review
- adapt/edit
- approve
- attach
- execute

Direct attachment from AI generation is no longer allowed.

---

### AI-Generated Checklists

When the agent generates a checklist from a user story:

- it creates a draft checklist
- it does **not** attach it directly to the story
- it appears under `Pending chef validation`
- the `chef` can review and edit it
- only after approval does it attach to the story

---

### Suggested Checklists

Suggested reusable checklists also do not attach directly.

Flow:

1. suggestion appears
2. chef clicks `View details`
3. chef edits items if needed
4. a draft is created for review
5. chef approves and attaches later

---

### Manual Chef Drafts

The chef can also create a checklist manually from the story UI:

- `Add manual checklist`
- define items manually
- save as draft
- approve and attach later

---

## UI/UX Changes Made During This Conversation

### Branding

Branding was aligned to:

- app name: `IntelliTest`
- browser title: `IntelliTest`
- favicon updated from Vite default
- top-left logo made clickable and now refreshes the page
- Vite devtools bottom badge removed from development config

Relevant files:

- `frontend/index.html`
- `frontend/src/components/LogoHeader.vue`
- `frontend/vite.config.js`

---

### Project Detail Redesign

`ProjectDetailView` was redesigned to:

- make the execution workspace clear
- expose version selection as cards
- surface `Run Test` more clearly
- improve readability and linearity
- preserve existing execution logic

Key ideas:

- visible execution workspace
- environment summary
- better run CTA placement
- better version continuity after refresh

---

### Story Detail Redesign

`UserStoryDetailView` was redesigned to:

- make the QA workflow understandable
- separate suggestions from attached checklists
- add pending draft validation section
- allow manual draft creation
- allow draft review before approval

---

### Checklist Detail Execution

Checklist detail was upgraded so that:

- every checklist item can be tested directly
- each item supports `Run Test`
- status can be updated directly from checklist detail
- history can be viewed
- execution trace and artifacts can be surfaced

This removed the old limitation where automated testing was only obvious from project/version execution screens.

---

## Backend Changes Made During This Conversation

### Added / Extended Concepts

- checklist governance fields
- advanced QA story/checklist support
- checklist recommendation and suggestion review flow
- checklist adaptation flow
- checklist item direct execution support
- chef validation before story attachment

### Important Backend Files Touched

- `backend/app/Http/Controllers/Api/UserStoryController.php`
- `backend/app/Http/Controllers/Api/ChecklistController.php`
- `backend/app/Http/Controllers/Api/ChecklistItemExecutionController.php`
- `backend/app/Http/Controllers/Api/TestCaseRunController.php`
- `backend/app/Jobs/ExecuteSingleTestCaseRun.php`
- `backend/app/Services/ChecklistGenerationAgentService.php`
- `backend/app/Services/ChecklistAdaptationService.php`
- `backend/app/Services/ChecklistRecommendationService.php`
- `backend/app/Services/ChecklistSuggestionReviewService.php`
- `backend/routes/api.php`
- model files for checklist items, test runs, and test results

---

## Frontend Files Heavily Involved

- `frontend/src/App.vue`
- `frontend/src/views/ProjectDetailView.vue`
- `frontend/src/views/UserStoryDetailView.vue`
- `frontend/src/views/ChecklistDetailView.vue`
- `frontend/src/views/ProjectsView.vue`
- `frontend/src/views/ChecklistsView.vue`
- `frontend/src/stores/auth.js`
- `frontend/src/stores/checklists.js`
- `frontend/src/stores/userStories.js`
- `frontend/src/lib/runtimeTranslations.js`

---

## Execution Model

There are now two main places where tests can run:

### Project Execution Workspace

Used for:

- project versions
- version items
- project progress and export

### Checklist Detail Execution

Used for:

- testing checklist items directly
- updating checklist item status
- seeing history and execution state directly from the checklist

---

## Recommendation / Generation Logic

Suggested checklists include data like:

- checklist id
- title
- score
- coverage
- missing
- recommendation

Recommendation values:

- `REUSE`
- `ADAPT_EXISTING`
- `GENERATE_NEW`

The system was aligned with the rule:

- never skip review
- never modify the reusable source directly
- always prefer review -> adapt -> approve -> attach -> execute

---

## Language / UI Coverage

Language switching support was expanded to affect:

- navigation
- visible labels
- many dialogs and runtime text replacements
- form labels/placeholders in key flows

The app still has room for future cleanup if all hardcoded strings are eventually centralized.

---

## Notes About Current Behavior

- recommended checklists can be hidden from the suggestion list locally by the chef with `Delete`
- this current recommendation deletion is UI-level cleanup, not a persistent backend delete
- drafts can be edited before approval
- attached checklists represent validated checklists already linked to the story
- pending drafts represent not-yet-approved material

---

## Suggested Next Improvements

Recommended next steps:

- add persistent `Reject draft`
- add persistent recommendation dismissal on the backend
- add draft validation rules before approval
- generate a real favicon / app icons from IntelliTest branding files
- unify remaining legacy strings and styling across all pages
- optionally make logo click behavior configurable: refresh or go to dashboard

---

## Conversation Outcome Summary

During this conversation, the project was improved in these major ways:

- advanced QA data structure support was aligned with provided examples
- UI language switching was expanded
- suggestion / adaptation / review workflow was improved
- execution UX was redesigned
- checklist direct execution was added
- AI-generated checklist auto-attachment was removed
- chef validation workflow was enforced
- draft editing and manual draft creation were added
- branding was updated from default Vite to IntelliTest
- Vite devtools visual badge was removed
- logo now refreshes the page

---

## File Purpose of This Document

This document is intended to help:

- onboard developers
- summarize the current workflow rules
- capture the important decisions made during this session
- provide a quick reference for how IntelliTest currently behaves

