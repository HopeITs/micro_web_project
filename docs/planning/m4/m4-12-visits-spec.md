# M4.12 — Visits: opening, M&E, closing, rehabilitation, visitors, schedule, results, visitor statistics

Desktop reference (source frozen, folder `MicroProject newTest/MyWorkApplication`): `Visit Forms/ChooseVisitKind_Form`, `VOpening_Other_Form`, `VOpening_Taxi_Form`, `V_ME_Other_Form`, `V_ME_Taxi_Form`, `VClosing_Form`, `VClosing_Rehab_Form`, `Visit_Results_Form` (+ `.Kobo.cs`), `Visits_Schedule_Form`, `Visits_Statistics`; classes `M_and_E.cs`, `Office_Monitoring.cs`, `VisitRehab.cs`, `VisitorStatistics.cs`, `KoboVisitCompletion.cs`, `KoboProductionSchedule.cs`, `UserNotification.cs`, `Select.select_visitors`, `Partners_MessageBox`; visit-notice generation in `Loans_Form.cs` (already ported in M4.9).

Existing web logic to reuse (P5.2/P5.3, written on the old `op_*` tables, routes only in the unloaded `routes/legacy-web.php`): `app/Services/Field/{VisitService,MonitoringVisitService,RehabVisitService,VisitScheduleService,VisitKinds,RetiresRecords}.php`, `VisitController`, `MonitoringVisitController`, `RehabVisitController`, views `resources/views/field/{visit,visits,me-hub,me-visit,rehab-visit,schedule}.blade.php`, tests under `tests/Legacy/Feature/*Visit*`. Rebuild them on the unified schema (v1.4) as M4.9 rebuilt `FinanceService` (commits `4c5314f`, `c2461c1`: services on the unified tables, Livewire pages, project-page button, sidebar item, scenario test, MariaDB check): `visits`, `visit_answers`, `visit_visitors`, `me_visits`, `me_visit_answers`, `me_visit_visitors`, lookups `visit_questions`, `visit_question_options`, `me_questions`, `me_answers`, `me_profit_marks`, `me_result_ranges`, plus `projects`, `project_applicants`, `people`, `loans`, `loan_installments`, `project_tasks`, `web_notices`, `kobo_submissions`, `kobo_monitoring_records`, `kobo_visit_visitors`, `project_state_history` via `LifecycleService`. Retire the `op_*` code paths and `RetiresRecords` (the unified tables have `deleted_at`; no `operational_deletions`).

## Owner decisions (21 Nov 2026)

1. **Visitors:** accounts of the city holding a new `visits.visitor` capability, granted automatically to imported desktop users flagged visitor and to the monitoring role; the administrator can add it to others.
2. **At least one visitor** on every visit entered in the web (opening, M&E, closing, rehabilitation).
3. **Closing visit:** on the first save only, Financed → Finished through the lifecycle; a project already Finished, Canceled or Closed keeps its state.
4. **Delete:** soft delete by the administrator and data entry; the task is unticked and the schedule notice becomes unseen again; the Finished state set by a closing visit is not reverted (the page warns).
5. **Kobo visits:** read-only in M4.12 (the sync stays read-only for now). **Plan change:** every Kobo visit form will be built natively in the web (enter, edit, delete), so the Kobo sync can be retired. See task M4.14b in the rebuild plan.
6. **Visit date:** required, not in the future, not before the loan date.
7. **Visitor statistics:** administrator only (`visits.statistics`).
8. **Visit results report:** built now over all visits, including those written by Kobo; M4.14 adds the Kobo-only columns.

## Facts found (21 Nov 2026)

**Reachable screens (last desktop version).** The live menu strip has "M & E" → Visit Forms (the `ChooseVisitKind_Form` hub), Visit Dates Schedule, Visit Results, Visitors Statistics (plus Kobo sync, special cases and meetings, which are other tasks). A second "M & E" menu in the Designer is never added to a strip (dead duplicate). Menu visibility (`MainForm.Check_User_Role`): Admin (1) and L0_Admin (8) everything; Data (2) everything except Visitors Statistics; Financial (3), Lawful (7), Guest (4), Out of Service (6), Media (9): the whole M&E menu hidden. There is no city check anywhere in the visit forms.

