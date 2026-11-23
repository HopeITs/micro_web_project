# Reading the identity document from an attachment

Owner request and decisions, 23 November 2026: fill the new identity columns of the person (schema v1.6) from the scan of the identity document that staff already attach, with great accuracy and the possibility to edit from the image. Decisions: proposals shown beside the image and approved by staff, started from the attachment and from the person page. Engine: the owner wants a **free** way and will **try both free engines (Tesseract and Ollama)**; the paid cloud model stays available as a third engine.

## How it works

1. A staff member with `people.manage` in the person's city opens **قراءة الهوية** (person page, or the button on an image/PDF row of the attachments panel). The page lists the person's own scans and the scans of the projects they apply for (only in categories the account may see) and shows the chosen one.
2. Pressing **اقرأ الهوية** reads that one file with the configured engine (below). Every engine answers in the same fixed shape: the printed names, birth date, registry place (القيد), national number, issuing line (المنح), sex and, for a passport or residence permit, its number, each with a confidence.
3. The program checks everything locally (`IdCardReader::normalize`), whatever the engine: Arabic-Indic digits become ASCII; a Syrian national number must be 11 digits; the birth date must be a real date between 1900 and today (day-first dates are understood); names lose stray symbols and digits; a document that is not an identity document is never trusted.
4. The document is read **twice, independently** (`ID_READER_PASSES`, 1–3; Tesseract varies how it segments the page, the models get a different prompt). A field that differs between the readings is marked «تحتاج مراجعة» with the other candidates kept as clickable alternatives. A field is ticked by default only when the readings agree, the engine is sure and the checks pass; doubtful fields are unticked.
5. Staff compare each value with the image, edit any of them, tick what to apply and press **حفظ المحدد على الشخص**. The values are written through `PeopleService::update`: the person form's validation, the duplicate check of the national number (with «save anyway») and the audit details of v1.3 (the national number only as «changed»). Nothing is written before that.

## The engines (`ID_READER_DRIVER`)

| Engine | Cost | Where the scan goes | Notes |
|---|---|---|---|
| `tesseract` | free | stays on the server | OCR program. The least accurate on Arabic print and tilted or dim photos; clear digits (the national number, dates) are usually read well, names and places need the most review. Names are never marked «مؤكدة». |
| `ollama` | free | stays on the server | A vision model run by Ollama (default `qwen2.5vl:7b`). Understands the picture better than OCR, but needs a strong machine (16 GB RAM or more; a graphics card helps a lot) and is slow on an ordinary PC. Arabic quality varies between models: try it on real cards first. |
| `ensemble` | free | stays on the server | **Tesseract and the model together** (three readings: Tesseract, the model, Tesseract again). A value they disagree on is flagged for review. This catches a model's confident mistake that a second reading by the same model would repeat (see the trial below). Slower than Tesseract alone (the model's reading dominates) and needs both installed. |
| `claude` | paid per reading | sent to the cloud service, only when staff press "read" | Needs `ANTHROPIC_API_KEY`. Limited to `ID_READER_DAILY_LIMIT` readings per account per day. |

PDF scans: the models and Tesseract read images, so a PDF is turned into page images (the first two pages) with Poppler's `pdftoppm`; without it a PDF is refused with a clear message and an image works. The cloud engine reads PDFs itself.

### Installing the free engines (Windows)

- **Tesseract:** install it from the UB Mannheim build (github.com/UB-Mannheim/tesseract/wiki) and tick **Arabic** under "Additional language data". Check with `tesseract --list-langs` that `ara` is listed. If `tesseract` is not on the PATH, set `TESSERACT_BIN` to the full path (usually `C:\Program Files\Tesseract-OCR\tesseract.exe`). **Without administrator rights** (or to use the more accurate "best" Arabic data): put `ara.traineddata` (from github.com/tesseract-ocr/tessdata_best, about 13 MB) and a copy of `eng.traineddata` in a folder of your own and set `TESSERACT_DATA` to that folder.
- **Poppler** (only for PDF scans): install Poppler for Windows and set `PDFTOPPM_BIN` to the full path of `pdftoppm.exe` if it is not on the PATH.
- **Ollama:** install it from ollama.com, then run `ollama pull qwen2.5vl:7b` (several gigabytes). The defaults `OLLAMA_URL=http://127.0.0.1:11434` and `OLLAMA_MODEL=qwen2.5vl:7b` can stay. Another vision model can be tried through `OLLAMA_MODEL`.
- Restart the site's server after editing `.env` (it does not reload it).

### First trial (23 November 2026, one synthetic card, this PC)

An invented card drawn by the developer (clear Arabic print, invented data, **not a real card**) read with the free engines. It checks the plumbing and the engines' reading of printed Arabic, not their accuracy on your cards.

