import { test, expect } from '@playwright/test'

test.describe('Project Management Workflows', () => {
  test.beforeEach(async ({ page }) => {
    // Login as chef user
    await page.goto('/login')
    await page.fill('input[type="email"]', 'chef@example.com')
    await page.fill('input[type="password"]', 'password')
    await page.click('button[type="submit"]')
    await page.waitForURL('/dashboard')
  })

  test('Create a new project with start date and status', async ({ page }) => {
    // Navigate to projects
    await page.goto('/projects')
    
    // Click "New Project" button
    await page.click('button:has-text("New Project")')
    
    // Fill in project details
    await page.fill('input[placeholder*="Project name"]', 'Test Project')
    await page.fill('textarea[placeholder*="description"]', 'A test project for demonstration')
    await page.fill('input[type="url"]', 'https://example.com')
    
    // Fill start date
    await page.fill('input[type="date"]', '2026-05-01')
    
    // Select status
    await page.selectOption('select', 'active')
    
    // Select primary checklist
    await page.selectOption('select[placeholder*="Primary"]', '1')
    
    // Submit form
    await page.click('button:has-text("Create Project")')
    
    // Verify project was created
    await expect(page.locator('text=Test Project')).toBeVisible()
    await expect(page.locator('text=Project created successfully')).toBeVisible()
  })

  test('View project dashboard after creation', async ({ page }) => {
    // Navigate to projects
    await page.goto('/projects')
    
    // Click on a project
    await page.click('a:has-text("Test Project")')
    
    // Verify dashboard elements
    await expect(page.locator('h1')).toContainText('Test Project')
    await expect(page.locator('text=Project Dashboard')).toBeVisible()
  })
})

test.describe('User Story Management Workflows', () => {
  test.beforeEach(async ({ page }) => {
    // Login as chef user
    await page.goto('/login')
    await page.fill('input[type="email"]', 'chef@example.com')
    await page.fill('input[type="password"]', 'password')
    await page.click('button[type="submit"]')
    await page.waitForURL('/dashboard')
  })

  test('Create a new user story with all fields', async ({ page }) => {
    // Navigate to user stories
    await page.goto('/projects/1')
    await page.click('a:has-text("User Stories")')
    
    // Click "Add User Story"
    await page.click('button:has-text("Add User Story")')
    
    // Fill basic info
    await page.fill('input[placeholder*="Title"]', 'User Login Feature')
    await page.fill('textarea[placeholder*="Description"]', 'Implement user login functionality')
    
    // Expand Story Structure section
    await page.click('button:has-text("Story Structure")')
    await page.fill('input[placeholder*="As A"]', 'End User')
    await page.fill('textarea[placeholder*="I Want That"]', 'I want to login with email and password')
    await page.fill('textarea[placeholder*="So That"]', 'So that I can access my dashboard securely')
    
    // Expand Business Rules section
    await page.click('button:has-text("Business Rules")')
    await page.click('button:has-text("Add a rule")')
    await page.fill('input[placeholder*="rule"]', 'Email must be unique')
    
    // Expand Scenarios section
    await page.click('button:has-text("Scenarios")')
    await page.click('button:has-text("Add scenario")')
    await page.fill('textarea[placeholder*="Given/When/Then"]', 'Given valid credentials, When user submits form, Then dashboard loads')
    
    // Expand Estimation section
    await page.click('button:has-text("Estimation")')
    await page.fill('input[placeholder="Ex: 5"]', '8')
    await page.fill('input[placeholder="Ex: 10"]', '15')
    
    // Expand Timeline section
    await page.click('button:has-text("Dates")')
    await page.fill('input[placeholder*="Start"]', '2026-05-01')
    await page.fill('input[placeholder*="Target"]', '2026-05-15')
    
    // Submit form
    await page.click('button:has-text("Create")')
    
    // Verify story was created
    await expect(page.locator('text=User Login Feature')).toBeVisible()
    await expect(page.locator('text=User story created')).toBeVisible()
  })

  test('View user story with all details', async ({ page }) => {
    // Navigate to user stories
    await page.goto('/projects/1')
    await page.click('a:has-text("User Stories")')
    
    // Click on a story to view details
    await page.click('a:has-text("User Login Feature")')
    
    // Verify all sections are visible
    await expect(page.locator('text=As A')).toBeVisible()
    await expect(page.locator('text=End User')).toBeVisible()
    await expect(page.locator('text=Business Rules')).toBeVisible()
    await expect(page.locator('text=Scenarios')).toBeVisible()
    await expect(page.locator('text=Effort Points: 8')).toBeVisible()
    await expect(page.locator('text=Business Value: 15')).toBeVisible()
  })

  test('Edit user story and update fields', async ({ page }) => {
    // Navigate to user stories
    await page.goto('/projects/1')
    await page.click('a:has-text("User Stories")')
    
    // Click edit on a story
    await page.click('button:has-text("Edit")')
    
    // Update effort points
    await page.click('button:has-text("Estimation")')
    await page.fill('input[placeholder="Ex: 5"]', '13')
    
    // Update status
    await page.selectOption('select', 'in_progress')
    
    // Submit
    await page.click('button:has-text("Update")')
    
    // Verify changes
    await expect(page.locator('text=User story updated')).toBeVisible()
    await expect(page.locator('text=13')).toBeVisible()
  })
})

