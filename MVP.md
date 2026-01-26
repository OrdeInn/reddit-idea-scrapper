# Reddit SaaS Idea Scanner

## Project Specification & MVP Plan

---

## 1. Vision & Purpose

### What Is This?

A tool that scans Reddit subreddits to discover viable SaaS business opportunities. It analyzes posts and comments where people discuss problems, request tools, or express frustrations — then extracts actionable SaaS ideas with market analysis, branding suggestions, and viability scores.

### Who Is It For?

Solo developers and small teams (2-3 people) looking for validated SaaS ideas that are:

- Buildable in weeks/months, not years
- Have clear monetization paths
- Don't require massive infrastructure
- Have identifiable, reachable audiences
- Feasible to brand and market without a big budget

### Core Value Proposition

**Input:** A subreddit name
**Output:** A curated list of SaaS opportunities with scores, market fit analysis, and actionable next steps — filtered for small team viability.

---

## 2. MVP Scope

### In Scope

| Feature | Description |
|---------|-------------|
| Manual subreddit scanning | User inputs subreddit name, triggers scan |
| Incremental rescanning | First scan: 3 months. Rescan: 2 weeks + high-value post refresh |
| Dual-gate classification | Haiku + GPT-4o-mini consensus filtering |
| Idea extraction & enrichment | Sonnet 4.5 full analysis |
| Subreddit-scoped results | Ideas grouped by source subreddit |
| Expandable row details | Collapsed summary, expand for full analysis |
| Filtering & sorting | By scores, date, starred status |
| Favorites | Star ideas, view starred list |
| LLM abstraction | Swappable providers via interface |
| Raw data storage | All pipeline stages persisted |

### Out of Scope (Future Versions)

- Scheduled automatic scans
- Real-time monitoring
- Idea clustering / deduplication
- Cross-subreddit insights & analytics
- Detailed competitor deep-dives
- Export to CSV / Notion
- Multi-user / authentication / billing
- Community voting on ideas

---

## 3. System Architecture

### High-Level Flow

```
┌──────────────────────────────────────────────────────────────────────────┐
│                                                                          │
│   USER                                                                   │
│     │                                                                    │
│     │ enters subreddit                                                   │
│     ▼                                                                    │
│   ┌─────────────────┐                                                    │
│   │   WEB UI        │                                                    │
│   │   (Laravel)     │                                                    │
│   └────────┬────────┘                                                    │
│            │                                                             │
│            │ triggers scan                                               │
│            ▼                                                             │
│   ┌─────────────────┐      ┌─────────────────┐      ┌─────────────────┐ │
│   │   JOB 1         │      │   JOB 2         │      │   JOB 3         │ │
│   │   FETCH         │ ───▶ │   CLASSIFY      │ ───▶ │   EXTRACT       │ │
│   │                 │      │   (Dual-Gate)   │      │   (Sonnet 4.5)  │ │
│   │   Reddit API    │      │   Haiku + GPT   │      │                 │ │
│   └─────────────────┘      └─────────────────┘      └─────────────────┘ │
│            │                        │                        │           │
│            ▼                        ▼                        ▼           │
│   ┌──────────────────────────────────────────────────────────────────┐  │
│   │                         DATABASE                                  │  │
│   │   raw_posts | raw_comments | classifications | ideas             │  │
│   └──────────────────────────────────────────────────────────────────┘  │
│                                                                          │
└──────────────────────────────────────────────────────────────────────────┘
```

### Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | Laravel 11 (PHP 8.3) |
| Queue | Laravel Horizon + Redis |
| Database | MySQL 8 |
| Frontend | Livewire 3 + Alpine.js + Tailwind CSS |
| LLM Providers | Claude (Haiku, Sonnet), OpenAI (GPT-4o-mini) |
| External API | Reddit API (OAuth) |

---

## 4. Pipeline Detail

### Job 1: Fetch

**Purpose:** Retrieve posts and comments from Reddit.

**Scan Strategy:**

| Scan Type | Behavior |
|-----------|----------|
| First scan | 3 months back, all posts above interaction threshold |
| Rescan | Last 2 weeks for new posts + refresh comments on high-value existing posts |

**Interaction Thresholds (configurable):**

```
min_upvotes: 5
min_comments: 3
```

**Reddit API Handling:**

- OAuth authentication (script type app)
- Max 100 items per request
- 1-second delay between requests
- Pagination via `after` parameter
- Use search endpoint with timestamp filters for date ranges
- Store checkpoint for resumable scans

**Output:** Raw posts and comments stored with metadata.

**Data Stored:**

