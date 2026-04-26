import { test, expect } from '@playwright/test'

const API_BASE_URL = 'http://localhost:8000/api'
let authToken = ''

test.describe('User Story API Tests', () => {
  test.beforeEach(async ({ request }) => {
    // Login to get auth token
    const loginResponse = await request.post(`${API_BASE_URL}/login`, {
      data: {
        email: 'chef@example.com',
        password: 'password',
      },
    })
    const loginData = await loginResponse.json()
    authToken = loginData.token
  })

  test('Create user story with all new fields', async ({ request }) => {
    const response = await request.post(`${API_BASE_URL}/projects/1/user-stories`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        title: 'API Test Story',
        description: 'Testing API story creation',
        as_a: 'End User',
        i_want_that: 'I want to test the API',
        so_that: 'So that I can verify functionality',
        acceptance_criteria: 'Given test data, When API is called, Then response is valid',
        business_rules: ['Rule 1', 'Rule 2'],
        scenarios: ['Scenario 1', 'Scenario 2'],
        effort_points: 5,
        business_value: 10,
        start_date: '2026-05-01',
        target_completion_date: '2026-05-15',
        status: 'backlog',
        priority: 'medium',
      },
    })

    expect(response.status()).toBe(201)
    const data = await response.json()
    expect(data.title).toBe('API Test Story')
    expect(data.as_a).toBe('End User')
    expect(data.effort_points).toBe(5)
    expect(data.business_value).toBe(10)
    expect(data.start_date).toBe('2026-05-01')
  })

  test('Update user story fields', async ({ request }) => {
    const response = await request.put(`${API_BASE_URL}/projects/1/user-stories/1`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        effort_points: 8,
        business_value: 20,
        status: 'in_progress',
        business_rules: ['Updated Rule 1', 'Updated Rule 2'],
      },
    })

    expect(response.status()).toBe(200)
    const data = await response.json()
    expect(data.effort_points).toBe(8)
    expect(data.business_value).toBe(20)
    expect(data.status).toBe('in_progress')
  })

  test('Get user story with all fields', async ({ request }) => {
    const response = await request.get(`${API_BASE_URL}/projects/1/user-stories/1`, {
      headers: { Authorization: `Bearer ${authToken}` },
    })

    expect(response.status()).toBe(200)
    const data = await response.json()
    expect(data).toHaveProperty('id')
    expect(data).toHaveProperty('title')
    expect(data).toHaveProperty('as_a')
    expect(data).toHaveProperty('i_want_that')
    expect(data).toHaveProperty('so_that')
    expect(data).toHaveProperty('business_rules')
    expect(data).toHaveProperty('scenarios')
    expect(data).toHaveProperty('effort_points')
    expect(data).toHaveProperty('business_value')
    expect(data).toHaveProperty('start_date')
    expect(data).toHaveProperty('target_completion_date')
  })

  test('Attach checklist to user story', async ({ request }) => {
    const response = await request.post(`${API_BASE_URL}/projects/1/user-stories/1/attach-checklist`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: { checklist_id: 1 },
    })

    expect(response.status()).toBe(201)
    const data = await response.json()
    expect(data.checklists).toBeDefined()
    expect(data.checklists.length).toBeGreaterThan(0)
  })

  test('Detach checklist from user story', async ({ request }) => {
    const response = await request.delete(`${API_BASE_URL}/projects/1/user-stories/1/checklists/1`, {
      headers: { Authorization: `Bearer ${authToken}` },
    })

    expect(response.status()).toBe(200)
    const data = await response.json()
    expect(data.checklists).toBeDefined()
  })

  test('Generate checklist from AI', async ({ request }) => {
    const response = await request.post(`${API_BASE_URL}/projects/1/user-stories/1/generate-from-arxis`, {
      headers: { Authorization: `Bearer ${authToken}` },
    })

    expect(response.status()).toBe(201)
    const data = await response.json()
    expect(data.checklist).toBeDefined()
    expect(data.checklist.id).toBeDefined()
    expect(data.message).toBe('Checklist generated successfully')
  })
})

