# Human Context

Loose assortment of relevant background context that informs spec and design decisions. Not a spec itself, but should be consulted before updating specs.

## Support layers and configuration

- There is a support layer called "Pumas" who can change environment variables and trigger deployments. Customers submit support tickets and can escalate via account managers.
- Pumas **cannot** change config that lives in code repositories. Code changes require booking developer time which can take weeks or months to turn around.
- This is why environment variables are preferred over YAML config for runtime settings - it gives Pumas (and therefore customers) a faster path to changes like switching AI provider or changing the model used.
- For the async job specifically, `symbiote/silverstripe-queuedjobs` provides a CMS interface where admin users can enable/disable jobs directly. This is even faster than a support ticket and costs $0. So an environment variable to enable/disable the job may be redundant since admins can just toggle it in the CMS.

## Customer considerations

- Some customers may not be aware of or care about the AI metadata feature. Some may actively dislike AI. The module should not degrade their CMS experience (e.g. performance on save).
- The CMS modal, background job, and CMS report are intended to support content authors working on draft content, not site owners auditing the public site. This is why CMS-side extraction and report stale checks are Draft-first rather than Live-first.
- SiteTree's "Metadata" toggle section has `MetaDescription` and `ExtraMeta` (Custom Meta Tags). We can't hide this section because Custom Meta Tags must remain available. Instead, the module replaces `MetaDescription` with a read-only field showing the `GeneratedMetadata` value, with a note explaining it's managed by the AI module and showing the old value if one existed.
- The `<title>` tag is typically template-driven (e.g. `$Title | $SiteConfig.Title`), not from a CMS field. Do not store a separate MetaTitle field in GeneratedMetadata; use `OGTitle` for social sharing and derive JSON-LD titles from `OGTitle` or the page title.
- The module overrides `getMetaDescription()` on SiteTree so that `MetaTags()` picks up the AI-generated value automatically — no template changes needed.

## Versioning of GeneratedMetadata

- GeneratedMetadata is **Versioned** (Draft/Live). This matches the editor mental model that "publish page = publish everything on the page".
- The key insight came from how Elemental content blocks work: they also use sideways XHR saves but hook into the page publish action via JS to trigger their own publish. GeneratedMetadata follows the same pattern.
- **ReviewedAt as publish gate:** The critical design decision is that the JS publish hook only publishes metadata if `ReviewedAt` is set. This prevents the dangerous scenario where a background job generates metadata, an editor publishes the page for an unrelated reason, and unverified AI content goes live. Unreviewed metadata stays safely in Draft.
- The `$owns` relationship is not strictly needed (following Elemental's pattern) — the JS hook handles the cascade. Individual projects can add `$owns` on their own classes if desired.
- Frontend rendering reads from the Live stage, so only reviewed+published metadata appears on the site.

## Content hashing concerns

- Hashing rendered content at save time to detect staleness is a useful idea but adds overhead to every page save. This is "feature bloat" territory - most editors may not even use the AI metadata feature.
- Content change detection is arguably more broadly useful and might belong in `silverstripe/admin` or `silverstripe/framework` rather than this module. For now we'll implement it here but it's worth noting this concern.

## Prompt management

- Prompts are effectively a form of code. Non-technical people are liable to make things worse by altering them.
- Hesitant to put prompts in SiteSettings / make them admin-editable, though it's possible.
- Likely approach: hardcoded in the module, with extension hooks allowing override/extension at project level via code.

## CMS UI approach

- The AI metadata interface should be distinct from regular page content but doesn't have to be a tab. Could be a modal, a toggleable section, etc. Current MetaDescription is behind a toggleable section below the content field.
- Existing Silverstripe patterns: Elemental content blocks use toasts for async feedback and "sideways" XHR saves. This is likely the pattern we'll follow.
