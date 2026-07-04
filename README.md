<div align="center">
  <h1>LiveCode</h1>
  <p><strong>Collaborative cloud IDE — code, terminal, Git, all in one browser workspace.</strong></p>
  <p>
    <img src="https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php" alt="PHP">
    <img src="https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel" alt="Laravel">
    <img src="https://img.shields.io/badge/Vue-3-4FC08D?logo=vue.js" alt="Vue 3">
    <img src="https://img.shields.io/badge/PostgreSQL-15-4169E1?logo=postgresql" alt="PostgreSQL">
    <img src="https://img.shields.io/badge/WebSocket-Reverb-FF6B35" alt="WebSocket">
    <img src="https://img.shields.io/badge/license-MIT-blue" alt="License">
  </p>
</div>

---

## Overview

**LiveCode** is a full-stack, real-time collaborative coding platform. It brings together a browser-based code editor, in-project terminal sessions, Git integration via Forgejo, task management with Kanban, and instant collaboration — all within a single workspace.

Built with a **Laravel 12 + PostgreSQL** backend and a **Vue 3 SPA** frontend, with **Laravel Reverb** WebSocket broadcasting for real-time features and a dedicated **Node.js WebSocket gateway** for terminal PTY sessions.

---

## Features

### Code Editor
- Browser-based code editor powered by **Ace** with syntax highlighting for 120+ languages
- Project-based file tree — create, read, write, rename, move, delete, download files
- Open in **guest mode** (no account required) or within authenticated projects

### Real-time Collaboration
- **Operational Transformation (OT)** — concurrent editing with conflict resolution
- Live presence indicators — see who else is in the editor
- In-editor chat panel for collaborators
- Heartbeat-based session tracking

### In-browser Terminal
- Full xterm.js terminal inside each project workspace
- WebSocket-to-PTY bridge via dedicated **Node.js gateway**
- **Docker-isolated sandbox** for multi-tenant security
- Configurable idle/hard timeouts, resource limits, and read-only filesystem

### Git & Forgejo Integration
- Self-hosted **Forgejo** (Gitea fork) as the Git backend
- Create branches, check out commits directly from the editor
- Push projects to Forgejo repositories
- Create pull requests without leaving the browser

### Task Management
- Full CRUD for project tasks
- **Kanban board** with drag-and-drop workflow
- Status workflow: open → in progress → done
- Task assignment, priorities, and filtering

### Code Annotations
- Inline code comments on specific lines
- Persistent across sessions — useful for code review

### Notifications
- Real-time in-app notifications via WebSocket
- Unread badge counter with polling
- Mark individual or all notifications as read
- Navigate directly to the relevant project from a notification

### Admin Panel
- User management (search, edit, block, delete)
- Admin account management
- Project overview and participant management
- Terminal session monitoring

### Authentication
- Email registration with verification codes
- Login with optional **Yandex SmartCaptcha** (also supports Cloudflare Turnstile and Google reCAPTCHA)
- API authentication via **Laravel Sanctum**
- Forgejo OAuth integration

### Internationalization
- English and Russian language support via **vue-i18n**

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| **Backend** | Laravel 12, PHP 8.2+ |
| **Frontend SPA** | Vue 3, Vue Router, Vue I18n |
| **Database** | PostgreSQL 15 |
| **Cache / Queue** | Redis 7 |
| **Real-time** | Laravel Reverb (WebSocket), Laravel Echo |
| **Code Editor** | Ace (via ace-builds) |
| **Terminal** | xterm.js + Node.js WebSocket-PTY gateway |
| **Git Backend** | Forgejo (self-hosted) |
| **Containerization** | Docker (terminal sandbox) |
| **API** | RESTful with Sanctum token auth |
| **Build** | Vue CLI 5 (frontend), Vite (backend assets) |

---

## Architecture