**Hub (`ChooseVisitKind_Form`).** Project picker lists projects whose current state is Accepted, Financed, Finished, Canceled or Closed (state ids 1, 4, 5, 7, 8 = `FinanceService::FUNDED`). Project kind: rehabilitation = type contains "ترميم" AND fund type contains "منحة"; vehicle = sub-category 1 or 2 (Taxi, Other Vehicles); everything else (agricultural included) uses the "Other" forms. One opening and one closing per project (button disabled when a `Kind` with `op`/`cl` exists). Next M&E number = max + 1. Rehabilitation projects: opening and M&E refused ("لا يوجد امكانية لاضافة زيارة افتتاحية/مراقبة وتقييم لمشاريع الترميم"); only the rehabilitation closing visit. M&E visits are per person (beneficiary or partner); after saving, `Partners_MessageBox` offers the same visit number for the partner. Opening and closing are per project.

**Visitors.** Always desktop users with `IsVisitor = 1` (`Select.select_visitors`), never team members or free text (free-text names exist only on Kobo visits, `kobo_visit_visitors.display_name`). Opening/closing/rehab: up to 4 (`visit_user`); M&E: up to 2, each with a rating ضعيف / وسط / جيد (`mevisit_user.Mark`). Only the rehab form requires one visitor. In the unified schema visitors are `web_accounts` (`visit_visitors.account_id`, `me_visit_visitors.account_id`); 53 references to deleted desktop users got placeholder accounts; 53 empty rehab slots were skipped. Users flagged visitor (`legacy_staff_users.is_visitor`): ALP 41 of 58, DAM 14/45, HMS 12/46, LAT 12/27, WAD 13/47.

**Desktop permissions on save/delete.** Opening, M&E and closing forms refuse roles 3, 4, 7, 9 ("نعتذر ! لا تملك الصلاحية للقيام بهذا الفعل."); 1, 8, 2 write and delete. The rehab form has no role check (menu only). Visit Results export blocked for 4, 6, 7, 9; schedule export blocked for 4, 6, 7; schedule hide/restore/note have no write check. Visitors Statistics: admins only (menu).

**Effects.** Tasks (`task_microproject`, all type "f"): opening → 25 "Opening Visit"; M&E number 1–5 → 26 M1, 27 M2, 28 M3, 29 "MFinal", 30 "M5"; closing (incl. rehab) → 31 "Clearance". Notices (`user_notification`, `Pay_ID = -21`, `User_ID NULL`): opening marks 'Visit Opening-O/V' seen; M&E marks 'Visit M{n}-O/V' of that person seen (MFinal and Solar bodies are not matched by the manual forms — a desktop gap; Kobo sync matches them); closing marks every notice of the project seen and sets the state "منتهي" (Finished) **on every save**, inserting a new state row each time. Deletes untick the task but leave child rows, notices and the state untouched. The rehab update path is broken (its SQL string is overwritten; answers and visitors are never saved on edit). Kobo-imported visits open read-only (save/delete disabled).

**Usage (Nov 19 backups `m1src_*`; reconcile files `docs/planning/m3/reconcile-*.json`).**

| City | visits (O-op / V-op / O-cl / V-cl / R-cl) | visit visitors | M&E visits (o / v; by number) | from Kobo (M&E / opening) | dates |
|---|---|---|---|---|---|
| ALP | 1,301 (738 / 197 / 233 / 106 / 27) | 2,093 | 3,495 (2,640 / 855; 1:878 2:799 3:703 4:720 5:393 6:2) | 524 / 114 | 2018-07 → 2026-11 |
| DAM | 585 (366 / 174 / 39 / 6 / 0) | 1,219 | 1,982 (1,278 / 704; 1:498 2:451 3:379 4:482 5:172) | 514 / 102 | 2021-11 → 2026-11 |
| HMS | 1,468 (1,067 / 99 / 269 / 33 / 0) | 1,601 | 3,984 (3,622 / 362; 1:1,127 2:894 3:727 4:870 5:366) | 1,044 / 170 | 2020-12 → 2026-12 |
| LAT | 187 (181 / 6 / 0 / 0 / 0) | 468 | 273 (268 / 5; 1:141 2:86 3:46) | 245 / 134 | 2024-11 → 2026-11 |
| WAD | 121 (114 / 7 / 0 / 0 / 0) | 158 | 204 (196 / 8; 1:128 2:66 3:10) | 204 / 91 | 2024-11 → 2026-11 |

