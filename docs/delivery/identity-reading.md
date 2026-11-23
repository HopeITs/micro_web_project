# Reading the identity document from an attachment

Owner request and decisions, 23 November 2026: fill the new identity columns of the person (schema v1.6) from the scan of the identity document that staff already attach, with great accuracy and the possibility to edit from the image. Decisions: a cloud vision model (Claude), proposals shown beside the image and approved by staff, started from the attachment and from the person page.

## How it works

1. A staff member with `people.manage` in the person's city opens **قراءة الهوية** (person page, or the button on an image/PDF row of the attachments panel). The page lists the person's own scans and the scans of the projects they apply for (only in categories the account may see) and shows the chosen one.
2. Pressing **اقرأ الهوية** sends that one file to the vision service. The model must answer through a fixed tool (`record_identity`), no free text: the printed names, birth date, registry place (القيد), national number, issuing line (المنح), sex and, for a passport or residence permit, its number, each with a confidence.
3. The program checks everything locally (`IdCardReader`): Arabic-Indic digits become ASCII; a Syrian national number must be 11 digits; the birth date must be a real date between 1900 and today (day-first dates are understood); names lose stray symbols and digits; a document that is not an identity document is never trusted.
4. The document is read **twice, independently** (`ID_READER_PASSES`, 1–3). A field that differs between the readings is marked «تحتاج مراجعة» with the other candidates kept as clickable alternatives. A field is ticked by default only when the readings agree, the model is sure and the checks pass; doubtful fields are unticked.
5. Staff compare each value with the image, edit any of them, tick what to apply and press **حفظ المحدد على الشخص**. The values are written through `PeopleService::update`: the person form's validation, the duplicate check of the national number (with «save anyway») and the audit details of v1.3 (the national number only as «changed»). Nothing is written before that.

## Settings (`.env`)

| Key | Meaning |
|---|---|
| `ANTHROPIC_API_KEY` | The vision service key. **Without it the feature is off** (the page says so; nothing is sent). |
| `ID_READER_MODEL` | Default `claude-sonnet-5-5`. |
| `ID_READER_PASSES` | Independent readings per press, default 2. |
| `ID_READER_DAILY_LIMIT` | Readings per account per day, default 60. |

## Privacy (the exception to the "no personal data leaves the site" rule of the smart-search plan, approved by the owner)

- The image leaves the server **only when staff press "read"**, to the vision service, to be read. It is not stored by the program anywhere but the private attachment disk, and the readings are not stored anywhere.
- Every press is logged in `ai_queries` (dataset `identity`: who, when, model, tokens, duration; the prompt is the fixed text `[identity document scan]`) and audited as `identity.read` (person, attachment id; success, failure or limit). Neither holds the document's content or any value read.
- The daily limit counts `ai_queries` rows of the account for the day.

## What is not claimed

- The accuracy on real Syrian ID cards has not been measured: it needs a key and a set of real scans reviewed by the owner. The design is what makes the result checkable (fixed schema, local validation, two readings, every value reviewed beside the image), not a guarantee about the model. After the first week of use, count how often staff edit a value; that is the real accuracy figure.
- Sex is read when the card prints it; the older cards do not.
- A scan over 5 MB (image) or 20 MB (PDF) is refused: compress it first.

## Files

`app/Services/Identity/IdCardReader.php` (call, local checks, comparison), `IdentityReviewService.php` (permissions, daily limit, log, apply), `app/Livewire/IdentityReview.php` + `resources/views/livewire/identity-review.blade.php`, route `people.identity`, `config/identity_reader.php`, `lang/*/identity.php`, tests `tests/Unit/IdCardReaderTest.php` and `tests/Feature/IdentityReadingScenarioTest.php` (a faked vision service; no test calls the real one).
