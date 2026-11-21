# M1 unified schema — approval record

- **Approved by the user:** 19 November 2026, in reply to the review page, with the word "موافق".
- **What was approved:** `unified-schema-v1` as presented in [m1-schema-review.html](m1-schema-review.html):
  - 137 tables and 1,398 columns.
  - The project-number rules: the original number is kept and is unique per city; the next number is suggested and can be edited; the check and reservation happen under a lock; numbers are shown as `CITY-####`.
  - Lookups merged by name, with `lookup_legacy_map` linking each city's old ids to the new values.
  - The transfer rules (M1.6) and the list of source tables that are not imported.
- **Approved SHA-256:** `89d34b148216158425ec3d769ff31c297062ab4807c0b7c14b14d5d94edd3688`.
  - It covers [unified-schema-v1.json](unified-schema-v1.json), built from [unified-schema-v1.php](unified-schema-v1.php) by [build-schema.php](build-schema.php).
- **What this allows:** M2.1 may write the migrations for exactly this schema without asking again.
- **What needs a new approval:** any change to tables, columns, keys or transfer rules. The changed version gets a new SHA and its own approval.
- **Not covered by this approval:** production activation, hosting, and importing the real data into a shared environment.
- **Open item:** the complete Aleppo backup. The 19 November Aleppo file has only 95 tables, so Aleppo's design comes from its 22 October backup. A complete backup will be checked against this schema before M5.

## Addendum v1.1 — account tables

- **Approved by the user:** 19 November 2026, with the word "موافق", after reviewing [m1-schema-review-v1.1.html](m1-schema-review-v1.1.html).
- **What it does:** the account tables become explicit definitions linked to the unified schema.
  - New table `legacy_staff_users` replaces `web_principals`.
  - `web_accounts`, `account_legacy_links`, `account_city_tasks`, `audit_events` and `web_notices` are re-linked.
  - `web_principals`, `principal_capabilities` and `source_publications` are removed.
  - No data, permission rule or transfer rule changes.
- **Approved SHA-256:** `a783e7f335baac175f1fa3bd01905725200b54978c53a4a4b6f7f72b6e93047e`, covering [unified-schema-v1.1.json](unified-schema-v1.1.json) (138 tables). v1 is unchanged and keeps its SHA.
- **What this allows:** M2 implements v1.1 without asking again.

## Addendum v1.2 — corrections found by the real-data transfer

- **Approved by the user:** 20 November 2026, with the word "موافق", after reviewing [m1-schema-review-v1.2.html](m1-schema-review-v1.2.html).
- **What it changes:**
  - A person's education status links to `education_levels`; `education_grades` (desktop `level`, unused by the program) is removed.
  - The checklist professional tester links to `checklist_members`.
  - `web_notices.legacy_pay_key` keeps the desktop notice codes.
  - Longer national number, phone, work-years and desktop-log fields.
  - The added transfer rules.
- **Checked before approval:** the desktop code links `Level_ID` to `educationlevel` (Search_Form.cs:2312) and fills the tester list from the visitor members (CheckList_Form.cs:305).
- **Approved SHA-256:** `7cbd347ebd5f3db58796724c01ab9339b263a1e1e300d7be4287fdde2098266a`, covering [unified-schema-v1.2.json](unified-schema-v1.2.json) (137 tables, 1,485 columns). v1 and v1.1 are unchanged.
- **What this allows:** M2/M3 implement v1.2 without asking again.

## Addendum v1.3 — change details in the audit log (M4.19)

- **Approved by the user:** 21 November 2026, with the word "موافق", after reviewing [m1-schema-review-v1.3.html](m1-schema-review-v1.3.html).
- **What it changes:** one optional column, `account_audit_events.details` (JSON).
  - Change events only (add, edit, save, delete, state change): the fields that changed, each with its value before and after.
  - Up to 60 fields; each value up to 200 characters (a longer value is cut and marked).
  - Views and sign-ins carry no details.
