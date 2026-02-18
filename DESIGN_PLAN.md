# Reddit Idea Scrapper — UI/UX Redesign Plan

## Aesthetic Direction: **"Signal Intelligence"**

A data-forward, editorial dashboard aesthetic inspired by Bloomberg Terminal meets modern fintech design. The app surfaces SaaS opportunities from noise — the design should feel like a **precision instrument for discovering signal**. Think: controlled density, sharp typographic hierarchy, purposeful color coding, and a dark-mode-first approach that makes data pop.

**Memorable element**: Score visualization as **radial gauges** with animated fill — not generic badges, but miniature data instruments that communicate quality at a glance.

---

## 1. Typography System

### Font Pairing
- **Display / Headings**: **"Satoshi"** (from Fontshare) — geometric sans with personality, clean but distinctive. Not overused like Inter/Space Grotesk.
- **Body / UI**: **"General Sans"** (from Fontshare) — humanist proportions, excellent legibility at small sizes, pairs naturally with Satoshi.
- **Monospace / Data**: **"JetBrains Mono"** — for scores, stats, code-like elements (subreddit names, scan counters).

### Type Scale (fluid, clamp-based)
```
--text-xs:    clamp(0.6875rem, 0.65rem + 0.1vw, 0.75rem)    /* 11-12px */
--text-sm:    clamp(0.8125rem, 0.78rem + 0.1vw, 0.875rem)   /* 13-14px */
--text-base:  clamp(0.875rem, 0.85rem + 0.12vw, 1rem)       /* 14-16px */
--text-lg:    clamp(1.0625rem, 1rem + 0.15vw, 1.125rem)     /* 17-18px */
--text-xl:    clamp(1.1875rem, 1.1rem + 0.2vw, 1.375rem)    /* 19-22px */
--text-2xl:   clamp(1.5rem, 1.35rem + 0.4vw, 1.875rem)     /* 24-30px */
--text-3xl:   clamp(1.875rem, 1.7rem + 0.5vw, 2.25rem)     /* 30-36px */
```

### Font Weight Usage
- **900 (Black)**: Hero numbers, key stats
- **700 (Bold)**: Page titles, section headers
- **600 (Semibold)**: Card titles, table headers, active nav
- **500 (Medium)**: Body text emphasis, labels
- **400 (Regular)**: Body text, descriptions

---

## 2. Color System

### Light Mode Palette
```
/* Surfaces */
--surface-primary:      #FAFBFC     /* Page background — warm off-white */
--surface-secondary:    #FFFFFF     /* Cards, panels */
--surface-tertiary:     #F3F4F6     /* Nested containers, filter bars */
--surface-elevated:     #FFFFFF     /* Modals, dropdowns (with shadow) */

/* Brand — Deep Teal (unique, not generic indigo) */
--brand-50:   #ECFDF7
--brand-100:  #D1FAE5
--brand-200:  #A7F3D0
--brand-300:  #6EE7B7
--brand-400:  #34D399
--brand-500:  #10B981    /* Primary actions */
--brand-600:  #059669    /* Primary hover */
--brand-700:  #047857
--brand-800:  #065F46
--brand-900:  #064E3B

/* Accent — Electric Amber (for scores, highlights) */
--accent-400: #FBBF24
--accent-500: #F59E0B
--accent-600: #D97706

/* Text */
--text-primary:    #111827    /* Headings, primary content */
--text-secondary:  #4B5563    /* Body, descriptions */
--text-tertiary:   #9CA3AF    /* Captions, placeholders, timestamps */
--text-inverse:    #FFFFFF    /* On dark backgrounds */

/* Borders */
--border-default:  #E5E7EB
--border-subtle:   #F3F4F6
--border-strong:   #D1D5DB

/* Score Colors (distinct, accessible) */
--score-excellent:   #059669  /* 4-5: Emerald green */
--score-good:        #0891B2  /* 3: Cyan/teal */
--score-average:     #D97706  /* 2: Amber */
--score-poor:        #DC2626  /* 1: Red */

/* Status */
--status-success:  #059669
--status-warning:  #D97706
--status-error:    #DC2626
--status-info:     #2563EB
--status-scanning: #7C3AED   /* Purple for active scans — distinctive */
```