test.describe('Project API Tests', () => {
  test.beforeEach(async ({ request }) => {
    // Login to get auth token
    const loginResponse = await request.post(`${API_BASE_URL}/login`, {
      data: {
        email: 'chef@example.com',
        password: 'password',
      },
    })
    const loginData = await loginResponse.json()
    authToken = loginData.token
  })

  test('Create project with start_date and status', async ({ request }) => {
    const response = await request.post(`${API_BASE_URL}/projects`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        name: 'New Test Project',
        description: 'Test project creation',
        app_url: 'https://example.com',
        start_date: '2026-05-01',
        status: 'active',
        checklist_id: 1,
        checklist_ids: [1],
        tester_ids: [2],
      },
    })

    expect(response.status()).toBe(201)
    const data = await response.json()
    expect(data.name).toBe('New Test Project')
    expect(data.start_date).toBe('2026-05-01')
    expect(data.status).toBe('active')
  })

  test('Update project fields', async ({ request }) => {
    const response = await request.put(`${API_BASE_URL}/projects/1`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        name: 'Updated Project Name',
        status: 'completed',
        start_date: '2026-06-01',
      },
    })

    expect(response.status()).toBe(200)
    const data = await response.json()
    expect(data.name).toBe('Updated Project Name')
    expect(data.status).toBe('completed')
  })

  test('Get project with user stories', async ({ request }) => {
    const response = await request.get(`${API_BASE_URL}/projects/1`, {
      headers: { Authorization: `Bearer ${authToken}` },
    })

    expect(response.status()).toBe(200)
    const data = await response.json()
    expect(data).toHaveProperty('id')
    expect(data).toHaveProperty('name')
    expect(data).toHaveProperty('start_date')
    expect(data).toHaveProperty('status')
  })

  test('Get project metadata', async ({ request }) => {
    const response = await request.get(`${API_BASE_URL}/projects/metadata`, {
      headers: { Authorization: `Bearer ${authToken}` },
    })

    expect(response.status()).toBe(200)
    const data = await response.json()
    expect(data).toHaveProperty('checklists')
    expect(data).toHaveProperty('testers')
    expect(Array.isArray(data.checklists)).toBeTruthy()
    expect(Array.isArray(data.testers)).toBeTruthy()
  })

  test('Get user story generators status', async ({ request }) => {
    const response = await request.get(`${API_BASE_URL}/projects/1/user-stories/generators/status`, {
      headers: { Authorization: `Bearer ${authToken}` },
    })

    expect(response.status()).toBe(200)
    const data = await response.json()
    expect(data).toHaveProperty('available_generators')
    expect(data).toHaveProperty('configured_provider')
    expect(data).toHaveProperty('configured_model')
  })
})

test.describe('Data Validation Tests', () => {
  test.beforeEach(async ({ request }) => {
    // Login to get auth token
    const loginResponse = await request.post(`${API_BASE_URL}/login`, {
      data: {
        email: 'chef@example.com',
        password: 'password',
      },
    })
    const loginData = await loginResponse.json()
    authToken = loginData.token
  })

  test('Validate date format', async ({ request }) => {
    const response = await request.post(`${API_BASE_URL}/projects/1/user-stories`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        title: 'Date Test',
        description: 'Testing date validation',
        start_date: 'invalid-date',
      },
    })

    expect(response.status()).toBe(422)
  })

  test('Validate effort_points is positive', async ({ request }) => {
    const response = await request.post(`${API_BASE_URL}/projects/1/user-stories`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        title: 'Effort Test',
        description: 'Testing effort validation',
        effort_points: -5,
      },
    })

    expect(response.status()).toBe(422)
  })

  test('Validate business_value is positive', async ({ request }) => {
    const response = await request.post(`${API_BASE_URL}/projects/1/user-stories`, {
      headers: { Authorization: `Bearer ${authToken}` },
      data: {
        title: 'Value Test',
        description: 'Testing value validation',
        business_value: 0,
      },
    })

    expect(response.status()).toBe(422)
  })
})
