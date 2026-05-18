# System Overview

One-page summary of the AI SEO module architecture. Read this first, then dive into individual specs.

## What it does

Automatically generates SEO and AI-oriented metadata for Silverstripe CMS pages using an AI provider (Gemini by default). Metadata is stored on a separate DataObject, reviewed by editors via a CMS modal, and rendered as meta tags + JSON-LD structured data.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ CMS (Editor)                                                │
│                                                             │
│  Page Edit Form ──→ [Metadata] button ──→ Modal (React)    │
│                                              │         │    │
│                                  Generate/Regenerate   Apply │
│                                              │         │    │
└──────────────────────────────────────────────┼─────────┼────┘
                                               │         │
                         FormSchema XHR (specs/08)         │
                                               ▼         ▼
┌──────────────────────────────────────────────────────────────┐
│ AiSeoController (specs/08)                              │
│                                                              │
│  GET  /admin/ai-seo/schema/AiSeoForm/{ID}?fqcn=... │
│  POST /admin/ai-seo/aiSeoForm/{ID} → doRegenerate  │
│                                           → doSave           │
└──────────────────┬───────────────────────────┬───────────────┘
                   │                           │
                   ▼                           ▼
┌──────────────────────────┐   ┌───────────────────────────────┐
│ Content Extraction       │   │ GeneratedSeo DataObject          │
│ (specs/03)               │   │ (specs/01, specs/02)           │
│                          │   │                                │
│ Elemental blocks ──┐     │   │ MetaDescription, OGTitle,      │
│ Content field ─────┤─→ text  │ OGDescription, SummaryLong,    │
│ Extension hook ────┘     │   │ KeyEntities, KeyTopics,        │
│                          │   │ SuggestedFAQs, ReviewedAt,     │
│          + MD5 hash ─────────│→ContentHash                    │
│          (specs/07)      │   │ GeneratedAt, GenerationNote    │
└──────────┬───────────────┘   └───────────────┬────────────────┘
           │                                   │
           ▼                                   │
┌──────────────────────────┐                   │
│ AI Provider (specs/04)   │                   ▼
│                          │   ┌───────────────────────────────┐
│ Prompt (specs/05)        │   │ Rendering (specs/10)          │
│ → Gemini / OpenAI /      │   │                               │
│   Anthropic              │   │ MetaTags() → <title>, <meta>  │
│                          │   │ JSON-LD → <script> block       │
│ → AiSeoResult       │   │ llms.txt → /llms.txt route     │
└──────────────────────────┘   └───────────────────────────────┘

┌──────────────────────────────────────────────────────────────┐
│ Background (async)                                           │
│                                                              │
│ GenerateAiSeoJob (specs/11)                             │
│  → Finds pages: no metadata OR stale (hash mismatch)         │
│  → Calls AI provider per page (rate-limited)                 │
│  → llms.txt is built dynamically today; static generation    │
│    may be introduced in a later phase                        │
│                                                              │
│ AiSeoReport (specs/13)                                  │
│  → Status: Missing / Stale / Unreviewed / OK                 │
│  → Filterable, paginated                                     │
└──────────────────────────────────────────────────────────────┘
```

## Spec index

| # | Spec | What it covers |
|---|------|---------------|
| 00 | This file | System overview and architecture |
| 01 | `data-architecture` | GeneratedSeo DataObject, polymorphic relationship, Versioned, migration |
| 02 | `metadata-fields` | All field definitions, types, validation, JSON examples |
| 03 | `content-extraction` | Elemental + Content field extraction, extension hook |
| 04 | `ai-providers` | Provider interface, env vars, error handling, request timeout |
| 05 | `prompts` | System/user prompt templates, output parsing, extension hook |
| 06 | `generation-behaviour` | Pipeline, threshold, concurrency, error handling |
| 07 | `stale-metadata` | MD5 content hashing, on-demand staleness checking |
| 08 | `api-endpoints` | FormSchema endpoints, FQCN validation |
| 09 | `cms-ux` | React modal, field layout, regenerate/submit flow |
| 10 | `metadata-rendering` | Meta tags, JSON-LD assembly, llms.txt |
| 11 | `background-job` | QueuedJob, targeting, rate limiting, logging |
| 12 | `dirty-versioned-state` | Versioned lifecycle, publish-on-page-publish, ReviewedAt gate |
| 13 | `cms-report` | Status report, columns, filtering |

## Key design decisions

- **Versioned GeneratedSeo** — Draft/Live with publish-on-page-publish via JS hook; `ReviewedAt` gates publish readiness
- **Polymorphic relationship** — supports future DataObject types
- **Sideways XHR** — metadata saves independent from page form
- **Single AI call** — one prompt generates all fields as JSON
- **MD5 hashing** — on-demand stale detection, no save-time overhead
- **React + Entwine adapter** — modern UI within legacy CMS shell
- **ReviewedAt datetime** — tracks human review, reset on regeneration
