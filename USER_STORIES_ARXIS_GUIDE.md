# User Stories & Arxis Integration Guide

## Overview

This guide explains how to set up and use the linked user stories workflow with Arxis integration for automatic checklist generation.

## Architecture

```
Project
  ├── User Stories Backlog
  │   ├── User Story 1
  │   │   ├── Checklist (Auto-generated from Arxis)
  │   │   │   ├── Test Case 1
  │   │   │   ├── Test Case 2
  │   │   │   └── ...
  │   │   └── Checklist (Manual or attached)
  │   ├── User Story 2
  │   │   └── ...
  │   └── ...
  └── Project Versions
      └── Test Runs
```

## Database Structure

### New Tables

1. **user_stories**
   - `id` - Primary Key
   - `project_id` - Foreign Key to projects
   - `title` - User story title
   - `description` - Detailed description
   - `acceptance_criteria` - What defines success
   - `status` - backlog, in_progress, ready_for_test, completed
   - `priority` - low, medium, high, critical
   - `story_id` - External ID (e.g., from Arxis)
   - `created_by` - User who created it
   - `timestamps` - created_at, updated_at, deleted_at

2. **user_story_checklists** (Pivot Table)
   - `id` - Primary Key
   - `user_story_id` - Foreign Key to user_stories
   - `checklist_id` - Foreign Key to checklists
   - `is_generated_from_arxis` - Boolean flag
   - `timestamps` - created_at, updated_at

## API Endpoints

### User Stories Management

#### List User Stories
```http
GET /api/projects/{project}/user-stories
```

#### Create User Story
```http
POST /api/projects/{project}/user-stories
Content-Type: application/json

{
  "title": "User can login with email",
  "description": "As a user, I want to login with my email and password",
  "acceptance_criteria": "1. User can enter email\n2. User can enter password\n3. System validates credentials\n4. User is redirected to dashboard",
  "status": "backlog",
  "priority": "high",
  "story_id": "ARXIS-123"
}
```

#### Get User Story Details
```http
GET /api/projects/{project}/user-stories/{userStory}
```

#### Update User Story
```http
PUT /api/projects/{project}/user-stories/{userStory}
Content-Type: application/json

{
  "status": "ready_for_test",
  "priority": "critical"
}
```

#### Delete User Story
```http
DELETE /api/projects/{project}/user-stories/{userStory}
```

### Checklist Generation from Arxis

#### Generate Checklist from Arxis
```http
POST /api/projects/{project}/user-stories/{userStory}/generate-from-arxis
```

This endpoint:
1. Sends the user story to the Arxis API
2. Receives generated test cases
3. Creates a new checklist with the test cases
4. Automatically links it to the user story

**Response:**
```json
{
  "message": "Checklist generated successfully from Arxis",
  "checklist": {
    "id": 1,
    "name": "Auto-generated: User can login with email",
    "description": "Generated from user story...",
    "category": "auto-generated",
    "items": [
      {
        "id": 1,
        "name": "Verify login page loads",
        "description": "Check that the login form is displayed",
        "expected_result": "Login form should be visible with email and password fields",
        "criticality": "High"
      }
    ]
  },
  "user_story": {
    "id": 1,
    "title": "User can login with email",
    "checklists": [...]
  }
}
```

#### Attach Existing Checklist
```http
POST /api/projects/{project}/user-stories/{userStory}/attach-checklist
Content-Type: application/json

{
  "checklist_id": 5
}
```

#### Detach Checklist
```http
DELETE /api/projects/{project}/user-stories/{userStory}/checklists/{checklistId}
```

## Configuration

### Backend Setup

1. **Install Dependencies**
```bash
cd backend
composer install
```

2. **Create Migrations**
```bash
php artisan migrate
```

3. **Set up Arxis API Key**

Edit `.env` file and add:
```env
ARXIS_BASE_URL=https://api.arxis.io/v1
ARXIS_API_KEY=your_arxis_api_key_here
```

### Getting Arxis API Key

1. Go to [Arxis Dashboard](https://arxis.io/dashboard)
2. Navigate to API Settings
3. Generate a new API key
4. Copy the key to your `.env` file

## Usage Workflow

### 1. Create a Project
```
POST /api/projects
{
  "name": "E-Commerce Application",
  "description": "Testing e-commerce system"
}
```

### 2. Create User Stories
```
POST /api/projects/1/user-stories
{
  "title": "User can add items to cart",
  "description": "...",
  "acceptance_criteria": "..."
}
```

### 3. Generate Checklists from Arxis
```
POST /api/projects/1/user-stories/1/generate-from-arxis
```

This creates test cases based on the user story.

### 4. Attach Additional Checklists (Optional)
```
POST /api/projects/1/user-stories/1/attach-checklist
{
  "checklist_id": 5
}
```

### 5. Create Project Version & Run Tests
The checklist items are now ready for testing through the standard project version flow.

## Data Flow Diagram

```
User Story Created
       ↓
+------+------+
|             |
v             v
Manual     Arxis API
Checklist   Generated
  Link      Checklist
     \     /
      v   v
    Checklist
       Items
         ↓
   Project Version
         ↓
    Test Runs
```

## Error Handling

### Common Errors

1. **Arxis API Key Missing**
```json
{
  "error": "Arxis API key is not configured. Please set ARXIS_API_KEY in your environment."
}
```

2. **Arxis API Connection Failed**
```json
{
  "error": "Failed to generate checklist from Arxis",
  "message": "Failed to call Arxis API: Connection timeout"
}
```

3. **User Story Not Found**
```json
{
  "error": "User story not found in this project",
  "status": 404
}
```

## Frontend Integration

### Notes for Frontend Development

The following routes and components need to be implemented:

1. **UserStoriesListView** - Display all user stories for a project
2. **UserStoryFormView** - Create/Edit user story
3. **UserStoryDetailView** - View detailed user story info
4. **GenerateChecklistButton** - Button to generate checklist from Arxis
5. **UserStoryChecklistsSection** - Show attached checklists

Sample API calls from frontend:

```javascript
// Get all user stories
await fetch(`/api/projects/${projectId}/user-stories`)

// Create user story
await fetch(`/api/projects/${projectId}/user-stories`, {
  method: 'POST',
  body: JSON.stringify(userStoryData)
})

// Generate checklist from Arxis
await fetch(`/api/projects/${projectId}/user-stories/${storyId}/generate-from-arxis`, {
  method: 'POST'
})

// Attach checklist
await fetch(`/api/projects/${projectId}/user-stories/${storyId}/attach-checklist`, {
  method: 'POST',
  body: JSON.stringify({ checklist_id: checklistId })
})
```

## Security Considerations

1. **Role-based Access** - Only "chef" role can:
   - Create/Edit/Delete user stories
   - Generate checklists from Arxis
   - Attach/Detach checklists

2. **API Key Security** - Never commit `.env` files with API keys

3. **Data Validation** - All inputs are validated before processing

## Troubleshooting

### Migrations Not Running
```bash
php artisan migrate:fresh  # WARNING: This drops all data
```

### Arxis API Not Working
1. Verify API key in `.env`
2. Check Arxis API status at https://status.arxis.io
3. Test connection: POST to `{ARXIS_BASE_URL}/health`

### Checklist Generation Fails
1. Ensure user story has title and acceptance criteria
2. Check Arxis API logs for errors
3. Verify API rate limits are not exceeded

## Next Steps

1. Implement Frontend UI components
2. Add batch generation of checklists
3. Add Arxis webhook support for real-time updates
4. Implement checklist version control
5. Add advanced filtering and search capabilities