- Since August 2025 almost every visit comes from Kobo: manual entries since then are ALP 12 openings + 1 closing, HMS 1 opening + 1 M&E, no manual M&E elsewhere. Closing visits stopped (last ALP Feb 2026, DAM/HMS Jun 2025; never in LAT/WAD) — for loans the clearance notice replaced them. The rehabilitation closing exists only in Aleppo (27, Jun 2023 – Apr 2024).
- M&E number 4 is the schedule's MFinal (Kobo stage 4); number 6 occurs twice (ALP vehicles).
- Duplicates of (person, kind, number) already exist: ALP 95, DAM 78, HMS 209, LAT 1, WAD 14 (mostly Kobo re-submissions); DAM has 3 projects with two opening visits. A unique index is therefore not possible; the check stays in the application for new visits.
- Stored M&E visitor marks: جيد / وسط / ضعيف plus older ممتاز, سيئ and empty values — display as stored.
- `visits.type` holds old free values (MC, M, MSC, …; ALP 761 empty) — kept, not edited.
- Schedule notices: bodies Opening-O/V (+ " (Solar)"), M1–M5-O/V, MFinal-O/V, Closing-O/V/R, "براءة الذمة (Clearance)", "Visit (Trophy)O"; a large unseen backlog of MFinal and M2/M3 (e.g. HMS 348 MFinal-O unseen). Kobo-retired reminders: ALP 80, HMS 206, LAT 3, WAD 103 (`web_notices.retired_by_kobo`).
- The local `micro_unified` holds only the synthetic seed (22 visits per city with kind `opening`/type `other`): the transfer keeps the desktop codes (`O-op`, `V-op`, `O-cl`, `V-cl`, `R-cl`; M&E `o`/`v`), so the seeder must be aligned to them before tests.

## Unified schema check (v1.4)

Everything needed exists: `visits` (kind, type, visit_date, indicators, vehicle_number, office_comments, created/edited_by_name), `visit_answers` (form `visit`/`rehab`/`office_monitoring`, question_number, answer_text, answer_value; one row per answered question), `visit_visitors`, `me_visits` (person_id, project_id, visit_number, kind, profit dec, answer_1/2, indicators, notes, result), `me_visit_answers`, `me_visit_visitors` (mark), the question/answer lookups (`visit_questions` gives the opening/closing wording per kind and number: O-op 1–7, V-op 1–6, O-cl 1–5, 7, 8), `me_profit_marks` (5 active ranges, marks 0/2/4/8/10), `me_result_ranges` (0–40 Poor, 41–60 Average, 61–80 Good, 81–100 Excellent — used by the desktop search, not by the forms), `web_notices` (original_date, change_reason, retired_by_kobo), `projects.street_id`/`address_after_fund` (opening place). Kobo origin is known from `kobo_submissions.local_table` + `local_record_id` (visit / mevisit) and `kobo_monitoring_records.me_visit_id`.

Schema-gap proposals (none blocks the task; nothing changed):
1. *Optional* index `kobo_submissions (city_id, local_table, local_record_id)` — the "is this visit from Kobo" check runs on every hub and results row.
2. No visitor flag on web accounts (only `legacy_staff_users.is_visitor`). Proposed without a schema change: a capability `visits.visitor` in `account_city_capabilities` (see question 1).
3. `web_notices` has no `deleted_at` (known since M4.9): the schedule's "delete" stays `seen = 1` as on the desktop.
4. `visit_visitors` has no slot order: the order is the insertion order (id).

## Rules

