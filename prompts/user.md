Read the page content below and return a single JSON object with these fields:

metaDescription (string)
  Short page summary for search result snippets. Max 150 characters.

ogTitle (string)
  Title for social media sharing. Can differ from the page title. Max 70 characters.

ogDescription (string)
  Description for social media sharing. Should not be identical to metaDescription. Max 200 characters.

summaryLong (string)
  Factual summary of the page content in 100-200 words. Plain language only. No marketing language.

keyEntities (array of objects, 0 to 5 items)
  People, organisations, and places mentioned in the content. Each object has these keys:
    "type" - must be "Person", "Organization", or "Place". No other values.
    "name" - the entity name.
    "sameAs" - a Wikipedia or Wikidata URL. Leave out this key if unsure. Do not guess URLs.

keyTopics (string)
  Comma-separated topic labels describing what the page is about. 3 to 7 topics. Lowercase except for proper nouns. Example: "spaceports, traffic windows, debris"

suggestedFAQs (array of objects, 2 to 5 items)
  Question and answer pairs based on the page content. Each object has these keys:
    "question" - a question a reader might ask.
    "answer" - a short answer (1 to 3 sentences) using only facts from the content.

If the page content is too short or empty: set metaDescription to "Insufficient content", use empty strings for other text fields, and empty arrays for array fields.
If there are no clear entities, return an empty keyEntities array.
If the content does not suit FAQs, return an empty suggestedFAQs array.

EXAMPLE OUTPUT:
{"metaDescription":"Guide to residential building consents in Wellington, including timelines, fees, and required documents.","ogTitle":"Residential Building Consent Guide","ogDescription":"Everything Wellington homeowners need to know about building consent requirements and application steps.","summaryLong":"Wellington City Council processes residential building consents within 20 working days. Applicants must submit building plans, an engineering report, and a completed application form. Fees start from $2,500 for standard residential projects. The council offers a pre-application meeting service to help identify potential issues before formal submission.","keyEntities":[{"type":"Organization","name":"Wellington City Council","sameAs":"https://en.wikipedia.org/wiki/Wellington_City_Council"},{"type":"Place","name":"Wellington","sameAs":"https://en.wikipedia.org/wiki/Wellington"}],"keyTopics":"building consent, residential construction, Wellington regulations, council fees","suggestedFAQs":[{"question":"How long does a building consent take?","answer":"Standard residential building consents are processed within 20 working days."},{"question":"What documents do I need?","answer":"You need building plans, an engineering report, and a completed application form."}]}

NOW GENERATE METADATA FOR THIS PAGE:

Page title: {pageTitle}
Page URL: {pageUrl}

--- PAGE CONTENT START ---
{content}
--- PAGE CONTENT END ---

Return only the JSON object. No other text.
