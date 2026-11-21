# M4.13 — Special cases (الحالات الخاصة)

Desktop reference (source frozen, folder `MicroProject newTest/MyWorkApplication`): `SpecialCase_Form.cs` (+ `.Designer.cs`), `Classes/SpecialCase.cs`, `Select.select_project_IDs` / `select_beneficiaries` (autocomplete), `Search_Form.bind_SpecialCases` (the only list of cases, search mode 7), `NewIdea_Form` ("Add Notes" e-mail), `MainForm.specialCaseToolStripMenuItem_Click` / `Check_User_Role`.

Existing web logic to reuse (P5, written on the old `op_*` tables, routes only in the unloaded `routes/legacy-web.php` lines 130–139): `app/Services/Field/SpecialCaseService.php` (uses `RetiresRecords`), `app/Http/Controllers/SpecialCaseController.php`, views `resources/views/field/{special-cases,special-case-form}.blade.php`, lang `field.special.*`, test `tests/Legacy/Feature/SpecialCaseTest.php`; the search mode `app/Services/Reports/Search/SpecialCaseSearch.php` (also `op_*`, belongs to M4.17). Rebuild on the unified schema (v1.4) as M4.12 did for visits (commit `f4faf66`: services on the unified tables, Livewire pages, project-page button, sidebar item, `tests/Feature/VisitScenarioTest.php`, MariaDB check): `special_cases`, `special_case_types`, plus `projects`, `project_applicants`, `people`, `loans`, `web_accounts`, `account_city_capabilities`. Header and project access come from `VisitSupport` (`project`, `header`, `people`, `lock`, `require`) or a shared extract of it. Retire the `op_*` code paths of special cases; `RetiresRecords` itself stays until `ProfessionMeetingService` (M4.15) stops using it.

## Owner decisions (21 Nov 2026)

1. **Reading:** the administrator, data entry and monitoring. Financial, legal, guest and media do not see special cases. **Writing** as the desktop: administrator and data entry.
2. **Projects and date:** any project of the city in any state; the date is required, not in the future, and not compared with the loan date.
3. **Recorder** (recommended option, not asked): defaults to the signed-in account and can be changed to a city account that can write cases.
4. **Delete:** permanent, as on the desktop, after a confirmation, by the administrator and data entry. It is audited with details.
5. **Scope now:** the city-wide list of all cases with filters and export, and the case types in the shared lookup editor (recommended). The administrator "Add Notes" e-mail is left to M4.16 (messages).

## Owner decisions

_(to be filled after the questions below are answered)_

## Facts found (21 Nov 2026)

**Reachable in the last desktop version: yes.** Menu strip "M & E" → "New Special Case" (`SpecialCase_toolStripMenuItem`, Alt+S; `mEItems[6]`), shown to Admin (1), L0_Admin (8) and Data (2); the whole M&E menu is hidden for Financial (3), Lawful (7), Guest (4), Out of Service (6), Media (9). A second "New Special Case" in the old, detached "M & E" menu is dead. Existing cases are opened only from Search → "Special Cases" (double-click a row → `SpecialCase_Form(SC_ID)`); Search is open to every role, including 3, 4, 6, 7, 9 (they can read; export blocked for 4, 6, 7, 9). There is no city check in the form.