```
posts:
  - reddit_id
  - subreddit
  - title
  - body
  - author
  - upvotes
  - num_comments
  - created_at (Reddit timestamp)
  - fetched_at
  - permalink

comments:
  - reddit_id
  - post_id (FK)
  - parent_id (for nested replies)
  - body
  - author
  - upvotes
  - created_at
  - fetched_at
```

---

### Job 2: Classify (Dual-Gate)

**Purpose:** Filter out garbage before expensive extraction.

**Models Used:**

- Claude Haiku 3.5
- GPT-4o-mini

**Process:**

1. Run both models in parallel on each post + its comments
2. Each returns verdict + confidence score
3. Apply consensus logic to decide KEEP / DISCARD / BORDERLINE

**Prompt Template (both models):**

```
Analyze this Reddit post and its comments. Determine if it contains 
a genuine problem, pain point, or tool request that could inspire 
a SaaS product idea.

POST:
{title}
{body}

COMMENTS:
{comments}

Respond in JSON:
{
  "verdict": "keep" | "skip",
  "confidence": 0.0 - 1.0,
  "category": "genuine-problem" | "tool-request" | "advice-thread" | "spam" | "self-promo" | "rant-no-solution" | "meme-joke" | "other",
  "reasoning": "brief explanation"
}
```

**Consensus Logic:**

```
score = (haiku_confidence × haiku_keep + gpt_confidence × gpt_keep) / 2

where: keep = 1, skip = 0

Thresholds:
  score >= 0.6  →  KEEP
  score < 0.4   →  DISCARD
  0.4 - 0.6     →  BORDERLINE (keep but flag)
```

**Shortcut Rules:**

- Both SKIP with confidence > 0.8 → DISCARD immediately
- Both KEEP with confidence > 0.8 → KEEP immediately

**Data Stored:**

```
classifications:
  - post_id (FK)
  - haiku_verdict
  - haiku_confidence
  - haiku_category
  - haiku_reasoning
  - gpt_verdict
  - gpt_confidence
  - gpt_category
  - gpt_reasoning
  - combined_score
  - final_decision (keep | discard | borderline)
  - classified_at
```

---

### Job 3: Extract

**Purpose:** Generate complete SaaS idea analysis from promising posts.

**Model Used:** Claude Sonnet 4.5

**Input:** Posts that passed classification (KEEP or BORDERLINE)

**Prompt Template:**

```
You are a SaaS opportunity analyst. Analyze this Reddit post and comments 
to extract viable SaaS business ideas suitable for solo developers or 
small teams (2-3 people).

CONTEXT:
- Ideas should be buildable in weeks/months, not years
- Target audience must be identifiable and reachable
- Clear monetization path required
- Marketing should be feasible without large budget

POST:
Subreddit: r/{subreddit}
Title: {title}
Body: {body}
Upvotes: {upvotes}
Comments: {num_comments}

COMMENTS:
{comments}

If you identify viable SaaS idea(s), respond with a JSON array. 
If no viable ideas exist, return an empty array.

Each idea should contain:
{
  "idea_title": "Short catchy name for the concept",
  "problem_statement": "What specific pain point does this solve?",
  "proposed_solution": "High-level product description (2-3 sentences)",
  "target_audience": "Who pays for this? Be specific.",
  "why_small_team_viable": "Why this doesn't need a large company to build",
  "demand_evidence": "What in the post/comments suggests people want this?",
  "monetization_model": "How would this make money? (SaaS subscription, usage-based, etc.)",
  "branding_suggestions": {
    "name_ideas": ["Name1", "Name2", "Name3"],
    "positioning": "One-line positioning statement",
    "tagline": "Marketing tagline"
  },
  "marketing_channels": ["Channel 1", "Channel 2", "Channel 3"],
  "existing_competitors": ["Competitor 1", "Competitor 2"] or "None identified",
  "scores": {
    "monetization": 1-5,
    "monetization_reasoning": "Why this score",
    "market_saturation": 1-5,
    "saturation_reasoning": "Why this score (5 = wide open, 1 = crowded)",
    "complexity": 1-5,
    "complexity_reasoning": "Why this score (5 = easy to build, 1 = very complex)",
    "demand_evidence": 1-5,
    "demand_reasoning": "Why this score",
    "overall": 1-5,
    "overall_reasoning": "Holistic assessment"
  },
  "source_quote": "The specific text from post/comment that inspired this idea"
}
```

**Data Stored:**