**Common.** Reading: `applications.view` + `visits.view` (opening/closing/rehab, schedule) or `me_visits.view` (M&E) in the project's city (another city: 404). Writing `visits.manage` / `me_visits.manage`; deleting `visits.delete` / `me_visits.delete`. Only funded projects (Accepted, Financed, Finished, Canceled, Closed) get new visits; rehabilitation grant projects only the rehab closing; vehicle projects (sub-category 1/2) the V forms. Version hash of what the page showed, project row lock, retry-safe; `created_by_name`/`edited_by_name` = acting username. Kobo-imported visits are shown read-only with a "from Kobo" mark (question 5). Up to 4 visitors on opening/closing/rehab, up to 2 with a rating on M&E, chosen from the city's visitor accounts (question 1), no account twice; on edit the visitor rows are replaced (soft-deleted and re-added only where they changed).

**Opening visit (O-op / V-op), fields in desktop order.** Read-only header: project number `CITY-####`, beneficiary, project name, phone, address, funded amount (`loans.amount`), project start (`loans.loan_date`); info note "لم يتم إدخال مبلغ القرض لهذا المشروع" without a loan (not blocking, as desktop). Visit date. Other projects: project street + detailed address (writes `projects.street_id`, `address_after_fund`). Vehicles: plate number (`vehicle_number`). Questions 1–7 (Other) / 1–6 (Vehicles), wording from `visit_questions`, each a note + choice (yes/no; the loan-share question 25/50/75/100 % stored 1–4; unanswered −1 → no answer row). Section 7 "كيف تصف تجربتك…": six office ratings (Other: الاستقبال، الانتظار، الزيارة، المحامي، المشتريات/مسيّر المعاملات، التصوير; Vehicles: … المشتريات) bad/acceptable/excellent 0/1/2, each with a note (`visit_answers.form = office_monitoring`); beneficiary comments (`office_comments`); M&E team indicators; visitors V1–V4. A second opening for the project is refused ("لا يمكن انشاء زيارة افتتاحية ثانية لهذا المشروع، لأنه يوجد زيارة سابقة"). On create: tick task "Opening Visit" when assigned, mark the shared notice 'Visit Opening-O/V' (and the " (Solar)" variant) seen.

**M&E visit (o / v).** Per person of the project: hub lists the beneficiary and partners with their visits. Header as opening. Visit number (suggested max + 1 of that person and kind; whole number 1–9; a number already used by that person refused), visit date, then the questionnaire from `me_questions`/`me_answers` by kind in the desktop sections — Other: environmental (7 incl. the free-text "would you recommend the items" → `answer_1`), financial (9), profit, experience (2), social/health (7); Vehicles: car factors (4 + free-text satisfaction), financial (5), profit, experience (2), social/health (7) — each an answer choice plus a note; net monthly profit (required, decimal, may be negative); indicators; beneficiary notes; visitors 1–2 each with rating ضعيف/وسط/جيد. Required: person, profit ("لا يمكن ترك الربح الصافي فارغاً"), every question answered ("لا يمكن ترك سؤال بدون إجابة"). Result = sum of the chosen answers' marks + the mark of the active profit range (`me_profit_marks`, min ≤ profit ≤ max); stored in `result`, shown with its `me_result_ranges` evaluation. `project_id` is always filled. On create: tick task by number (1→M1, 2→M2, 3→M3, 4→MFinal, 5→M5); mark the person's notice 'Visit M{n}-O/V' seen, number 4 also 'Visit MFinal-…', and the Solar variants (fixing the desktop gap). After saving for a project with a partner: offer "record visit {n} for {partner}".

**Closing visit (O-cl / V-cl).** Header as opening; visit date; Q1 goal achieved (yes 0 / no 1), Q2 financial return (debts 0, no change 1, good 2, excellent 3), Q3 financial independence (declined 0, same 1, improved 2), Q4 indirect benefit (text), Q5 future plans (sell 0, no 1, yes 2, expand 3), Q6 four ratings 0/1/2 (التواضع، الإصغاء، العدالة، المسؤولية), Q7 hope added and Q8 notes on the team (text; slot mapping to verify against the 233 Aleppo rows before building), indicators, visitors. One closing per project. On create: tick task 31 "Clearance", mark every unseen notice of the project seen, move the project to Finished through `LifecycleService::enterNamed` (question 3).