### Dark Mode Palette
```
/* Surfaces */
--surface-primary:     #0F1117    /* Deep charcoal, not pure black */
--surface-secondary:   #1A1D27    /* Cards */
--surface-tertiary:    #242836    /* Nested containers */
--surface-elevated:    #2A2E3B    /* Modals */

/* Brand remains the same but adjusted */
--brand-500-dark:  #34D399    /* Slightly lighter for dark bg */

/* Text */
--text-primary:    #F9FAFB
--text-secondary:  #D1D5DB
--text-tertiary:   #6B7280

/* Borders */
--border-default:  #2A2E3B
--border-subtle:   #1F2937
--border-strong:   #374151
```

### Implementation
- Use CSS custom properties defined in `app.css` via `@theme`
- Dark mode via `class="dark"` on `<html>` + `prefers-color-scheme` media query as fallback
- Store preference in `localStorage`, default to system preference

---

## 3. Spacing & Layout System

### Spacing Scale
```
--space-0:   0
--space-1:   0.25rem    /* 4px  — tight internal padding */
--space-2:   0.5rem     /* 8px  — element gaps */
--space-3:   0.75rem    /* 12px — compact sections */
--space-4:   1rem       /* 16px — standard gap */
--space-5:   1.25rem    /* 20px */
--space-6:   1.5rem     /* 24px — section padding */
--space-8:   2rem       /* 32px — major sections */
--space-10:  2.5rem     /* 40px */
--space-12:  3rem       /* 48px — page-level spacing */
--space-16:  4rem       /* 64px */
```

### Layout Grid
- **Sidebar**: 260px (expanded) / 72px (collapsed) / 0px (mobile overlay)
- **Main content max-width**: 1280px, centered with auto margins
- **Card grid**: `grid-cols-1 sm:grid-cols-2 xl:grid-cols-3` with `gap-5`
- **Content padding**: `px-6 py-6` (mobile) → `px-8 py-8` (desktop)
- **Consistent card padding**: `p-5` internally

### Border Radius Scale
```
--radius-sm:   0.375rem   /* 6px  — badges, chips */
--radius-md:   0.5rem     /* 8px  — inputs, buttons */
--radius-lg:   0.75rem    /* 12px — cards */
--radius-xl:   1rem       /* 16px — modals, panels */
--radius-full: 9999px     /* pills, avatars */
```

### Shadow System
```
--shadow-xs:    0 1px 2px rgba(0,0,0,0.05)
--shadow-sm:    0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04)
--shadow-md:    0 4px 6px -1px rgba(0,0,0,0.08), 0 2px 4px -2px rgba(0,0,0,0.04)
--shadow-lg:    0 10px 15px -3px rgba(0,0,0,0.08), 0 4px 6px -4px rgba(0,0,0,0.04)
--shadow-xl:    0 20px 25px -5px rgba(0,0,0,0.08), 0 8px 10px -6px rgba(0,0,0,0.04)
--shadow-glow:  0 0 20px rgba(16,185,129,0.15)  /* Brand glow for focused elements */
```

---

## 4. Component Design Specifications

### 4.1 AppLayout (Sidebar Navigation)

**Current issues**: 64px icon-only sidebar feels cramped and disorienting.

**Redesign**:
- **Collapsible sidebar**: 260px expanded ↔ 72px collapsed, with smooth 200ms transition
- **Collapse toggle**: Chevron button at bottom of sidebar
- **Logo area**: App icon + "SaaS Scanner" text (text hides when collapsed)
- **Nav items**: Icon (20px) + label + optional badge (e.g., count of starred ideas)
- **Active state**: Left border accent (3px brand-500) + subtle background tint
- **Hover state**: Background tint + slight icon scale (1.05)
- **Footer**: User avatar placeholder + settings gear icon
- **Mobile**: Full-screen overlay with slide-in from left, backdrop blur
- **Dark mode toggle**: Sun/moon icon in sidebar footer

**Navigation items**:
1. Dashboard (grid icon)
2. Starred Ideas (star icon) — with count badge
3. Settings (gear icon) — future-proofing
4. Divider
5. Dark mode toggle
6. Collapse toggle

### 4.2 Dashboard Page

**Current issues**: Basic cards, no visual hierarchy, plain empty state.

**Redesign**:
- **Page header**: Large title "Dashboard" + subtitle + "Add Subreddit" button (brand color, prominent)
- **Stats bar** (new): Horizontal strip showing aggregate stats across all subreddits:
  - Total subreddits tracked
  - Total ideas discovered
  - Average score
  - Ideas starred