**Form, fields in desktop order** (sub-header "مراجعة عن مشروع" — a case is a beneficiary's contact with the office):
1. Project number (autocomplete of funded projects only: current state 1, 4, 5, 7, 8 — but any typed number loads) **or** beneficiary name (LIKE search, first match).
2. Read-only header: project name, phone, address (street + address after fund, "متنقل" alone), funded amount (`Loan_Amount`), project start (`Loan_DateTaken`); info "لم يتم إدخال مبلغ القرض لهذا المشروع" when there is no loan (not blocking).
3. "تاريخ المراجعة" date (default today, no check).
4. "مسجّل الطلب" recorder: users with `IsVisitor = 1`, default the logged-in user.
5. "نوع المراجعة" type from `specialcasetype` (هاتفية / مقابلة in every city; default the first, هاتفية).
6. "سبب المراجعة" reason (single line), 7. "تفاصيل المراجعة" description (multi-line), 8. "الإجراء المتّبع" action (multi-line).

Required on save, in this order: project ("الرجاء التأكد من اختيار المشروع ثم إعادة المحاولة."), recorder ("…اختيار مسجل الطلب…"), type ("…من نوع المراجعة…"), reason ("…كتابة سبب المراجعة…"). Description and action may be empty. Success: "تم تسجيل المراجعة بنجاح. هل تريد إضافة مراجعة جديدة ؟". Choosing another project while editing asks "هل أنت متأكد أنك تريد اختيار مشروع جديد لتسجيل مراجعة جديدة؟" and switches to a new case; an existing case's project is never changed.

**Permissions on save/delete** (`CheckUserPermission`): roles 3, 4, 7, 9 refused ("نعتذر ! لا تملك الصلاحية للقيام بهذه العملية."); 1, 8, 2 save and delete; role 6 is not in the list, so an Out-of-Service user who opened a case from Search could save it (desktop bug, not copied). Delete is a hard delete after "هل أنت متأكد من أنك تريد حذف هذه المراجعة؟"; "الرجاء التأكد من اختيار مراجعة لحذفها!" without a case. Each insert/update/delete writes a `log` line.

**Effects: none.** A special case does not touch the project state, loan, instalments, schedule, tasks or notices; no attachments, no printing. Context menu: Clear Form, Show Application (hidden for 3, 4, 7, 9), Add Notes (admin only, when editing): opens `NewIdea_Form`, which e-mails a note to the city's admins and the recorder ("لا يمكنك كتابة ملاحظات … لا يملكون ايميل محفوظ" when nobody has an e-mail).

**Search list** (`bind_SpecialCases`): project number, beneficiary, father, English name, career (ar/en), category, sub-category, review date, recorder ("مسجّل المراجعة"), type, reason, description, action; newest first; the Search project filters apply.

**Usage (Nov 19 backups `m1src_*`; `docs/planning/m3/reconcile-*.json`, all rows transferred).**

| City | cases | projects | per project (max) | phone / interview | dates | with description / action | on non-funded projects | before the loan date |
|---|---|---|---|---|---|---|---|---|
| ALP | 108 | 92 | 4 | 7 / 101 | 2024-03 → 2026-08 | 108 / 33 | 10 (Waiting 4, Withdrew 3, Delayed 2, Rejected 1) | 1 |
| DAM | 104 | 91 | 3 | 24 / 80 | 2024-03 → 2026-09 | 104 / 104 | 14 (Rejected 12, Delayed 1, Withdrew 1) | 0 |
| HMS | 271 | 204 | 6 | 47 / 224 | 2024-04 → 2026-11 | 270 / 266 | 0 | 8 |
| LAT | 0 | — | — | — | — | — | — | — |
| WAD | 20 | 18 | 2 | 4 / 16 | 2025-07 → 2026-11 | 20 / 18 | 4 (Rejected 4) | 2 |

- Growing use: 58 in 2024, 246 in 2025, 199 so far in 2026 (HMS 138). No dates in the future; no time parts used.
- Reasons are free text, mostly about instalments: تأجيل أقساط / تخفيض قسط / إعفاء من الأقساط / تأخير, then address or project changes, travel, closing or reopening a file, objections to a rejection (WAD), solar-equipment requests. Lengths: reason ≤ 147, description ≤ 2,177, action ≤ 847 characters.
- Recorders: 8–9 distinct users per city, roles admin, L0 admin, data and (now) out-of-service; all flagged visitor except 51 DAM rows whose users are no longer flagged. In the unified data every row has an `account_id` (placeholder accounts for deleted users).
- 28 cases are on projects whose current state is not funded, so the autocomplete's funded filter was not a rule in practice.
- The local `micro_unified` (synthetic seed) has **no special cases**; the seeder must add some (both types, a non-funded project, a case before the loan) before the scenario test. `micro_m3_sample` holds the 503 real rows for the MariaDB check.

## Unified schema check (v1.4)

Everything needed exists: `special_cases` (city_id, legacy_id, project_id NOT NULL, `account_id` → `web_accounts` = the recorder, `special_case_type_id` → `special_case_types`, `case_date` date, `reason`/`description`/`action` text, timestamps, `deleted_at`), `special_case_types` (shared lookup: name_ar/name_en, sort_order, is_active; 1 هاتفية / Phone Call, 2 مقابلة / Interview).

Schema-gap proposals (none blocks the task; nothing changed):
1. No "entered by" column (unlike `visits.created_by_name`): the recorder can differ from the acting account. The audit event (account + details) records who wrote it; *optional* later `created_by_name`/`edited_by_name` as on visits.
2. *Optional* index `special_cases (city_id, case_date)` for the city-wide list (question 8); `project_id` already has its foreign-key index.
3. `special_case_types` is not in `LookupCatalog` (the desktop had no editor for it); adding it needs no schema change (question 7).

## Rules

**Access.** Reading `applications.view` + `special_cases.view` in the project's city (another city: 404, audited refusal 403 as `VisitSupport::require`); writing `special_cases.manage`; deleting `special_cases.delete` (question 5). Version hash of what the page showed, project row lock, retry-safe; idempotent create (a resubmitted form does not make a second case).

**Which projects** (question 2): any project of the city, whatever its state (recommended); the header shows the current state.

**Fields and checks (desktop order):** date (default today; required, a real day, not in the future — question 4), recorder (question 3; default the acting account; a stored recorder who no longer qualifies is shown as stored and kept unless changed), type (required, active `special_case_types`, default هاتفية), reason (required, trimmed, single line, max 1,000), description and action (optional, multi-line, max 65,535). Only changed fields are written on edit; the project of an existing case is never changed. Messages reuse the desktop wording.

**Delete** (question 5): soft delete (`deleted_at`) after the desktop confirmation, audited with details; the administrator sees deleted cases of the project and may restore one (`special_cases.restored`, kept from P5). No other effect (the desktop had none).

**Notes / notices** (question 6): no automatic notice (desktop had none); the desktop's "Add Notes" e-mail is not ported here.

**City-wide list** (question 8): `/special-cases` across the permitted cities, columns as the desktop Search (city, project number, beneficiary, father, career, category, sub-category, date, recorder, type, reason, description, action), newest first; filters date from/to (from > to refused "لا يمكن أن يكون تاريخ البداية أكبر من تاريخ النهاية"), type, recorder, project state, city, text (reason/description/action/beneficiary/project number); Excel/CSV with `reports.export`.

## Permissions (current `AccessRoles`, to keep unless question 1 changes it)

| Role | view | manage | delete |
|---|---|---|---|
| administrator | ✓ | ✓ | ✓ |
| data-entry | ✓ | ✓ | ✓ |
| monitoring | ✓ | ✓ | — |
| financial, legal | per question 1 | — | — |
| media, guest, communication | — | — | — |

Matches the desktop writers (1, 2, 8; the desktop's IsME users were data or admin). Any capability added to a role is rolled out to existing holders with `staff:sync-export-roles` as in M4.10/M4.12 (`AccountAccess::ADDED_CAPABILITIES`).

## Plugs into earlier tasks

- **Lifecycle (M4.8):** none — no state change, no task; the header reads the current state through `LifecycleService`. `NextStep` is unchanged.
- **Finance (M4.9):** read-only loan amount/date in the header. Requests to postpone, reduce or forgive instalments stay free text; the financial staff act on them in the finance page (no automatic rescheduling — the desktop had none). The finance page may show a small "special cases" count/link for holders of `special_cases.view` (question 1).
- **Visits (M4.12):** reuse `VisitSupport` header/people/lock/require (or move them to a shared `ProjectHeader` helper); not a visit, no visitors.
- **Attachments (M4.11):** none (the form has no attachments).
- **Notices / messages (M4.16):** none now (question 6).
- **Search (M4.17):** `SpecialCaseSearch` moves to the unified tables in M4.17; if the list page is built now (question 8) M4.17 reuses its query. Show the recorder's username (the old web hid it because desktop users were not in the web model; now `account_id` → `web_accounts`).
- **Lookups (M4.2):** `special_case_types` in `LookupCatalog` (question 7), shared list, `lookups.manage`.
- **Audit (M4.19, v1.3 details):** events `special_cases.view`, `special_cases.saved` (created/updated), `special_cases.deleted`, `special_cases.restored`, `special_cases.list`, `reports.exported`; details: changed fields before/after (date, type name, recorder username, reason, description, action), project number.

## UI (HC layout, Livewire, AR/EN, RTL, 375px)

Project page: button "الحالات الخاصة" (`applications.view` + `special_cases.view`) → `/applications/{project}/special-cases` (Livewire `ProjectSpecialCases`): header (project number `CITY-####`, beneficiary, project name, state, phone, address, funded amount, project start; "no loan" note), the cases newest first as cards (date, type badge, recorder, reason, description, action; edit/delete where allowed), "new case" form with the fields in desktop order, deleted cases for the administrator with restore. Sidebar "الحالات الخاصة" under the field group (Navigation key `special_cases`, `special_cases.view`) → `/special-cases` (question 8). Ctrl+K: "حالة خاصة جديدة" on a project page, "الحالات الخاصة" list.

## Done when

Role scenarios pass: data entry records a phone case and an interview case on a funded project (recorder defaults to them; reason required; future date refused), edits one (only changed fields audited), deletes one; monitoring records and edits but cannot delete; administrator deletes and restores; a case on a rejected project per question 2; per question 1 financial/legal read or are refused; guest and media refused; another city 404; stale version refused (409); the list page filters and exports and matches the desktop Search on a sample of `micro_m3_sample`. Old `op_*` special-case code, views, legacy routes and the legacy test retired. Full suite passes; pages in AR/EN and at 375px; new queries checked on local MariaDB (ONLY_FULL_GROUP_BY) after the seeder adds special cases.

## Questions for the owner

1. **Who can read special cases?**
   - A (recommended): administrator, data entry and monitoring as now, plus financial and legal read-only (most cases are instalment requests; the desktop Search showed them to every role).
   - B: only administrator, data entry and monitoring.
   - C: every role, including guest and media, as the desktop Search.
2. **Which projects can get a special case?**
   - A (recommended): any project of the city in any state (28 existing cases are on rejected, waiting, withdrawn or delayed projects, e.g. objections to a rejection).
   - B: only funded projects (Accepted, Financed, Finished, Canceled, Closed), as the desktop's suggestion list.
3. **Who is the recorder (مسجّل الطلب)?**
   - A (recommended): defaults to the signed-in account; may be changed to any enabled account of the city that can write special cases.
   - B: always the signed-in account, not editable.
   - C: chosen from the city's visitors (`visits.visitor`), as the desktop.
4. **Date checks**
   - A (recommended): required, defaults to today, not in the future (no loan-date check: 11 existing cases predate the loan).
   - B: as the desktop: no check.
   - C: as visits: also not before the loan date.
5. **Deleting a case**
   - A (recommended): soft delete by administrator and data entry after confirmation, audited; the administrator can restore it.
   - B: administrator only.
   - C: no delete in the web; cases are only edited.
6. **Notes and notices**
   - A (recommended): no automatic notice; the desktop's "Add Notes" e-mail is not ported (staff messages come with M4.16).
   - B: a web notice to the city's administrators (and the recorder) for every new case.
   - C: B, plus an e-mail through the institution SMTP.
7. **Case types list (هاتفية / مقابلة)**
   - A (recommended): add it to the shared lookup editor so administrators can add or rename types.
   - B: keep the two types fixed.
8. **List of all special cases**
   - A (recommended): build now a "الحالات الخاصة" page across permitted cities (filters date, type, recorder, state, city, text; Excel/CSV export); M4.17 Search reuses it.
   - B: only the per-project page now; the city-wide list waits for M4.17 Search.
