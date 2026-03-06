# Frontend (Vue)

This frontend is a standalone Vue app and is intentionally outside the Laravel backend.

Project structure:

- `backend/` → Laravel API only
- `frontend/` → Vue UI only

## Environment

Create `.env` inside `frontend/` from `.env.example`:

```sh
cp .env.example .env
```

Set the API base URL:

```env
VITE_API_URL=http://127.0.0.1:8000/api
```

## Run frontend

```sh
cd frontend
npm install
npm run dev
```

## Run backend (separately)

```sh
cd backend
php artisan serve
```

Frontend and backend run as two separate processes.

## Build

```sh
npm run build
```