- **Subreddit cards**: Redesigned as richer data cards:
  - **Top section**: Subreddit name (r/name) as bold heading + active scan pulse indicator
  - **Middle section**: Key metrics in a 2×2 mini grid:
    - Ideas count (with sparkline trend if data available)
    - Top score (with radial gauge mini-visualization)
    - Last scanned (relative time)
    - Scan status indicator
  - **Bottom section**: Subtle gradient bar showing score distribution
  - **Hover**: Lift effect (translateY -2px) + shadow-lg + border color shift
  - **Click target**: Entire card is clickable (already implemented)
- **Empty state**: Illustrated empty state with:
  - Large radar/signal icon (SVG)
  - "No signals detected yet" heading
  - "Add a subreddit to start discovering SaaS opportunities" description
  - Prominent CTA button with pulse animation

### 4.3 Subreddit/Show Page

**Current issues**: Flat layout, basic action buttons, no breadcrumb context.

**Redesign**:
- **Breadcrumb**: Dashboard → r/subredditname (with chevron separator)
- **Header card**: Elevated card containing:
  - Subreddit name (large, bold)
  - Last scanned timestamp
  - Action buttons group (Scan/Cancel, Delete)
  - Quick stats row: ideas count, avg score, top score
- **Scan Progress**: Redesigned as a horizontal pipeline visualization (see 4.6)
- **Ideas section**: Full-width with filter bar integrated into the section header

### 4.4 IdeasTable Component

**Current issues**: Basic table with simple loading spinner, no skeleton states.

**Redesign**:
- **Filter bar**: Redesigned as a collapsible toolbar with:
  - Pill-style filter chips for quick toggles (Score 4+, Starred, etc.)
  - "More filters" expandable section for detailed controls
  - Active filter count badge
  - Clear all button when filters are active
- **Table header**: Sticky, with subtle blur backdrop
- **Loading state**: Skeleton rows (3-5 animated pulse rows matching row layout)
- **Empty state**: Contextual message with filter suggestion
- **Pagination**: Redesigned with:
  - Page number buttons (not just prev/next)
  - "Showing X-Y of Z" text
  - Items-per-page selector
  - Smooth scroll to top on page change

### 4.5 IdeaRow Component

**Current issues**: Basic grid layout, minimal visual differentiation, plain expand/collapse.

**Redesign — Collapsed state**:
- **Star button**: Animated fill transition (empty → filled with scale bounce)
- **Title**: Semibold, with subtle hover underline
- **Score badge**: Radial gauge mini-visualization (SVG circle with stroke-dasharray):
  - Score number centered
  - Circular progress ring colored by score tier
  - Size: 36×36px
- **Tags**: Audience as a subtle chip/tag
- **Borderline badge**: Amber dot indicator instead of text badge
- **Date**: Relative time ("2d ago") with absolute date tooltip

**Redesign — Expanded state**:
- Smooth height animation (CSS grid trick: `grid-template-rows: 0fr → 1fr`)
- **Details card**: Inside a subtle bordered container with left accent stripe (brand color)
- **Problem/Solution**: Side-by-side cards with distinct icons
- **Score breakdown**: 5 radial gauges in a row with labels, animated on expand
- **Branding section**: Name ideas as styled chips with copy-to-clipboard on click
- **Tagline**: In a styled quote block with decorative quotation marks
- **Marketing channels**: Pill chips with relevant emoji/icons
- **Competitors**: Red-tinted chips
- **Source quote**: Blockquote with Reddit orange accent border
- **Reddit link**: Styled button with Reddit icon

### 4.6 ScanProgress Component

**Current issues**: Basic phase indicators, simple progress bar.

**Redesign**:
- **Pipeline visualization**: Horizontal connected nodes with animated connectors:
  - Completed nodes: Filled green with checkmark
  - Active node: Pulsing brand color with spinning ring
  - Future nodes: Hollow gray outlines
  - Connectors: Animated dashed line that fills solid as progress advances
- **Progress bar**: Gradient fill (brand-400 → brand-600) with subtle shimmer animation
- **Stats grid**: Each stat as a mini card with:
  - Large number (monospace font)
  - Label underneath
  - Animated counter (count-up effect on value change)
- **Failed state**: Red-tinted card with error icon, message, and retry button with refresh animation