```
ideas:
  - id
  - post_id (FK)
  - subreddit_scan_id (FK)
  - idea_title
  - problem_statement
  - proposed_solution
  - target_audience
  - why_small_team_viable
  - demand_evidence
  - monetization_model
  - branding_suggestions (JSON)
  - marketing_channels (JSON)
  - existing_competitors (JSON)
  - scores (JSON)
  - source_quote
  - classification_status (from Job 2: keep | borderline)
  - is_starred (boolean)
  - starred_at (timestamp)
  - created_at
  - updated_at
```

---

## 5. Data Model

### Entity Relationship

```
┌─────────────────┐       ┌─────────────────┐
│  subreddits     │       │  scans          │
├─────────────────┤       ├─────────────────┤
│  id             │◄──┐   │  id             │
│  name           │   │   │  subreddit_id   │──┐
│  created_at     │   │   │  scan_type      │  │
│  updated_at     │   │   │  status         │  │
└─────────────────┘   │   │  date_from      │  │
                      │   │  date_to        │  │
                      │   │  posts_fetched  │  │
                      │   │  started_at     │  │
                      │   │  completed_at   │  │
                      │   └─────────────────┘  │
                      │                        │
                      │   ┌─────────────────┐  │
                      │   │  posts          │  │
                      │   ├─────────────────┤  │
                      │   │  id             │  │
                      └───│  subreddit_id   │  │
                          │  scan_id        │◄─┘
                          │  reddit_id      │
                          │  title          │
                          │  body           │
                          │  author         │
                          │  upvotes        │
                          │  num_comments   │
                          │  permalink      │
                          │  reddit_created │
                          │  fetched_at     │
                          └────────┬────────┘
                                   │
                    ┌──────────────┼──────────────┐
                    │              │              │
                    ▼              ▼              ▼
          ┌─────────────┐  ┌─────────────┐  ┌─────────────┐
          │  comments   │  │ classific.  │  │  ideas      │
          ├─────────────┤  ├─────────────┤  ├─────────────┤
          │  id         │  │  id         │  │  id         │
          │  post_id    │  │  post_id    │  │  post_id    │
          │  reddit_id  │  │  haiku_*    │  │  scan_id    │
          │  parent_id  │  │  gpt_*      │  │  idea_title │
          │  body       │  │  combined_* │  │  scores     │
          │  author     │  │  final_dec  │  │  branding   │
          │  upvotes    │  │  created_at │  │  is_starred │
          │  created_at │  └─────────────┘  │  created_at │
          └─────────────┘                   └─────────────┘
```

### Key Indexes

```sql
-- Fast lookups
CREATE INDEX idx_posts_subreddit ON posts(subreddit_id);
CREATE INDEX idx_posts_reddit_id ON posts(reddit_id);
CREATE INDEX idx_posts_upvotes ON posts(upvotes);
CREATE INDEX idx_ideas_scan ON ideas(subreddit_scan_id);
CREATE INDEX idx_ideas_starred ON ideas(is_starred, starred_at);
CREATE INDEX idx_classifications_decision ON classifications(final_decision);
```

---

## 6. UI/UX Specification

### Pages

#### 1. Dashboard / Home

- List of scanned subreddits as cards
- Each card shows: subreddit name, last scan date, idea count, top score
- "Add Subreddit" button
- Click card → goes to subreddit detail page

#### 2. Subreddit Detail Page

- Header: subreddit name, scan history, "Rescan" button
- Scan status indicator (if scan in progress)
- Ideas table (main content)

#### 3. Ideas Table (Core UI)

**Collapsed Row Shows:**

| Column | Description |
|--------|-------------|
| Star toggle | ☆ / ★ |
| Idea Title | Short name |
| Overall Score | 1-5 with visual indicator |
| Target Audience | Brief |
| Complexity | 1-5 |
| Source | Link to Reddit |
| Date Found | When extracted |

**Expanded Row Shows:**

- Full problem statement
- Proposed solution
- All scores with reasoning
- Branding suggestions (names, positioning, tagline)
- Marketing channels
- Competitors
- Source quote from Reddit
- Classification status (borderline flag if applicable)

#### 4. Starred Ideas Page

- Same table format as subreddit detail
- Pulls from all subreddits
- Additional column showing source subreddit

### Filtering & Sorting

**Filters:**

- Overall score: minimum threshold slider (1-5)
- Complexity: minimum threshold (1-5)
- Date range: when idea was found
- Starred only: toggle
- Include borderline: toggle (default on)

**Sorting:**

- By any score column (asc/desc)
- By date found
- By source post upvotes

### UI States

