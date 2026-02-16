# Frontend SPA

Vue 3 SPA for working with backend endpoints from `routes/api.php` without Postman.

## Setup

```bash
npm install
```

## Dev run

1. Start Laravel backend (default: `http://127.0.0.1:8000`).
2. Start Reverb websocket server (default: `ws://127.0.0.1:8081`):

```bash
php artisan reverb:start --host=0.0.0.0 --port=8081
```

3. Start frontend:

```bash
npm run serve
```

`vue.config.js` proxies `^/api` to `VUE_APP_BACKEND_URL` (default `http://127.0.0.1:8000`).

## Environment

Use `frontend/.env.example`:

```bash
VUE_APP_BACKEND_URL=http://127.0.0.1:8000
# Optional:
# VUE_APP_API_BASE_URL=http://127.0.0.1:8000/api
VUE_APP_REVERB_APP_KEY=livecode-key
VUE_APP_REVERB_HOST=127.0.0.1
VUE_APP_REVERB_PORT=8081
VUE_APP_REVERB_SCHEME=http
```

## Available pages

- `/` - overview
- `/editor` - full web code editor
- `/profile` - profile settings (name, language, icon/avatar)
- `/register` - registration (`POST /auth/register`)
- `/login` - login (`POST /auth/login`)
- `/explorer` - API explorer with all URIs from `routes/api.php`
- `/forgejo/callback` - helper page for Forgejo OAuth callback

## Editor access logic

- Guest users can edit one local file in browser storage.
- Registered users can create projects and work with project filesystem.
- Forgejo account link and push are available only for authenticated users.

## Build and lint

```bash
npm run lint
npm run build
```