test.describe('Checklist Template Integration', () => {
  test.beforeEach(async ({ page }) => {
    // Login as chef user
    await page.goto('/login')
    await page.fill('input[type="email"]', 'chef@example.com')
    await page.fill('input[type="password"]', 'password')
    await page.click('button[type="submit"]')
    await page.waitForURL('/dashboard')
  })

  test('Attach checklist to user story', async ({ page }) => {
    // Navigate to user story detail
    await page.goto('/projects/1/user-stories/1')
    
    // Click "Attach Checklist" button
    await page.click('button:has-text("Attach Checklist")')
    
    // Select a checklist
    await page.selectOption('select', '1')
    
    // Confirm attachment
    await page.click('button:has-text("Attach")')
    
    // Verify checklist appears
    await expect(page.locator('text=Checklist attached')).toBeVisible()
    await expect(page.locator('text=Testing Checklist')).toBeVisible()
  })

  test('Detach checklist from user story', async ({ page }) => {
    // Navigate to user story detail
    await page.goto('/projects/1/user-stories/1')
    
    // Find and click remove button for attached checklist
    await page.click('button:has-text("Remove"):near(text="Testing Checklist")')
    
    // Confirm removal
    await page.click('button:has-text("Confirm")')
    
    // Verify checklist is removed
    await expect(page.locator('text=Checklist removed')).toBeVisible()
    await expect(page.locator('text=Testing Checklist')).not.toBeVisible()
  })

  test('Generate checklist from AI for user story', async ({ page }) => {
    // Navigate to user story detail
    await page.goto('/projects/1/user-stories/1')
    
    // Click "Generate from AI" button
    await page.click('button:has-text("Generate Checklist")')
    
    // Wait for generation
    await page.waitForSelector('.spinner', { state: 'hidden' })
    
    // Verify AI-generated checklist appears
    await expect(page.locator('text=AI Generated Checklist')).toBeVisible()
    await expect(page.locator('text=is_generated_from_arxis: true')).toBeVisible()
  })
})

test.describe('Project Dashboard', () => {
  test.beforeEach(async ({ page }) => {
    // Login as chef user
    await page.goto('/login')
    await page.fill('input[type="email"]', 'chef@example.com')
    await page.fill('input[type="password"]', 'password')
    await page.click('button[type="submit"]')
    await page.waitForURL('/dashboard')
  })

  test('View project backlog with user stories', async ({ page }) => {
    // Navigate to project
    await page.goto('/projects/1')
    
    // Click on Backlog tab
    await page.click('a:has-text("Backlog")')
    
    // Verify user stories are displayed
    await expect(page.locator('text=User Login Feature')).toBeVisible()
    
    // Filter by status
    await page.selectOption('select', 'in_progress')
    
    // Verify filtered results
    await expect(page.locator('text=En Cours')).toBeVisible()
  })

  test('View project statistics', async ({ page }) => {
    // Navigate to project
    await page.goto('/projects/1')
    
    // Click on Stats/Metrics tab
    await page.click('a:has-text("Statistics")')
    
    // Verify metrics are displayed
    await expect(page.locator('text=Total Stories')).toBeVisible()
    await expect(page.locator('text=In Progress')).toBeVisible()
    await expect(page.locator('text=Completed')).toBeVisible()
    await expect(page.locator('text=Average Effort')).toBeVisible()
  })
})
