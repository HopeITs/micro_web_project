# M4.11 Attachments: delivery note

21 November 2026, branch `m4-11-attachments` (from `rebuild` at bdf806a). Reference: desktop `Attachments_Form` and `Classes/Images.cs`.

## What works

- **Project and person pages:** each has one `AttachmentsPanel` Livewire line (`owner="project"` / `owner="person"`). The panel shows the files with type, upload date, uploader and size. You can filter by type (with counts), upload several files under one type, open them, download them and delete them.
- **Person photo:** shown at the top of the person's panel, where it can be set, replaced or deleted. A new photo hides the old one. Like the desktop, the "صورة شخصية" type is not offered in the upload list.
- **Attachments page** (`/attachments`, sidebar "المرفقات"): every file the account may see across its cities, newest first. You can search by project number, project or person name, or file name, and filter by category and city.
- **Private storage:** files are saved on the private disk under `storage/app/private/attachments/<CITY>/<project|person>-<id>/<uuid>.<ext>`. They are served only by `GET /{locale}/attachments/{id}`, which rechecks access every time. Responses carry `no-store`, `nosniff`, a CSP header and the original name.
- **Checks on upload:** the extension must be allowed (jpg, jpeg, png, pdf). The content must match the extension (a renamed program is refused). Each file is at most 10 MB, with up to 10 files per upload. All files are checked before any is saved. If a save fails, the files already written are removed.
- **Permissions** (per city, as the desktop gave each role its categories): a file needs `attachments.download` and the grant for its category (`attachments.personal`, `.legal`, `.financial` in `AccountAccess::ATTACHMENT_CATEGORIES`). The same grant allows adding and deleting in that category. The project also needs `projects.view`, and a person file needs `people.view`. A file with no type needs all three category grants. With the seeded roles:
  - administrator: all categories
  - data-entry: personal and legal
  - financial: financial
  - legal: legal
  - guest, media, monitoring, communication: none

  A setting or replacing a photo also needs `people.manage`. Other cities and hidden categories answer 404.
- **Delete is permanent**, as in the desktop: the row and the stored file are removed after an explicit confirmation that says it cannot be undone. The audit log keeps who deleted which file name, type and category, and when (never the content). Only a replaced personal photo is hidden (`deleted_at`) instead of removed.
- **Audit log (v1.3 details):** the new events are `attachments.uploaded`, `attachments.deleted` and `attachments.view` (a download). They are recorded on the project or person, with decision `attachment:<id>`. Details hold the file name and type (and the category on delete), never the content. The audit page shows them as "رفع مرفق / حذف مرفق / فتح مرفق".
- **Imported desktop files:** imported rows whose file is not on the private disk yet (FTP `ftp/<folder>/…`) are listed with "لم يُنقل الملف بعد". They open automatically once M5.4 copies the file to the same path. Unsafe stored paths (`..`, absolute paths, links) are never served.

## Schema

No schema change. Everything uses the approved v1.4 `attachments` and `attachment_types` tables. `legacy_source` is required and only allows `image`, `file` or `meeting`, so web uploads are stored as `image` (the same kind of record as the desktop `image` row) with `legacy_id = NULL`. If the owner wants a separate `web` value, that needs a schema addendum.

## Tests

`tests/Feature/AttachmentScenarioTest.php` has 8 user scenarios:

- data-entry uploads to a project, opens and downloads the files, and the audit records it
- refused files: exe, renamed exe, over 10 MB, no type, photo type
- each role sees only its categories (financial, legal, guest, media)
- another city cannot open or upload
- delete asks for confirmation, removes the row and the file, and logs the name, type and category
- the administrator sees every category, financial included
- the person photo is set, replaced and kept out of the list, and legal is refused
- imported FTP rows stay listed until moved, and a path-escape row is refused
- search on the attachments page across cities and categories

Full suite on 21 Nov (after the owner decisions): 129 tests, 0 failures (1369 assertions; only PHP 8.5 deprecation notices, as before).

Browser (Arabic, `micro-web-m4-11`): data-entry uploaded a png and a pdf to ALP-1001 (desktop width), and the pdf opened inline. The person photo was set at 375px. Financial sees only the financial (imported, not moved) file plus the "2 hidden" note. There was no horizontal scroll at 375px on the project, person and attachments pages.

## Owner decisions (21 November 2026)

Given through the coordinating session; the quoted words are the owner's.

1. **File types and size:** "jpg, png, pdf حتى 10MB". Allowed: jpg, jpeg, png, pdf, at most 10 MB per file, several files per upload (`config/attachments.php`, up to 10 at a time).
2. **Permissions:** "المدير يرى كل الفئات". Each category grant lets its holder see, add and delete in that category:
   - administrator: all categories, financial included
   - data-entry: personal and legal
   - financial: financial
   - legal: legal
   - guest, media, monitoring, communication: none
3. **Delete:** "حذف نهائي كسطح المكتب". Deleting is permanent (row and stored file). It is recorded in the audit log (who, file name, type, category, when; no contents) and asks for an explicit confirmation first.
4. **Person photo** and 5. **Imported FTP files:** "موافق على الاثنين". The photo is set or replaced on the person page with `people.manage` + `attachments.personal`, and the old photo is kept hidden. Desktop FTP attachments are listed as "not moved yet" until M5.4.

## Not in this task

- **Moving the old files (M5.4).** This covers the FTP `micro_images2/<city>` images and the Aleppo `file` BLOBs (the importer already writes those to `attachments/<CITY>/file-…`).
- **Profession-meeting attachments.** The UI comes with M4.15. Those rows are not shown here.
- **Staff profile pictures** (`user.ProfilePicture` BLOB). There is no column in v1.4. If wanted, that needs a schema proposal.
- **Phase 3 frozen-catalog download path** (`AttachmentController`, `routes/legacy-web.php`). It is left as it was and is not loaded.

## Merge notes

Shared files touched, with a small change in each:
- `routes/web.php`: 2 routes after the people block
- `app/Support/Navigation.php`: the attachments item gets its route
- `app/Services/AccountAuditTrail.php`: 1 line of events
- `lang/*/audit.php`: field and event labels
- `resources/views/projects/show.blade.php` and `people/show.blade.php`: 1 line each
- `config/attachments.php`

Not touched: `NoticeService`, `Services/Finance/*`, `app.css`.