- **Never stored:** passwords and their hashes, tokens, one-time passwords, encrypted draft contents, attachment contents.
  - The national number is recorded as "changed" only, without its values.
- **Readers:** the same as the audit log (holders of `accounts.manage` in the event's city).
  - The log stays append-only.
- **Approved SHA-256:** `2d9b4f9d15287ac5c3f7c049ccc252245e3639446d258f7a8cd2a379dc5c6baf`, covering [unified-schema-v1.3.json](unified-schema-v1.3.json) (137 tables, 1,486 columns). Earlier versions are unchanged.
- **What this allows:** the migrations and models move to v1.3, and each module may record its change details, without asking again.

## Addendum v1.4 — "remember me" for the e-mail login

- **Decided by the user:** 21 November 2026. Staff sign in with the institution e-mail (`@hcsyria.org`), and the login page gets "remember me". The decision and its approval are written in the addendum inside [unified-schema-v1.4.php](unified-schema-v1.4.php) (`addendum_v1_4`). No separate review page was made, because only one column changes.
- **What it changes:** one optional column, `web_accounts.remember_token` (text, 100 characters).
  - This is Laravel's standard "remember me" token.
  - It is written when someone signs in with "remember me" ticked.
  - It is replaced on sign-out and when the password is changed or reset, so every remembered device is signed out.
  - It is not transferred from the desktop program.
- **Unchanged:** every other table and column. `web_accounts.email` already existed and is unique. Password reset uses the existing `account_password_resets`.
- **Approved SHA-256:** `6d95d4fc6e5375b9cb7975a58439be1e0105246f63ad3228a3fd89b6f46b7e61`. It covers [unified-schema-v1.4.json](unified-schema-v1.4.json) (137 tables, 1,487 columns). Earlier versions are unchanged.
- **What this allows:** the migrations move to v1.4 without asking again.

## Addendum v1.5 — saved views and AI query log

- **Approved by the user:** 21 November 2026 ("موافق"), after the draft was presented in the session. `database/schema/approved.php` declares v1.5.
- **Why:** the smart-search plan (`docs/planning/smart-search-plan-2026-11.md`, phase 1 item 2 and phase 4 audit) needs saved filtered views/reports per user, and an audit log with a daily per-user limit for AI queries. The decision text is in [unified-schema-v1.5.php](unified-schema-v1.5.php) (`addendum_v1_5`).
- **What it adds:** two new tables (139 tables, 1,509 columns; v1.4 had 137 and 1,487). Nothing existing changes.
  - `saved_views` (11 columns): `account_id` (FK `web_accounts`), `dataset`, `kind` (view|report), `name`, `spec` (JSON), `columns` (JSON, optional), `is_shared`, `is_default`, timestamps. Index (`account_id`, `dataset`); unique (`account_id`, `dataset`, `kind`, `name`). No soft delete (the user owns it and may delete it).
  - `ai_queries` (11 columns): `account_id`, `dataset`, `prompt` (stored only after local redaction of national numbers and names), `spec` (JSON, optional), `status` (ok|rejected|error), `model`, `input_tokens`, `output_tokens`, `duration_ms`, `created_at`. Append-only. Index (`account_id`, `created_at`) for the daily limit.
- **Privacy and city scoping:** views store field keys and lookup ids only, never personal data, national numbers or free-text search terms. Neither table has `city_id`: results are always computed at run time within the viewer's permitted cities and capabilities, so a shared view shares the filter, not the data.
- **Tooling note:** `build-schema.php` gained an optional per-table `indexes` key (composite indexes); v1.4 regenerates byte-identical (SHA unchanged).
- **Approved SHA-256:** `b734681deedda50f8ac4e4c4af3069a01f569a05c5f59525102ff13a3c63459d`, covering [unified-schema-v1.5.json](unified-schema-v1.5.json).
- **What this allows:** the migrations, models and code for saved views and the AI query log follow v1.5 without asking again.
- **Note:** the words "مسودة" (draft) inside `unified-schema-v1.5.php` are left as they were, because they are part of the approved JSON; changing them would change the approved SHA.
