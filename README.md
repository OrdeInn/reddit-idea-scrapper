# Reddit Idea Scrapper

A SaaS opportunity discovery tool that scans Reddit subreddits to surface validated business ideas. It fetches posts and comments, runs them through a dual-LLM classification pipeline, and extracts structured SaaS opportunities with market analysis, viability scores, and branding suggestions.

---

## What It Does

1. **Scans subreddits** — fetches posts and comments from a configurable date range via the Reddit API
2. **Classifies content** — a dual-gate consensus filter (Anthropic Haiku + OpenAI GPT-4o-mini) scores each post on how likely it contains a real problem or opportunity
3. **Extracts ideas** — passing posts are processed by Anthropic Sonnet to extract structured SaaS ideas including problem statement, solution, target audience, monetization paths, branding suggestions, competitor analysis, and a 5-dimensional score
4. **Presents results** — a clean web UI lets you filter, sort, star, and deep-dive into any extracted idea

Designed for solo developers and small teams looking for buildable, validated SaaS ideas without manually reading thousands of Reddit posts.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend framework | Laravel 12 (PHP 8.2+) |
| Frontend | Vue 3 + Inertia.js + Tailwind CSS 4 |
| Build tool | Vite |
| Queue system | Laravel Horizon + Redis |
| Database | MySQL 8.0 |
| LLM — classification | Anthropic Haiku + OpenAI GPT-4o-mini |
| LLM — extraction | Anthropic Sonnet |
| External data | Reddit API (OAuth) |
| Containerization | Docker + Docker Compose |

---

## Quick Start

### Prerequisites

- Docker and Docker Compose
- A Reddit app (script type) — [create one here](https://www.reddit.com/prefs/apps)
- An [Anthropic API key](https://console.anthropic.com/)
- An [OpenAI API key](https://platform.openai.com/api-keys)

### 1. Clone and configure

```bash
git clone <repository-url>
cd reddit-idea-scrapper
cp .env.example .env
```

Open `.env` and fill in the required credentials:

```env
# Reddit API
REDDIT_CLIENT_ID=your_client_id
REDDIT_CLIENT_SECRET=your_client_secret
REDDIT_USERNAME=your_reddit_username
REDDIT_PASSWORD=your_reddit_password
REDDIT_USER_AGENT="SaaSScanner/1.0 by YourRedditUsername"

# LLM
ANTHROPIC_API_KEY=your_anthropic_key
OPENAI_API_KEY=your_openai_key
```

### 2. Install and start

```bash
make install
```

This will build Docker images, install Composer and NPM dependencies, run database migrations, and start all services.

### 3. Open the app

- App: [http://localhost:8080](http://localhost:8080)
- Horizon (queue dashboard): [http://localhost:8080/horizon](http://localhost:8080/horizon)

---

## Make Commands

| Command | Description |
|---|---|
| `make install` | First-time setup (build, migrate, start) |
| `make up` | Start all services |
| `make down` | Stop all services |
| `make shell` | Open a bash shell in the app container |
| `make migrate` | Run database migrations |
| `make test` | Run PHPUnit test suite |
| `make queue` | Tail queue worker logs |
| `make horizon` | Open Horizon dashboard link |

---

## Architecture Overview

```
User (Vue 3 UI)
      │
      ▼
Laravel Controllers
      │
      ├── MySQL (Scans, Posts, Ideas, Classifications)
      │
      └── Redis Queue (Laravel Horizon)
               │
               ├── fetch     → Reddit API → raw posts & comments
               ├── classify  → Haiku + GPT-4o-mini dual-gate filter
               └── extract   → Sonnet → structured SaaS idea objects
```

### Processing Pipeline

1. **Fetch** — Reddit API is polled with OAuth. Posts and comments are stored raw. Pagination is cursor-based so scans are resumable.
2. **Classify** — Posts are chunked and sent to both Haiku and GPT-4o-mini. A consensus score determines keep / borderline / discard. Only keep and borderline posts advance.
3. **Extract** — Sonnet processes each qualifying post in chunks and returns structured idea objects with full market analysis and scores.
4. **Finalize** — Scan status is updated and subreddit statistics are refreshed.

### Scan Behavior

- **Initial scan:** fetches the last 3 months of posts
- **Rescan:** fetches the last 2 weeks + refreshes comments on previously high-value posts

---

## Configuration

LLM providers, models, chunk sizes, and timeouts are configured in `config/llm.php`. Key env variables:

```env
LLM_CLASSIFICATION_CHUNK_SIZE=10   # posts per classification job
LLM_EXTRACTION_CHUNK_SIZE=5        # posts per extraction job
```

Queue driver defaults to Redis. To use the database driver instead, set `QUEUE_CONNECTION=database` in `.env`.

---

## Docker Services

| Service | Purpose | Port |
|---|---|---|
| `app` | PHP 8.3-FPM (Laravel) | — |
| `nginx` | Web server | 8080 |
| `db` | MySQL 8.0 | 3306 |
| `redis` | Cache + queue backend | 6379 |
| `node` | Vite dev server (HMR) | 5173 |
| `queue` | Laravel Horizon worker | — |

---

## License

MIT