```
┌─────────────┐     ┌──────────────────┐     ┌──────────────┐
│  Vue 3 SPA  │────▶│  Laravel API     │────▶│  PostgreSQL  │
│  (frontend) │     │  (REST + Reverb) │     │  + Redis     │
└──────┬──────┘     └────────┬─────────┘     └──────────────┘
       │                     │
       │  WebSocket          │  HTTP Callback
       ▼                     ▼
┌──────────────┐     ┌──────────────┐
│  Terminal    │◀───▶│  Forgejo     │
│  Gateway     │     │  (Git)       │
│  (Node.js)   │     └──────────────┘
└──────┬───────┘
       │
       │  Docker
       ▼
┌──────────────┐
│  Sandbox     │
│  Containers  │
└──────────────┘
```

---

## Quick Start

### Prerequisites

- PHP 8.2+, Composer
- Node.js 20+
- PostgreSQL 15, Redis 7
- Docker (optional, for terminal sandbox)

### Setup

```bash
# Clone the repository
git clone https://github.com/Chengazin/livecode.git
cd livecode

# Install dependencies
composer install
npm install

# Environment
cp .env.example .env
php artisan key:generate

# Database
php artisan migrate

# Frontend (Vue 3 SPA)
cd frontend
npm install
npm run build
cd ..

# Start development servers
composer run dev
```

### Dev Servers

```bash
# Laravel backend
php artisan serve

# Queue worker
php artisan queue:listen

# WebSocket server (Reverb)
php artisan reverb:start

# Frontend dev server
cd frontend && npm run serve

# Terminal gateway
cd terminal-gateway && npm run dev
```

Or use the Windows dev script:

```powershell
.\dev.ps1
```

---

## Project Structure

```
├── app/                    # Laravel backend
│   ├── Events/             # Broadcasting events
│   ├── Http/
│   │   ├── Controllers/    # 23 API controllers
│   │   ├── Middleware/     # Auth, admin middleware
│   │   └── Requests/       # Form request validation
│   ├── Models/             # Eloquent models
│   └── Services/           # Business logic layer
├── frontend/               # Vue 3 SPA
│   └── src/
│       ├── pages/          # Route pages
│       ├── components/     # UI components
│       ├── composables/    # Vue composables
│       ├── services/       # API and feature services
│       └── i18n/           # Localization
├── terminal-gateway/       # Node.js WebSocket-PTY bridge
├── docker/                 # Terminal sandbox Dockerfile
├── docker-compose.yml      # Forgejo + Redis + PostgreSQL
├── services/               # Native Windows Forgejo service
├── routes/
│   ├── api.php             # API routes
│   ├── channels.php        # Broadcasting channel auth
│   └── web.php             # Web routes
├── config/                 # Laravel configuration
├── database/migrations/    # Database migrations
└── tests/                  # PHPUnit tests
```

---

## API Overview

The backend exposes a RESTful API organized around projects:

| Endpoint | Description |
|----------|-------------|
| `POST /api/register/*` | Registration flow with email verification |
| `POST /api/login` | Authentication |
| `GET/PATCH /api/me/profile` | User profile management |
| `CRUD /api/projects` | Project management |
| `POST/GET/PUT/DELETE /api/projects/{id}/filesystem/**` | File operations |
| `POST /api/projects/{id}/git/*` | Git branch and commit operations |
| `POST /api/projects/{id}/forgejo/*` | Forgejo push, PR, sync |
| `WebSocket` channels | Real-time editor sync, presence, chat |
| `POST /api/projects/{id}/speech/transcribe` | Speech-to-text |
| `CRUD /api/projects/{id}/tasks` | Task management |
| `CRUD /api/projects/{id}/code-comments` | Code annotations |

---

## Testing

```bash
composer run test
```

---

## License

This project is open-sourced under the [MIT license](LICENSE).

---

<div align="center">
  <p>
    Built by <a href="https://github.com/Chengazin">Alexander</a>
  </p>
</div>