| Engine | Time | Result |
|---|---|---|
| `tesseract` | 1.6 s (two readings) | 8 of 10 fields right, names and places «medium» as designed, date and national number «high»; the issuing line (المنح, a stylised word) came out empty instead of wrong. |
| `ollama` (`qwen2.5vl:7b`, CPU) | 148 s (two readings) | Read the issuing line, **but made two mistakes marked «high» and repeated by its second reading**: a surname with Latin letters mixed in (`زيidan`) and the registry digits reversed (`حلب 5/1234` for `حلب 1234/5`). |
| `ensemble` | 41 s | The reversed registry was flagged «needs review» with the other reading kept, the national number and the date stayed «high», the issuing line was flagged because the engines disagreed. |

What it taught: (1) a model's own confidence cannot be trusted alone, so two readings by the same model are not enough, and the local script check (Latin letters among Arabic ones are doubtful) and the comparison between different engines are what catch such errors; (2) Tesseract is fast and good at digits and printed Arabic but misses stylised words, the model is slow on a PC without a graphics card but reads those words; (3) staff review stays the safety net. One card proves nothing about accuracy: use `identity:test` on ten real cards before choosing.
### Choosing between them: test on real cards first

On the server console, with a scan you may handle (nothing is saved, logged or audited; the values are printed to the terminal):

```
php artisan identity:test "C:\path\to\scan.jpg" --driver=tesseract
php artisan identity:test "C:\path\to\scan.jpg" --driver=ollama
php artisan identity:test "C:\path\to\scan.jpg" --driver=ensemble
```

Run them on the same ten real cards, write down which values are wrong for each, and choose by the number of wrong values and the time it takes. Staff review every value before saving whatever the engine.

## On the online server

Whether it is possible depends on what kind of server the site will be hosted on. The hosting choice is still open (the question is asked before deployment, M7.1); this is what each engine needs, so the answer can be checked against any offer.

| | Tesseract | Ollama (vision model) | Claude (cloud) |
|---|---|---|---|
| Possible on a normal VPS or a dedicated server with SSH and `sudo` | **Yes**: light (a few seconds of one CPU core and well under 1 GB of memory per reading) | Only on a **large** machine: the 7B model is about 6 GB on disk and needs roughly 8 GB of free memory or more; on a CPU without a graphics card one reading takes tens of seconds or minutes. A small VPS (2–4 GB) cannot run it. A GPU server is expensive. Check these figures on the real machine with `ollama run` before relying on them. | Yes, anywhere with internet |
| Shared hosting (cPanel and similar) | Normally **no**: no permission to install programs, and `proc_open` (which the site uses to run it) is usually disabled | No | Yes (needs only outbound HTTPS) |
| The scan leaves the server | No | No | Yes (to the cloud service) |
| Cost | Free | Free software, but a big server costs money | Per reading |

**Tesseract on a Linux VPS (Ubuntu or Debian), as a user with `sudo`:**

```
sudo apt update
sudo apt install tesseract-ocr tesseract-ocr-ara poppler-utils
tesseract --list-langs          # ara must be listed
```

Then in the site's `.env`: `ID_READER_DRIVER=tesseract` (the defaults `TESSERACT_BIN=tesseract` and `PDFTOPPM_BIN=pdftoppm` fit). `poppler-utils` is for PDF scans. The Linux package usually carries the lighter Arabic data; for the more accurate data download `ara.traineddata` from tessdata_best as on the development machine and set `TESSERACT_DATA` to its folder. PHP must be allowed to run programs (`proc_open` not in `disable_functions`), and the web user must be able to run `tesseract`. Restart the PHP service after editing `.env`. Test on the server with `php artisan identity:test <scan> --driver=tesseract`.

**Ollama, when the server is big enough:** install with the official script from ollama.com (`curl -fsSL https://ollama.com/install.sh | sh`), which sets it up as a service, then `ollama pull qwen2.5vl:7b`, and set `ID_READER_DRIVER=ollama`. Ollama has **no login**: keep it listening on `127.0.0.1` only and never open its port to the internet.

**If the online server is too small for Ollama but the office has a strong computer:** the office machine can run Ollama and the online site can reach it over a private VPN (WireGuard or Tailscale), with `OLLAMA_URL=http://<the machine's VPN address>:11434`. The scan then travels over the VPN to your own machine and nowhere else; the office machine must be on whenever staff read documents. Do not expose that port publicly.

**What to ask a hosting provider:** SSH access with `sudo` (or root); permission to install packages; `proc_open` allowed in PHP; the amount of memory (4 GB is enough for Tesseract, 16 GB or more is needed for Ollama on CPU) and whether a GPU is offered; outbound internet only if the cloud engine is wanted.

The `ensemble` mode needs both, so its requirement on a server is Ollama's.

**Recommendation:** start with Tesseract on the server (it fits any ordinary VPS, costs nothing, the scan stays with you) and keep staff review as the safety net. Move to Ollama only if the trial on real cards shows Tesseract is not good enough *and* the hosting has the machine for it (or the office-computer option above is acceptable).
## Settings (`.env`)

| Key | Meaning |
|---|---|
| `ID_READER_DRIVER` | `tesseract` (default, the owner's choice for now), `ollama`, `ensemble` or `claude`. **The feature is off until the chosen engine is set up** (the page says what is missing). |
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
