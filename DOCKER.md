# Docker Setup Guide

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) and Docker Compose installed
- API credentials:
  - **Reddit API** — client ID, secret, username, password — [create an app](https://www.reddit.com/prefs/apps)
  - **Anthropic API key** — [console.anthropic.com](https://console.anthropic.com/)
  - **OpenAI API key** — [platform.openai.com/api-keys](https://platform.openai.com/api-keys)

## Quick Start

```bash
# 1. Clone the repository
git clone <repository-url>
cd reddit-idea-scrapper

# 2. Copy the example env file and fill in your API keys
# (-n skips this if .env already exists)
cp -n .env.example .env
# Edit .env and set: REDDIT_CLIENT_ID, REDDIT_CLIENT_SECRET, REDDIT_USERNAME,
#                    REDDIT_PASSWORD, ANTHROPIC_API_KEY, OPENAI_API_KEY

# 3. Build and start everything
make install
```

Once complete, the app is available at:
- **App:** http://localhost:8080
- **Vite HMR:** http://localhost:5173

## Available Commands

| Command | Description |
|---------|-------------|
| `make install` | First-time setup — build images, start services, install deps, migrate |
| `make up` | Start all services |
| `make down` | Stop all services |
| `make build` | Rebuild Docker images from scratch |
| `make shell` | Open a bash shell inside the app container |
| `make migrate` | Run pending database migrations |
| `make seed` | Run database seeders |
| `make fresh` | Reset database (migrate:fresh --seed) |
| `make test` | Run PHPUnit test suite |
| `make logs` | View logs from all containers |
| `make queue` | View queue worker logs |
| `make key` | Generate a new Laravel application key |

## Services Overview

| Service | Description | Port |
|---------|-------------|------|
| `app` | PHP 8.3-FPM application server | — |
| `nginx` | Web server (proxies to PHP-FPM) | 8080 |
| `db` | MySQL 8.0 database | 3306 |
| `node` | Vite dev server with HMR | 5173 |
| `queue` | Queue worker — processes fetch, classify, extract, default queues | — |

## Running Without Docker

You can still run the project without Docker using the standard Laravel dev server.

1. Change `DB_HOST` in your `.env` from `db` to `127.0.0.1`
2. Ensure a local MySQL instance is running with the matching credentials
3. Start all processes with:
   ```bash
   composer dev
   ```

## Development vs Production

> **This Docker setup is for development only.**

For a production deployment, the following changes are required:

- Use `queue:work` instead of `queue:listen` for better memory management
- Set a finite `--timeout` on queue workers
- Remove the exposed MySQL port (`3306`) — do not expose databases publicly
- Remove the Vite/Node service — pre-build assets with `npm run build` instead
- Use proper secrets management — do not use hardcoded passwords
- Set `APP_DEBUG=false` and `APP_ENV=production` in your environment

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Port 3306 already in use | Change the port mapping in `docker-compose.yml` or stop your local MySQL |
| Port 8080 already in use | Change the nginx port mapping in `docker-compose.yml` |
| Queue worker not processing jobs | Run `make queue` to check for errors; verify API keys are set in `.env` |
| Jobs running twice / duplicate LLM charges | Ensure `DB_QUEUE_RETRY_AFTER` in `.env` is greater than the queue worker `--timeout` (default: 360 > 300) |
| Vite HMR not working | Ensure port 5173 is accessible from your host; check `vite.config.js` server config |
| Database connection refused | Run `make logs` to check if MySQL is healthy; wait for the health check to pass |
| Permission issues on storage/ or bootstrap/cache/ | Run `make shell` then `chmod -R 775 storage bootstrap/cache` |
