# Quick Start Guide - Running Checklist Manager Locally

**Status**: Manual testing system ready to deploy on your machine  
**Estimated Setup Time**: 5-10 minutes

## One Command Startup (Recommended)

From the project root, run:

```bash
npm run dev
```

This starts:
- Laravel API on http://localhost:8000
- Queue worker
- Vue frontend on http://localhost:5173 (or next available port)
- MailDev SMTP server on 127.0.0.1:2525
- Mail inbox UI on http://127.0.0.1:8025

---

## Prerequisites (Install First)

- ✅ **PHP 8.2+**
- ✅ **Node.js 18+**
- ✅ **MySQL 8.0+**
- ✅ **Composer** (for PHP packages)
- ✅ **npm** (for Node packages)

---

## Step 1: Setup Backend

```bash
cd backend

# Start Laravel development server
php artisan serve --port=8000

# In another terminal:
# Run queue worker (for async jobs)
php artisan queue:work --timeout=3600
```

✅ Backend will be available at: **http://localhost:8000**

---

## Step 2: Launch Frontend  

```bash
cd frontend

# Start Vue dev server
npm run dev
```

✅ Frontend will be available at: **http://localhost:5173**

---

## Step 3: Configure Database

```bash
cd backend

# Create database tables
php artisan migrate:fresh --seed
```

---

## Step 4: Manual Testing Workflow

1. Open frontend: **http://localhost:5173**
2. **Login** with test credentials:
   - Chef: `chef@test.com` / `password123`
   - Testeur: `testeur@test.com` / `password123`

3. **Create a Project** → **Add Version** → **Assign Checklist**

4. **Execute Tests Manually:**
   - Open the checklist version
   - For each item, update its status using the status dropdown:
     - **Pending** - Not tested yet
     - **Passed** - Test succeeded
     - **Failed** - Test failed
     - **Blocked** - Cannot test (environment issue, etc.)
   - Add comments to document findings
   - Assign tester who performed the test

5. **Track Progress:**
   - View progress bar showing completion percentage
   - See who tested each item and when
   - Filter items by status

---

## Step 5: Troubleshooting

### Database connection error
```bash
cd backend
# Check MySQL is running
mysql -u root -p

# Initialize database
php artisan migrate:fresh --seed
```

### Port 8000 already in use
```bash
php artisan serve --port=8001
# Update frontend API config to point to new port
```

### Frontend can't authenticate
Make sure `.env` in backend has:
```env
SANCTUM_STATEFUL_DOMAINS=localhost:5173
APP_URL=http://localhost:8000
```

---

## User Roles

**Admin System**: Full access to all features
→ Email: `admin@test.com` / Password: `password123`

**Chef de Projet**: Create projects, manage checklists, run tests
→ Email: `chef@test.com` / Password: `password123`

**Admin Contenus**: Quality review, archive checklists, run tests
→ Email: `admin_contenus@test.com` / Password: `password123`

**Testeur**: Execute tests, update status, add comments
→ Email: `testeur@test.com` / Password: `password123`

---

## Next Steps

- Read [ROLES_AND_PERMISSIONS.md](./ROLES_AND_PERMISSIONS.md) for detailed permission matrix
- Check [README.md](./README.md) for project structure
- Review backend [README.md](./backend/README.md) for API documentation

---

## Next Steps

Once running locally:

1. **Explore the Dashboard** - See test results in real-time
2. **Create More Tests** - Add UI, API, GitHub tests
3. **Set Up Secrets** - Encrypt credentials for API tests
4. **Configure CI/CD** - Push to GitHub and trigger tests via Actions
5. **Monitor Artifacts** - View screenshots and traces

---

## Support

📖 Full docs: See `SETUP_GUIDE.md` and `IMPLEMENTATION_SUMMARY.md`  
🎬 Architecture: Check `IMPLEMENTATION_SUMMARY.md` for diagrams  
🔧 API Reference: See controllers in `backend/app/Http/Controllers/Api/`

---

**You are now ready to execute automated Playwright tests from your Laravel app!** 🚀