```
Scan States:
  - idle: "Scan" button enabled
  - fetching: "Fetching posts..." with progress
  - classifying: "Classifying posts..." with progress  
  - extracting: "Extracting ideas..." with progress
  - complete: Show results, "Rescan" button enabled
  - error: Show error message, "Retry" button

Empty States:
  - No subreddits yet: "Add your first subreddit to scan"
  - No ideas found: "No SaaS ideas found in this subreddit. Try another?"
  - No starred ideas: "Star ideas from any subreddit to see them here"
```

---

## 7. LLM Abstraction Layer

### Interface

```php
interface LLMProviderInterface
{
    public function classify(ClassificationRequest $request): ClassificationResponse;
    public function extract(ExtractionRequest $request): ExtractionResponse;
    public function getProviderName(): string;
    public function getModelName(): string;
}
```

### Request/Response Objects

```php
class ClassificationRequest
{
    public function __construct(
        public string $postTitle,
        public string $postBody,
        public array $comments,
        public int $upvotes,
    ) {}
}

class ClassificationResponse
{
    public function __construct(
        public string $verdict,      // 'keep' | 'skip'
        public float $confidence,    // 0.0 - 1.0
        public string $category,
        public string $reasoning,
        public array $rawResponse,
    ) {}
}

class ExtractionRequest
{
    public function __construct(
        public string $subreddit,
        public string $postTitle,
        public string $postBody,
        public array $comments,
        public int $upvotes,
        public int $numComments,
    ) {}
}

class ExtractionResponse
{
    public function __construct(
        public array $ideas,         // Array of Idea objects
        public array $rawResponse,
    ) {}
}
```

### Implementations

```
app/Services/LLM/
  ├── LLMProviderInterface.php
  ├── ClaudeHaikuProvider.php
  ├── ClaudeSonnetProvider.php
  ├── OpenAIGPT4MiniProvider.php
  └── LLMProviderFactory.php
```

### Configuration

```php
// config/llm.php
return [
    'classification' => [
        'providers' => ['claude-haiku', 'openai-gpt4-mini'],
        'consensus_threshold_keep' => 0.6,
        'consensus_threshold_discard' => 0.4,
    ],
    'extraction' => [
        'provider' => 'claude-sonnet',
    ],
    'providers' => [
        'claude-haiku' => [
            'class' => ClaudeHaikuProvider::class,
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => 'claude-3-5-haiku-20241022',
        ],
        'claude-sonnet' => [
            'class' => ClaudeSonnetProvider::class,
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => 'claude-sonnet-4-5-20250929',
        ],
        'openai-gpt4-mini' => [
            'class' => OpenAIGPT4MiniProvider::class,
            'api_key' => env('OPENAI_API_KEY'),
            'model' => 'gpt-4o-mini',
        ],
    ],
];
```

---

## 8. Reddit API Integration

### Setup Requirements

1. Create Reddit app at https://www.reddit.com/prefs/apps
2. Select "script" type for personal use
3. Note: client_id and client_secret

### Configuration

```php
// config/reddit.php
return [
    'client_id' => env('REDDIT_CLIENT_ID'),
    'client_secret' => env('REDDIT_CLIENT_SECRET'),
    'username' => env('REDDIT_USERNAME'),
    'password' => env('REDDIT_PASSWORD'),
    'user_agent' => env('REDDIT_USER_AGENT', 'SaaSScanner/1.0'),
    
    'rate_limit' => [
        'requests_per_minute' => 60,
        'delay_between_requests_ms' => 1000,
    ],
    
    'fetch' => [
        'default_timeframe_months' => 3,
        'rescan_timeframe_weeks' => 2,
        'min_upvotes' => 5,
        'min_comments' => 3,
        'posts_per_request' => 100,
    ],
];
```

### Service Class

```php
class RedditService
{
    public function authenticate(): void;
    public function getSubredditPosts(string $subreddit, Carbon $after, Carbon $before): Collection;
    public function getPostComments(string $postId): Collection;
    public function searchSubreddit(string $subreddit, string $query, array $options): Collection;
}
```

### Rate Limiting Strategy

```
┌─────────────────────────────────────────────────────────────────┐
│  RATE LIMIT HANDLING                                            │
├─────────────────────────────────────────────────────────────────┤
│  • Track requests per minute                                    │
│  • 1-second delay between requests (safe default)               │
│  • If rate limited (429), exponential backoff                   │
│  • Store last request timestamp                                 │
│  • Queue jobs respect rate limits via middleware                │
└─────────────────────────────────────────────────────────────────┘
```

---

## 9. Development Phases

### Phase 1: Foundation (Week 1)