**Rehabilitation closing (R-cl).** Header: project number, beneficiary, funded amount; visit date; Q1 four ratings (الزيارة الأولية، الاستقبال، التصوير، مسؤول المشتريات) 0/1/2; Q2 eight workshop ratings (بلاط + بيتون، صحية، كهربائي، المينيوم، دهان، نجار، حداد، تكييف) 0/1/2 or N/A; Q3 earthquake damage left, Q4 informed of all amounts, Q5 suggestions — yes 0 / no 1; each with a note (`visit_answers.form = rehab`, 15 numbers); visitors. Required: every choice ("الرجاء التأكد من أنك قمت باختيار جميع الاجابات") and at least one visitor. Effects as the closing visit. Edits save properly (desktop bug not copied).

**Delete** (question 4): soft delete of the visit with its answers and visitors after a confirmation ("هل أنت متأكد أنك تريد حذف معلومات هذه الزيارة بشكل نهائي؟"), audited with details; unticks the visit's task; the notice the visit had marked seen becomes unseen again.

**Visit schedule** (`/visits/schedule`, all permitted cities; port `VisitScheduleService`): shared `web_notices` of kind visit/clearance (`account_id` NULL), current state joined. Columns in desktop order: project number, project, beneficiary, state, donor, project type, visit (body), date, home area, project area, mobile, next unpaid instalment due date, visit status (Done / Not yet: matching `visits`/`me_visits` of the same kind and number, Kobo ones included, nearest scheduled date), note. Filters: months (multi), year, kind (all / vehicles / other), visit (Opening, M1–M5, MFinal, Closing, Clearance), project area, project type, city, show hidden, text search. Actions (`visits.manage`): hide/restore with confirmation, edit note, reschedule (web addition kept: new date + reason, original date kept, optionally shift the project's later unseen visits). Export Excel/CSV with `reports.export`. Generation stays in M4.9 `ProjectNotices::generateVisitSchedule`; Kobo-driven additions/retirements are M4.14.

**Visit results** (`/visits/results`, question 8): the desktop's two views — count of each answer per question, and beneficiaries' answers (M&E visits; opening and closing per kind) — with filters date from/to, visit (M1–M5 / Openings / Closings), kind (vehicles / other), project type, category, sub-category, career, "last visit per beneficiary", city; Excel/CSV with `reports.export`.

**Visitor statistics** (`/visits/statistics`, question 7): one row per visitor account: Opening, M1–M5, Closing, total; sorted by total; filters date from/to (default the last year; from > to refused "لا يمكن أن يكون تاريخ البداية أكبر من تاريخ النهاية"), project type, city; totals row; counts distinct visits from `visit_visitors`, `me_visit_visitors` and Kobo `kobo_visit_visitors` (a Kobo visit already written as a visit counted once); export with `reports.export`.

## Permissions (current `AccessRoles`, to keep unless a question changes it)

| Role | visits / me_visits view | manage | delete | schedule actions | results / statistics |
|---|---|---|---|---|---|
| administrator | ✓ | ✓ | ✓ | ✓ | ✓ / ✓ |
| data-entry | ✓ | ✓ | ✓ | ✓ | ✓ / per question 7 |
| monitoring | ✓ | ✓ | — | ✓ | ✓ / per question 7 |
| financial, legal, media, guest, communication | — | — | — | — | — |

This matches the desktop (menu hidden for 3, 4, 6, 7, 9). Any new capability (`visits.visitor`, `visits.statistics`) is added to `AccountAccess::CAPABILITIES` and rolled out to existing holders with `staff:sync-export-roles` as in M4.2/M4.10.

## Plugs into earlier tasks

- **Lifecycle / next step (M4.8):** the visit tasks are "f" tasks, so `NextStep` already shows "Opening Visit"/M1… for a Financed project; add the link to the project's visits page for `visits.manage` holders ("open visits"). Closing → Finished via `LifecycleService` (state notice to staff as every change).
- **Finance (M4.9):** loan amount/date in the headers; visit notices come from `ProjectNotices::generateVisitSchedule`; reuse `ProjectNotices::visitDone` / `clearProject` on the unified columns.
- **Notices:** only shared visit notices are touched; no new notice kinds.
- **Attachments (M4.11):** the desktop visit forms have no attachments; nothing to plug (visit photos, if ever, would be a project attachment category).
- **Audit (M4.19, v1.3 details):** events `visits.view/saved/deleted`, `me_visits.view/saved/deleted`, `visits.schedule`, `notifications.rescheduled`, `visits.results`, `visits.statistics`, `reports.exported`; details: changed fields before/after (date, plate, street/address, indicators, comments), answers by question number with option names, office ratings, M&E number/profit/result and answers by question name, visitors added/removed by name, task ticked/unticked, state change, notices seen.

## UI (HC layout, Livewire, AR/EN, RTL, 375px)

Project page: "Visits" button (`visits.view` or `me_visits.view`) → `/applications/{project}/visits`: kind icon, opening card, M&E per person (number, date, result, evaluation, visitors), closing or rehab card, add buttons only where allowed, Kobo mark. Pages `/applications/{project}/visits/{kind}`, `/applications/{project}/me-visits/new/{person}`, `/applications/{project}/me-visits/{visit}`; `/visits/schedule`, `/visits/results`, `/visits/statistics`. Sidebar "الزيارات" (Navigation key `visits`) opens the schedule, with results and statistics as sub-items; Ctrl+K actions.

## Done when

Role scenarios pass: data entry records an opening (task ticked, notice seen, place updated), an M&E visit with result and evaluation for the beneficiary and the partner, and a closing (Finished, notices seen); monitoring records but cannot delete; administrator deletes (task unticked, notice back); rehab closing on a rehab grant project, other kinds refused there; vehicle project gets the V forms; a second opening/closing and a used M&E number refused; Kobo visit read-only; schedule filters/hide/note/reschedule/export; results and visitor statistics match the desktop on a sample; financial, legal, guest refused; another city refused. Full suite passes; pages in AR/EN and at 375px; new queries checked on local MariaDB (ONLY_FULL_GROUP_BY) after the seeder uses the desktop kind codes.

## Questions for the owner

1. **Who can be chosen as a visitor?**
   - A (recommended): accounts of the city holding a new `visits.visitor` capability, granted automatically to imported desktop users flagged "visitor" and to the monitoring role; the administrator can add others.
   - B: any enabled account of the city with the monitoring or data-entry role.
   - C: any enabled account of the city.
2. **Must every visit name its visitors?**
   - A (recommended): at least one visitor on every visit entered in the web (opening, M&E, closing, rehab).
   - B: as the desktop: only the rehabilitation closing requires one.
3. **What does a closing visit do to the project state?**
   - A (recommended): on the first save only, Financed → Finished through the lifecycle; a project already Finished, Canceled or Closed keeps its state.
   - B: as the desktop: every save sets Finished, whatever the state.
   - C: never changes the state (only the task and the notices).
4. **Deleting a visit**
   - A (recommended): soft delete by holders of the delete capability (administrator, data entry); the task is unticked and the visit's schedule notice becomes unseen again; a closing visit's Finished state is not reverted (a warning says so).
   - B: as A, but only the administrator may delete.
   - C: as the desktop: delete only unticks the task.
5. **Visits imported from Kobo**
   - A (recommended): shown read-only here; corrections happen in Kobo and come back with the sync (M4.14).
   - B: editable in the web like manual visits.
6. **Visit date checks**
   - A (recommended): required, not in the future, not before the loan date.
   - B: required and not in the future only.
   - C: as the desktop: no checks.
7. **Who sees the visitor statistics?**
   - A (recommended): administrator only, as the desktop (new capability `visits.statistics`).
   - B: administrator and monitoring.
   - C: everyone who can read visits.
8. **Visit results report**
   - A (recommended): build it now (count of each answer; beneficiaries' answers) over all visits including the ones written by Kobo; M4.14 later adds the Kobo-only columns.
   - B: leave the whole results report to M4.14.
