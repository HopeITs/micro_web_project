# M4.10 — Executive files and purchasing

Desktop reference: `ExecutiveFiles_Form.cs`, `Purchasing_Form.cs`, `Classes/Purchasing.cs`, `Select.select_Lawful`, `UserNotification.Insert_UserNotification` / `Update_NotificationW_ExpiredDateOfExeFile` (source frozen at `2273a2d`, folder `MicroProject newTest/MyWorkApplication`). Existing web logic to reuse: `app/Services/Finance/{ExecutiveFileService,PurchasingService}.php`, `ExecutiveFileController`, `PurchasingController` (built in P5.1 on the old `op_*` tables). Rebuild them on the unified schema as M4.9 did for `FinanceService`: `executive_files`, `purchases`, `purchasing_categories`, `web_notices`, `projects`, `loans`.

Facts found 21 Nov 2026: in the last desktop version both screens are unreachable (Executive Files hangs under an old Financial menu that is not on the menu strip; Purchasing is commented out). Data: 145 executive files, Aleppo only (2018–2021, one per project, all with an impound date); **no purchases and no purchasing categories in any city**.

## Owner decisions (21 Nov 2026)

1. **Purchasing is built now.**
2. **Purchasing access as the desktop code:** administrator, data entry and legal read and write; financial, guest and media are refused (the desktop threw "You don't have the permission"). Monitoring and communication: no access. So `purchasing.view` + `purchasing.manage` move from the financial role to administrator, data-entry and legal.
3. **Executive files are written by the administrator and the legal role only.** The financial role keeps `executive_files.view` (read only); remove `executive_files.manage` from it.
4. **One executive file per project; deletion is soft** (`deleted_at`) after a confirmation, audited with details, and removes the file's notices.

Existing role holders must get the changed capabilities the same way `lookups.manage` was rolled out in M4.2 (`staff:sync-export-roles` or its equivalent) — check how capabilities are stored before changing `AccessRoles`.

## Executive file (per project)

- Fields in the desktop order: file number (required), begin date, current date (on a new file it follows the begin date until changed), number of months, **expiry date = current date + months** (shown live, not stored), impound date, impound type.
- Any project of the city may have one (desktop did not require a loan); header shows project number `CITY-####`, beneficiary, project name, state.
- Save creates or updates the one file. A second file for the same project is refused (also under the project row lock).
- Notices: on create, one notice "الملف التنفيذي" per legal account of the city (`AccessRoles::RECIPIENTS['executive_file']`), due on the expiry date, with the beneficiary name and project; on update the notices follow the new expiry date; on delete they are removed. If the city has no legal account, save still succeeds and the page warns (desktop message: "لا يوجد مستخدمين لإرسال إشعار …").
- Reading: `applications.view` + `executive_files.view` in the project's city (another city: 404); writing `executive_files.manage`.

## Purchasing (per project)

- Header (read only): project number, beneficiary, project name, profession/career, loan amount and loan date; info note "لم يتم إدخال مبلغ القرض لهذا المشروع" when there is no loan.
- Grid in the desktop column order: item name, brand, category (from `purchasing_categories`), quantity (whole number ≥ 0), price (decimal ≥ 0), currency (USD / SYP), invoice date, invoice number, trader name; add row, delete row (confirm; saved rows soft-deleted), **one save** for the whole grid. A row needs an item name. Totals per currency (quantity × price) under the grid.
- Purchasing categories become a shared list in the M4.2 `LookupCatalog` (Arabic/English name, order, active), edited by `lookups.manage` holders in all five cities as the other shared lists.
- Which projects: the desktop's project picker passed "Yes" to `select_project_IDs` — check what that filter means and keep it (likely funded/accepted projects); otherwise refuse with a clear message.
- Reading `applications.view` + `purchasing.view`; writing `purchasing.manage`.

## Common rules

- Version hash of what the page showed on every write, project row lock, retry-safe; audit events `executive_file.*` and `purchasing.*` with fields before/after (v1.3 details), as `finance.*`.
- UI: HC layout, Livewire, AR/EN, RTL, works at 375px. Buttons "الملف التنفيذي" and "المشتريات" on the project page for holders of the view capability; actions in the Ctrl+K palette. Pages `/applications/{project}/executive-file` and `/applications/{project}/purchases`.
- Retire the old `op_*` code paths of these two services (do not leave two implementations).

## Done when

Role scenarios pass: legal creates, edits and deletes an executive file and the legal accounts receive/follow/lose the notice; administrator may do the same; financial reads only; data entry and guest refused; data entry and legal record purchases (add, edit, delete a row, totals per currency); financial, guest and media refused purchasing; another city refused; a second executive file refused. Full suite passes; pages render in AR/EN and at 375px; new queries checked on local MariaDB `micro_unified` (ONLY_FULL_GROUP_BY).