### 4.7 Modal System

**Current issues**: Basic modal with simple backdrop.

**Redesign**:
- **Backdrop**: `backdrop-blur-sm` + semi-transparent overlay (dark)
- **Entry animation**: Scale from 95% + fade in (200ms ease-out)
- **Exit animation**: Scale to 98% + fade out (150ms ease-in)
- **Modal card**: Rounded-xl, max-width-md, with:
  - Header with title + close button (X icon)
  - Divider
  - Body content with proper padding
  - Footer with action buttons (right-aligned)
- **Focus trap**: Already implemented, keep it
- **Form inputs**: Updated styling (see 4.8)

### 4.8 Form Elements

**Redesign**:
- **Inputs**:
  - Height: 40px
  - Border: 1.5px solid border-default
  - Focus: brand-500 ring (2px) + border color transition
  - Error: red-500 ring + red border + error message below
  - Background: surface-secondary
  - Placeholder: text-tertiary, italic
- **Select dropdowns**: Custom styled with chevron icon
- **Checkboxes**: Custom brand-colored with smooth check animation
- **Buttons**:
  - **Primary**: brand-500 bg, white text, hover: brand-600, active: brand-700
  - **Secondary**: transparent bg, border, text-secondary, hover: surface-tertiary bg
  - **Danger**: red-500 bg, white text (or ghost with red text)
  - **Ghost**: No border, text-secondary, hover: surface-tertiary bg
  - All: 8px radius, 40px height, 150ms transition, focus ring
  - Disabled: 50% opacity, cursor-not-allowed

### 4.9 Toast Notification System (replacing flash messages)

**Current issues**: Flash message is a static bar, position can conflict with content.

**Redesign**:
- **Position**: Bottom-right corner, stacked with 8px gap
- **Auto-dismiss**: 5 seconds with visible countdown bar
- **Variants**: Success (green), Error (red), Warning (amber), Info (blue)
- **Structure**: Icon + message + optional action link + close button
- **Animation**: Slide in from right + fade (300ms spring easing)
- **Exit**: Slide right + fade out (200ms)
- **Max visible**: 3 toasts, oldest auto-dismissed when exceeded
- **Hover**: Pauses auto-dismiss timer

---

## 5. Animation & Transition Specifications

### Page Transitions (Inertia)
```
/* Already configured in app.js progress bar — enhance with page content transition */
Enter: opacity 0 → 1 + translateY 8px → 0 (300ms ease-out, 50ms delay after progress completes)
Leave: opacity 1 → 0 (150ms ease-in)
```

### Component Transitions
```
/* Sidebar collapse */
width: 260px ↔ 72px (200ms ease-in-out)

/* Card hover lift */
transform: translateY(0) → translateY(-2px) (150ms ease-out)
box-shadow: shadow-sm → shadow-lg (150ms)

/* Score gauge fill on mount */
stroke-dashoffset: full → calculated (600ms ease-out, staggered 50ms per gauge)

/* Expand/collapse (IdeaRow) */
grid-template-rows: 0fr → 1fr (250ms ease-out)
opacity: 0 → 1 (200ms, 50ms delay)

/* Star toggle */
scale: 1 → 1.2 → 1 (200ms spring)
fill: none → currentColor (150ms)

/* Skeleton pulse */
opacity: 0.4 → 0.8 → 0.4 (1.5s ease-in-out infinite)
/* Use Tailwind's animate-pulse */

/* Toast enter */
translateX: 100% → 0 (300ms spring)
opacity: 0 → 1

/* Toast exit */
translateX: 0 → 100% (200ms ease-in)
opacity: 1 → 0

/* Modal */
Enter: scale(0.95) → scale(1) + opacity 0 → 1 (200ms ease-out)
Leave: scale(1) → scale(0.98) + opacity 1 → 0 (150ms ease-in)

/* Filter chip toggle */
background-color transition (150ms)
scale: 1 → 0.95 → 1 (100ms, on click)

/* Progress bar shimmer */
linear-gradient position animation (2s infinite)

/* Counter count-up */
Use requestAnimationFrame to increment numbers over 500ms
```

### Performance Rules
- All animations use `transform` and `opacity` only (GPU-composited)
- No `height` or `width` animations directly — use `grid-template-rows` or `max-height` with overflow hidden
- Use `will-change` sparingly and only on elements that will animate
- Prefer CSS transitions over JavaScript animations
- Use `prefers-reduced-motion` media query to disable decorative animations

