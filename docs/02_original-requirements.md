**DO NOT MODIFY THIS FILE - IT IS ONLY FOR REFERENCE PURPOSES**

These are initial human generated requirements for the AI Module. They are not final and are subject to change based on further discussion and development.

The module should:
- Generate existing metadata fields (e.g. meta title, meta description, Open Graph title/description) based on the page content.
- Generate additional AI-oriented metadata field
- This does not need to be the most robust module in the world.

**Additional AI-oriented metadata fields**

Fields show be ones that modern AI systems consume, such as:

- Structured summary (short + long form)
- Key entities (people, organisations, locations)
- Key topics/themes
- Content intent (informational / transactional / navigational)
- Suggested FAQs (for structured data)
- Semantic tags for vector/embedding use
- Canonical description optimised for LLM ingestion
- Optional JSON-LD schema markup draft

**Some initial acceptance criteria:**

- Metadata generation is optional (manual override always possible).
- Users can plug in their own AI API key (e.g. OpenAI, Anthropic, etc).
- Clear indication of AI-generated vs manually edited fields.
- Regeneration can be triggered per page.
- Generated content does not auto-publish without user review.
- Sensible token/usage limits configurable at environment level.
- Works against page content and maybe selected blocks if not too hard.
- Graceful failure if no API key is configured.
- Logging of prompts + outputs for debugging (dev mode only) if Steve thinks this is of value.
- No impact on page performance at runtime (async generation occurs on save or on demand).