**Goal:** Basic project structure and Reddit fetching

- [ ] Laravel project setup
- [ ] Database migrations (all tables)
- [ ] Reddit API service class
- [ ] OAuth authentication flow
- [ ] Fetch posts endpoint (manual test)
- [ ] Fetch comments endpoint (manual test)
- [ ] Basic Subreddit model and controller
- [ ] Simple UI: add subreddit, trigger fetch, view raw posts

**Deliverable:** Can fetch and store posts from any subreddit

### Phase 2: Classification Pipeline (Week 2)

**Goal:** Dual-gate classification working

- [ ] LLM abstraction interface
- [ ] Claude Haiku provider implementation
- [ ] GPT-4o-mini provider implementation
- [ ] Classification job (runs both models)
- [ ] Consensus logic implementation
- [ ] Classification results storage
- [ ] UI: view classification results per post

**Deliverable:** Posts are classified and filtered automatically

### Phase 3: Extraction Pipeline (Week 3)

**Goal:** Full idea extraction and storage

- [ ] Claude Sonnet provider implementation
- [ ] Extraction job with full prompt
- [ ] Ideas storage with all fields
- [ ] Link ideas to source posts
- [ ] UI: basic ideas list view

**Deliverable:** Complete pipeline works end-to-end

### Phase 4: UI Polish (Week 4)

**Goal:** Usable, pleasant interface

- [ ] Dashboard with subreddit cards
- [ ] Subreddit detail page with ideas table
- [ ] Expandable row detail view
- [ ] Filtering and sorting
- [ ] Star/favorite functionality
- [ ] Starred ideas page
- [ ] Scan progress indicators
- [ ] Error handling and empty states

**Deliverable:** MVP ready for personal use

### Phase 5: Refinement (Week 5)

**Goal:** Optimize and stabilize

- [ ] Incremental rescan logic
- [ ] High-value post refresh on rescan
- [ ] Prompt tuning based on real results
- [ ] Cost tracking / usage logging
- [ ] Performance optimization
- [ ] Bug fixes from personal use

**Deliverable:** Stable MVP for ongoing use

---

## 10. Cost Estimation

### Per Subreddit (3 months scan)

| Step | Model | Est. Tokens | Cost |
|------|-------|-------------|------|
| Classification | Haiku | ~1.5M input | ~$0.30 |
| Classification | GPT-4o-mini | ~1.5M input | ~$0.25 |
| Extraction | Sonnet 4.5 | ~600K input, 200K output | ~$8-10 |
| **Total** | | | **~$9-11** |

### Assumptions

- ~1,500 posts in 3 months for moderately active subreddit
- ~40% pass classification = 600 posts to extraction
- Average 1,500 tokens per post+comments

### 4 Subreddits Total: ~$40-50

---

## 11. Environment Variables

```env
# App
APP_NAME="SaaS Scanner"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=saas_scanner
DB_USERNAME=root
DB_PASSWORD=

# Redis (for queues)
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Reddit API
REDDIT_CLIENT_ID=
REDDIT_CLIENT_SECRET=
REDDIT_USERNAME=
REDDIT_PASSWORD=
REDDIT_USER_AGENT="SaaSScanner/1.0 by YourUsername"

# LLM Providers
ANTHROPIC_API_KEY=
OPENAI_API_KEY=
```

---

## 12. Future Considerations (Post-MVP)

### Potential v2 Features

- **Scheduled scans:** Daily/weekly automatic rescans
- **Idea clustering:** Group similar ideas across posts/subreddits
- **Competitor deep-dive:** Web search integration for market research
- **Export:** CSV, Notion, Airtable integration
- **Multi-user:** Auth, teams, shared idea lists
- **Idea validation:** Upvote/downvote, add notes, track progress
- **Alerts:** Notify when high-score idea is found
- **Analytics:** Trends over time, subreddit comparison

### Monetization Ideas (If Productizing)

- Free tier: 1 subreddit, 1 month lookback
- Pro tier: Unlimited subreddits, 6 month lookback, scheduled scans
- Team tier: Shared workspaces, collaboration features

---

## 13. Open Questions

1. **Reddit API pagination limits:** Need to verify if 3-month lookback is reliably achievable via API
2. **Prompt iteration:** Classification and extraction prompts will need tuning based on real results
3. **Duplicate handling:** How aggressively to dedupe similar ideas? (Deferred to v2)
4. **Post comment depth:** Fetch all nested replies or just top-level? (Start with top-level + 1 depth)

---

*Document Version: 1.0*
*Last Updated: January 2025*
*Status: Ready for Development*