---

## 6. Layout Improvements

### Responsive Breakpoints
```
sm:  640px    /* Phone landscape */
md:  768px    /* Tablet portrait */
lg:  1024px   /* Tablet landscape / small laptop */
xl:  1280px   /* Desktop */
2xl: 1536px   /* Large desktop */
```

### Mobile Layout (< 1024px)
- Sidebar: Hidden, hamburger menu button → slide-in overlay with backdrop blur
- Cards: Single column, full width
- Ideas table: Stack fields vertically, hide less-important columns
- Filters: Collapsed behind "Filters" button, slide-down panel
- Score gauges: Smaller (28×28px)
- Modals: Full-width with small margin (16px sides)
- Toast notifications: Full-width at bottom, above safe area

### Tablet Layout (768-1023px)
- Sidebar: Same as mobile (overlay)
- Cards: 2 columns
- Ideas table: Condensed grid, show key columns
- Filters: Visible but compact (horizontal scroll on overflow)

### Desktop Layout (≥ 1024px)
- Sidebar: Persistent, collapsible
- Cards: 3 columns (2 with sidebar expanded at 1024-1280px)
- Ideas table: Full grid layout
- Filters: Fully visible toolbar
- Main content: Max 1280px with centering

---

## 7. Accessibility Considerations

### WCAG 2.1 AA Compliance Checklist

**Color contrast**:
- All text meets 4.5:1 ratio against background (use APCA for perceptual accuracy)
- Score colors tested against both light and dark backgrounds
- Focus indicators: 3:1 ratio against adjacent colors
- Do NOT rely on color alone — pair with icons, patterns, or text

**Keyboard navigation**:
- All interactive elements focusable via Tab
- Logical tab order matching visual layout
- Visible focus ring (2px brand-500 ring with 2px offset)
- Escape closes modals, dropdowns, toasts
- Arrow keys for navigation within groups (filter chips, pagination)
- Enter/Space activates buttons and links

**Screen readers**:
- Semantic HTML: `<nav>`, `<main>`, `<header>`, `<aside>`, `<section>`, `<article>`
- ARIA landmarks where semantic HTML is insufficient
- `aria-live="polite"` for scan progress, toast notifications
- `aria-expanded` for collapsible sections
- `aria-label` for icon-only buttons
- `role="progressbar"` with `aria-valuenow` for progress bars
- `role="dialog"` with `aria-modal="true"` for modals
- `aria-describedby` for form error messages
- Announce page changes via Inertia's title updates

**Motion**:
- `prefers-reduced-motion: reduce` — disable all decorative animations
- Keep functional transitions (page changes, expand/collapse) but reduce duration to 1ms
- No auto-playing animations that can't be paused

**Touch targets**:
- Minimum 44×44px touch target for all interactive elements
- Adequate spacing between touch targets (minimum 8px gap)

---

## 8. Performance Considerations

### CSS Performance
- Use Tailwind's JIT/purge (built into v4) — only ship used classes
- Define theme variables via `@theme` in `app.css` — no separate config file needed
- Avoid `@apply` except for truly reusable base styles (buttons, inputs)
- Keep specificity low — utility-first approach

### Animation Performance
- Only animate `transform` and `opacity` (GPU-composited properties)
- Use `contain: layout style paint` on cards for isolation
- Avoid layout thrashing: batch DOM reads before writes
- Skeleton loading instead of spinners (less visual jank)
- Debounce filter changes (300ms) to prevent excessive API calls

### Font Loading
- Use `font-display: swap` for all custom fonts
- Preload critical fonts via `<link rel="preload">`
- Load from Fontshare CDN (or self-host for reliability)
- Subset fonts if possible (latin only)

### Image / SVG
- All icons as inline SVG (already done) — eliminates HTTP requests
- Consider extracting repeated SVGs into a Vue icon component system
- Use `loading="lazy"` for any below-fold images (future)

### Bundle Size
- No additional UI libraries — pure Tailwind + custom Vue components
- No animation libraries (GSAP, Framer Motion) — CSS transitions + requestAnimationFrame
- Tree-shake Vue (already handled by Vite)
- Code-split pages via Inertia's dynamic imports (already configured)

