# Changelog

All notable changes to this extension are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versions follow [Semantic Versioning](https://semver.org/).

## [0.1.3] — 2026-10-15

### Changed

- A translation profile can apply to **several sites**: the site field is a
  checkbox list now, and a page is matched against every site the profile
  lists. A profile that named one site before keeps working as it is; the
  column only grows (run the database schema update once). A profile with no
  site ticked remains the default for every site without one of its own

## [0.1.2] — 2026-10-15

### Added

- `dtranslate:remap-anchors`: the one-off repair of translations that exist
  already. Points the element anchors of page links in every translation of
  a site (or of the pages given with `--page`) at the translated elements,
  and with `--slugs` rebuilds the slug of page translations still carrying
  the source page's slug — written through the DataHandler, so EXT:redirects
  creates its redirects and moves subpages along. `--dry-run` lists every
  record, anchor and slug that would change, plus the anchors that have to
  stay because their element is not translated in that language. No LLM
  call is involved
- `dtranslate:glossary-consolidate`: collapses a glossary kept once per
  regional variant or site language id into one entry per language, and with
  `--merge-profiles` turns entries identical in every profile into shared
  ones. Variants that disagree on a translation are left alone and listed;
  entries that give way land in the recycler. `--dry-run` reports only

### Fixed

- A page translation (`dtranslate:page`, or any run that localizes a
  container and its children in one selection) no longer reports
  "Localization failed: There already are localizations (…)" once per b13
  container child. b13/container localizes the children together with their
  container, so the pass that followed for the children asked DataHandler for
  translations that existed already; the run now checks what exists before
  each container level and localizes only what is still missing. The children
  are counted as created records of the run, which they always were — the
  "created" figure used to leave them out
- A page link to a content element (`t3://page?uid=…#…`) in a translation now
  jumps to the translated element: once the element exists in the target
  language, the anchor is pointed at its translation. It used to keep naming
  the source-language element, which a site rendering its translations in
  free mode (`fallbackType: free`) does not show on the translated page at
  all — the link ended at the top of the page. Every translation on the page
  is covered, as are records elsewhere that link to an anchor on it, each
  time the page is translated
- A page translation that an editor or an import created before dTRANSLATE
  ran no longer keeps the source slug: a title and slug still identical to
  the source are treated as the untouched copy they are — the title is
  translated and the slug rebuilt from it. A slug that alone is still the
  source's copy (the title was translated by hand) is rebuilt as well. The
  slug is now built after all page fields have been translated
- An API base URL pasted with its operation path (`…/v1/chat/completions`, as
  the Azure AI Foundry and OpenAI consoles show it) no longer leads to a 404:
  the clients append the path themselves and used to call
  `…/chat/completions/chat/completions`. The path is cut off at request time
  and when the LLM configuration is saved; a query string such as
  `?api-version=…` stays at the end of the URL

### Changed

- The "does not know that model or endpoint" error (HTTP 404) now also names
  the URL that was actually requested, in all 39 interface languages
- The glossary has moved out of the profile form into its own view, *Web →
  Translation → Glossary*: a paged list per profile (25–200 entries a page)
  with search and a language filter, each entry opening in the standard
  record form. The profile form used to render every entry inline and took
  ages to open once a glossary had grown to thousands of terms; it now shows
  the entry count and a button into the list. A glossary entry carries its
  profile as a plain select field
- A glossary entry now applies to a **language** rather than to one site
  language id: "Applies to" offers every target language, the languages
  (`en` covers every English variant on every site), the regional variants
  (`en-GB`) and — as before — single site language ids, each listed once
  however many sites share it. The most specific entry wins when several name
  the same term. Existing entries keep their ids and their meaning; the column
  only changes from a number to a key (run the database schema update once)
- An entry can belong to **every profile** ("Every profile (shared)") instead
  of one, so a glossary used by several profiles is maintained once. The
  module's glossary list shows the shared entries as a list of their own

## [0.1.1] — 2026-08-04

### Changed

- Homepage and support links now point at the manual
  (https://www.dreistein.de/en/dtranslate/manual) and the public repository

## [0.1.0] — 2026-08-04

First public release (beta).

### Added

- LLM-based translation of pages and records into the site languages through
  TYPO3's own localize path — translations behave like ones made by hand
- Backend module *Web → Translation*: pick a page, choose profile, target
  languages and records, translate the selection; recent runs with state,
  progress, consumption and a cancel link
- Translation profiles per site: LLM configuration, register
  (formal/informal), style hint and glossary
- LLM configurations for Anthropic Claude, OpenAI, Mistral and Google Gemini
- Nested content support: b13/container, ContentBlocks collections, inline
  relations (IRRE), FlexForm values and file reference metadata travel with
  their parent
- Page properties and SEO fields are translated with the page; re-running a
  translation refreshes it without deleting anything
- Background processing via queue worker (`dtranslate:work` CLI command or
  scheduler task), plus `dtranslate:page` and `dtranslate:translate-record`
  for scripted runs
- Coherent terminology per page: the page context travels with every request
  (cache-friendly for providers with prompt caching)
- Backend in English plus 38 interface translations, following the backend
  user's language
