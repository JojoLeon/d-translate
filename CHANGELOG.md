# Changelog

All notable changes to this extension are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versions follow [Semantic Versioning](https://semver.org/).

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