### Runtime Performance
- Virtual scrolling NOT needed (paginated at 20 items)
- Debounce scroll/resize event listeners
- Use `v-memo` for list items that rarely change
- Avoid deep watchers where possible — use computed properties
- Clean up intervals/timeouts/event listeners in `onBeforeUnmount` (already done)

---

## 9. New Components to Create

| Component | Purpose |
|-----------|---------|
| `ScoreGauge.vue` | Radial SVG score visualization (replaces score badges) |
| `SkeletonRow.vue` | Animated skeleton placeholder for table rows |
| `SkeletonCard.vue` | Animated skeleton placeholder for dashboard cards |
| `ToastNotification.vue` | Individual toast message component |
| `ToastContainer.vue` | Stack manager for toast notifications |
| `FilterChip.vue` | Pill-style toggle chip for quick filters |
| `BaseModal.vue` | Reusable modal wrapper with transitions and focus trap |
| `BaseButton.vue` | Consistent button component with variants |
| `StatCard.vue` | Mini stat display (number + label) |
| `Breadcrumb.vue` | Navigation breadcrumb component |
| `ThemeToggle.vue` | Dark/light mode toggle with icon animation |
| `IconButton.vue` | Small icon-only button with tooltip |
| `EmptyState.vue` | Reusable empty state with icon/title/description/action |

---

## 10. File Changes Summary

| File | Type of Change |
|------|---------------|
| `resources/css/app.css` | Major: Add theme variables, dark mode, font imports, custom utilities |
| `resources/views/app.blade.php` | Minor: Add font preloads, dark mode class setup |
| `resources/js/Layouts/AppLayout.vue` | Major: Complete redesign with collapsible sidebar, toast system |
| `resources/js/Pages/Dashboard.vue` | Major: Stats bar, redesigned cards, new empty state, modal improvements |
| `resources/js/Pages/Subreddit/Show.vue` | Major: Breadcrumb, header card, improved layout |
| `resources/js/Pages/Starred.vue` | Moderate: Header improvements, consistent styling |
| `resources/js/Pages/Welcome.vue` | Major: Complete redesign or removal |
| `resources/js/Components/IdeaFilters.vue` | Major: Filter chips, collapsible toolbar |
| `resources/js/Components/IdeasTable.vue` | Major: Skeleton loading, sticky header, improved pagination |
| `resources/js/Components/IdeaRow.vue` | Major: Score gauges, improved expand/collapse, better detail layout |
| `resources/js/Components/ScanProgress.vue` | Major: Pipeline visualization, animated counters |
| `resources/js/Components/ScoreGauge.vue` | **New** |
| `resources/js/Components/SkeletonRow.vue` | **New** |
| `resources/js/Components/SkeletonCard.vue` | **New** |
| `resources/js/Components/ToastNotification.vue` | **New** |
| `resources/js/Components/ToastContainer.vue` | **New** |
| `resources/js/Components/FilterChip.vue` | **New** |
| `resources/js/Components/BaseModal.vue` | **New** |
| `resources/js/Components/BaseButton.vue` | **New** |
| `resources/js/Components/StatCard.vue` | **New** |
| `resources/js/Components/Breadcrumb.vue` | **New** |
| `resources/js/Components/ThemeToggle.vue` | **New** |
| `resources/js/Components/EmptyState.vue` | **New** |

---

## 11. Design Tokens Reference (Quick Access)

### Button Sizes
| Size | Height | Padding | Font Size | Icon Size |
|------|--------|---------|-----------|-----------|
| sm   | 32px   | 12px 16px | text-sm | 16px |
| md   | 40px   | 8px 20px | text-sm | 20px |
| lg   | 48px   | 12px 24px | text-base | 20px |

### Card Variants
| Variant | Background | Border | Shadow | Hover |
|---------|-----------|--------|--------|-------|
| Default | surface-secondary | border-default | shadow-xs | shadow-md + lift |
| Active  | brand-50 | brand-200 | shadow-sm | shadow-md |
| Error   | red-50 | red-200 | shadow-xs | — |

### Badge/Chip Variants
| Variant | Background | Text | Border |
|---------|-----------|------|--------|
| Brand   | brand-100 | brand-700 | — |
| Gray    | gray-100 | gray-700 | — |
| Success | green-100 | green-700 | — |
| Warning | amber-100 | amber-700 | — |
| Danger  | red-100 | red-700 | — |
| Outline | transparent | text-secondary | border-default |
