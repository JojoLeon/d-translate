# Changelog

All notable changes to this extension are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

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
