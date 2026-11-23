# Reading the identity document from an attachment

Owner request and decisions, 23 November 2026: fill the new identity columns of the person (schema v1.6) from the scan of the identity document that staff already attach, with great accuracy and the possibility to edit from the image. Decisions: proposals shown beside the image and approved by staff, started from the attachment and from the person page. Engine: the owner wants a **free** way and will **try both free engines (Tesseract and Ollama)**; the paid cloud model stays available as a third engine.

## How it works

1. A staff member with `people.manage` in the person's city opens **قراءة الهوية** (person page, or the button on an image/PDF row of the attachments panel). The page lists the person's own scans and the scans of the projects they apply for (only in categories the account may see) and shows the chosen one.
2. Pressing **اقرأ الهوية** reads that one file with the configured engine (below). Every engine answers in the same fixed shape: the printed names, birth date, registry place (القيد), national number, issuing line (المنح), sex and, for a passport or residence permit, its number, each with a confidence.
3. The program checks everything locally (`IdCardReader::normalize`), whatever the engine: Arabic-Indic digits become ASCII; a Syrian national number must be 11 digits; the birth date must be a real date between 1900 and today (day-first dates are understood); names lose stray symbols and digits; a document that is not an identity document is never trusted.
4. The document is read **twice, independently** (`ID_READER_PASSES`, 1–3; Tesseract varies how it segments the page, the models get a different prompt). A field that differs between the readings is marked «تحتاج مراجعة» with the other candidates kept as clickable alternatives. A field is ticked by default only when the readings agree, the engine is sure and the checks pass; doubtful fields are unticked.
5. Staff compare each value with the image, edit any of them, tick what to apply and press **حفظ المحدد على الشخص**. The values are written through `PeopleService::update`: the person form's validation, the duplicate check of the national number (with «save anyway») and the audit details of v1.3 (the national number only as «changed»). Nothing is written before that.

## The three engines (`ID_READER_DRIVER`)

| Engine | Cost | Where the scan goes | Notes |
|---|---|---|---|
| `tesseract` | free | stays on the server | OCR program. The least accurate on Arabic print and tilted or dim photos; clear digits (the national number, dates) are usually read well, names and places need the most review. Names are never marked «مؤكدة». |
| `ollama` | free | stays on the server | A vision model run by Ollama (default `qwen2.5vl:7b`). Understands the picture better than OCR, but needs a strong machine (16 GB RAM or more; a graphics card helps a lot) and is slow on an ordinary PC. Arabic quality varies between models: try it on real cards first. |
| `claude` (default) | paid per reading | sent to the cloud service, only when staff press "read" | Needs `ANTHROPIC_API_KEY`. Limited to `ID_READER_DAILY_LIMIT` readings per account per day. |

PDF scans: the models and Tesseract read images, so a PDF is turned into page images (the first two pages) with Poppler's `pdftoppm`; without it a PDF is refused with a clear message and an image works. The cloud engine reads PDFs itself.

### Installing the free engines (Windows)

- **Tesseract:** install it from the UB Mannheim build (github.com/UB-Mannheim/tesseract/wiki) and tick **Arabic** under "Additional language data". Check with `tesseract --list-langs` that `ara` is listed. If `tesseract` is not on the PATH, set `TESSERACT_BIN` to the full path (usually `C:\Program Files\Tesseract-OCR\tesseract.exe`).
- **Poppler** (only for PDF scans): install Poppler for Windows and set `PDFTOPPM_BIN` to the full path of `pdftoppm.exe` if it is not on the PATH.
- **Ollama:** install it from ollama.com, then run `ollama pull qwen2.5vl:7b` (several gigabytes). The defaults `OLLAMA_URL=http://127.0.0.1:11434` and `OLLAMA_MODEL=qwen2.5vl:7b` can stay. Another vision model can be tried through `OLLAMA_MODEL`.
- Restart the site's server after editing `.env` (it does not reload it).

### Choosing between them: test on real cards first

On the server console, with a scan you may handle (nothing is saved, logged or audited; the values are printed to the terminal):

```
php artisan identity:test "C:\path\to\scan.jpg" --driver=tesseract
php artisan identity:test "C:\path\to\scan.jpg" --driver=ollama
```

Run both on the same ten real cards, write down which values are wrong for each, and choose by the number of wrong values and the time it takes. Staff review every value before saving whatever the engine.

## Settings (`.env`)

| Key | Meaning |
|---|---|
| `ID_READER_DRIVER` | `claude` (default), `ollama` or `tesseract`. **The feature is off until the chosen engine is set up** (the page says what is missing). |
| `ANTHROPIC_API_KEY`, `ID_READER_MODEL`, `ID_READER_DAILY_LIMIT` | The cloud engine: key, model (default `claude-sonnet-5-5`), readings per account per day (default 60). |
| `OLLAMA_URL`, `OLLAMA_MODEL`, `OLLAMA_TIMEOUT` | The Ollama server and model. |
| `TESSERACT_BIN`, `TESSERACT_LANG` (default `ara+eng`), `PDFTOPPM_BIN` | The programs for Tesseract and for PDF pages. |
| `ID_READER_PASSES` | Independent readings per press, default 2. |

## Privacy (the cloud engine is an exception to the "no personal data leaves the site" rule of the smart-search plan, approved by the owner)

- With `tesseract` or `ollama` the scan never leaves the server. With `claude` it leaves **only when staff press "read"**, to the vision service, to be read.
- The program does not store the scan anywhere but the private attachment disk, and the readings are not stored anywhere (the temporary page files of the local engines are deleted at once).
- Every press is logged in `ai_queries` (dataset `identity`: who, when, model, tokens, duration; the prompt is the fixed text `[identity document scan]`) and audited as `identity.read` (person, attachment id; success, failure or limit). Neither holds the document's content or any value read.
- The daily limit applies to the paid engine only.

## What is not claimed

- The accuracy of **no engine** has been measured on real Syrian ID cards. The design is what makes the result checkable (fixed schema, local validation, two readings, every value reviewed beside the image), not a guarantee about an engine. The tests use stand-ins for the engines: the Tesseract text parser was written from the card's printed labels, **not from real scans**, and may need adjusting to the real card layouts (send the owner's test results and the labels as printed). After the first week of use, count how often staff edit a value; that is the real accuracy figure.
- Sex is read when the card prints it; the older cards do not.
- A scan over 5 MB (image) or 20 MB (PDF) is refused: compress it first.
- Local image clean-up (straightening, contrast) is not done: the PHP image extension (gd) is off on this machine, and Tesseract does its own binarisation. If real scans read poorly, enabling `gd` and adding a clean-up step is the next improvement.

## Files

`app/Services/Identity/`: `IdCardReader.php` (checks, comparison, engine choice), `Engines/` (`ClaudeEngine`, `OllamaEngine`, `TesseractEngine`, `EngineFactory`), `OcrTextParser.php` (Tesseract text to fields), `PageImages.php` (PDF to images), `IdentityPrompt.php` (rules and answer schema), `IdentityReviewService.php` (permissions, daily limit, log, apply). `app/Livewire/IdentityReview.php` + `resources/views/livewire/identity-review.blade.php`, route `people.identity`, `config/identity_reader.php`, `lang/*/identity.php`, command `identity:test`. Tests: `tests/Unit/IdCardReaderTest.php`, `tests/Unit/OcrTextParserTest.php`, `tests/Feature/IdentityReadingScenarioTest.php` (cloud, faked), `tests/Feature/IdentityEnginesTest.php` (Ollama and Tesseract, faked); no test calls a real engine.
