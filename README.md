# dTRANSLATE — LLM translation for TYPO3 (dreistein/d-translate)

Translates pages and records into your site languages with a large language
model — through TYPO3's own localize path, so translations behave like ones made
by hand.

📖 **[Full manual, examples and screenshots → www.dreistein.de/dtranslate](https://www.dreistein.de/dtranslate)**

## Requirements

TYPO3 12.4, 13.4 or 14.0 · PHP 8.2+ · an API key for Anthropic, OpenAI, Mistral or Google Gemini.

## Installation

```bash
composer require dreistein/d-translate
```

Activate the extension and run the database comparison.

## Setup (once)

Both are plain records on the root level (*Web → Records*, page id 0):

1. **LLM configuration** — provider, model and API key. The API base URL fills
   in by itself when you pick the provider. For OpenAI-compatible gateways such
   as Azure AI Foundry, enter the base URL (`https://<resource>.services.ai.azure.com/openai/v1`)
   and the deployment name as model; a pasted full URL ending in
   `/chat/completions` is shortened to the base URL on save.
2. **Translation profile** — the LLM configuration to use, the register
   (formal/informal), an optional style hint and glossary. Assign it to a site,
   or leave the site empty to make it the default for every site.

For background runs, call the worker from cron (every minute is fine):

```bash
vendor/bin/typo3 dtranslate:work --time-limit=50
```

If you already run the TYPO3 scheduler, add the task **dTRANSLATE: Process
translation queue** instead and let it run every minute — one or the other, not
both.

## Translating

Open **Web → Translation** and pick a page in the page tree:

1. Choose the **translation profile** (pre-selected from the site).
2. Tick the **target languages** — only the languages of that page's site are offered.
3. Tick the **records** to translate. An unticked container skips its whole group;
   inline children (IRRE, ContentBlocks, file references) always travel with their parent.
4. Options: *Publish translated page immediately* and *Process in the background*.
5. **Translate selection.**

A run is split into one unit per record and language. Without background
processing you watch it live; with it, the worker takes over and you can close
the page. **Recent runs** shows state, progress and what each run consumed —
plus a cancel link while it is still open.

Running a translation again refreshes it; nothing is ever deleted by a run.

## Glossary

Term pairs in the profile ("keep *Streamer* as *Streamer*") are handed to the
model with every request — either for all target languages or for one.

## Languages

Backend in English (source) plus 38 translations, following the backend user's
language.

## Support and manual

The detailed manual — profiles, glossaries, large runs, multi-site
setups and troubleshooting — lives at
**[www.dreistein.de/dtranslate](https://www.dreistein.de/dtranslate)**.

## License

GPL-2.0-or-later — © Joachim Dreistein Medienentwicklung, Düsseldorf ·
[www.dreistein.de](https://www.dreistein.de)
