# Quick Setup: User Stories & Arxis Integration

## Prerequisites
- ✅ Backend running (Laravel)
- ✅ Database configured

## Step 1: Add Arxis API Key

1. Open `backend/.env`
2. Add these lines:
```env
ARXIS_BASE_URL=https://api.arxis.io/v1
ARXIS_API_KEY=your-arxis-api-key-here
```

Get your API key from: https://arxis.io/dashboard/api

## Step 2: Run Migrations

```bash
cd backend
php artisan migrate
```

This creates:
- `user_stories` table
- `user_story_checklists` table

## Step 3: Test the API

### Create a User Story
```bash
curl -X POST http://localhost:8000/api/projects/1/user-stories \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "User can login",
    "description": "Login functionality",
    "acceptance_criteria": "User enters credentials and gets authenticated",
    "priority": "high"
  }'
```

### Generate Checklist from Arxis
```bash
curl -X POST http://localhost:8000/api/projects/1/user-stories/1/generate-from-arxis \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## New API Routes

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/projects/{id}/user-stories` | List user stories |
| POST | `/api/projects/{id}/user-stories` | Create user story |
| GET | `/api/projects/{id}/user-stories/{story_id}` | Get user story |
| PUT | `/api/projects/{id}/user-stories/{story_id}` | Update user story |
| DELETE | `/api/projects/{id}/user-stories/{story_id}` | Delete user story |
| POST | `/api/projects/{id}/user-stories/{story_id}/generate-from-arxis` | Generate checklist |
| POST | `/api/projects/{id}/user-stories/{story_id}/attach-checklist` | Attach existing checklist |
| DELETE | `/api/projects/{id}/user-stories/{story_id}/checklists/{checklist_id}` | Detach checklist |

## Data Structure

### Project → User Stories → Checklists

Each project can have multiple user stories. Each user story can have multiple checklists:
- Auto-generated from Arxis
- Manually linked

## Files Created/Modified

### New Files
- `backend/app/Models/UserStory.php` - User story model
- `backend/app/Http/Controllers/Api/UserStoryController.php` - API controller
- `backend/app/Services/ArxisService.php` - Arxis API integration
- `backend/database/migrations/2026_04_18_create_user_stories_table.php`
- `backend/database/migrations/2026_04_18_create_user_story_checklists_table.php`

### Modified Files
- `backend/app/Models/Project.php` - Added `userStories()` relationship
- `backend/app/Models/Checklist.php` - Added `userStories()` relationship
- `backend/routes/api.php` - Added user story routes
- `backend/config/services.php` - Added Arxis configuration
- `backend/.env.example` - Added Arxis variables

## Frontend Next Steps

1. Create Vue components for:
   - User stories list view
   - User story form
   - Generate checklist button
   - Checklist attachment UI

2. Add to router:
   - `/projects/:id/user-stories` - List view
   - `/projects/:id/user-stories/new` - Create form
   - `/projects/:id/user-stories/:storyId` - Detail view

3. Update existing project view to include user stories tab

## Troubleshooting

**Migration fails:**
```bash
# Check database connection
php artisan migrate:status

# Run specific migration
php artisan migrate --step=1
```

**Arxis API error:**
- Verify API key is valid
- Check internet connection
- Review Arxis API logs

**User story not found:**
- Verify project ID exists
- Check authorization (must be chef role)
- Confirm user story belongs to the project

## Documentation

Full documentation: See `USER_STORIES_ARXIS_GUIDE.md`
