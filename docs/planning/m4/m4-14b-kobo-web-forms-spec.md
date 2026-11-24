# M4.14b — Kobo visit forms on the web

Owner decision (21 Nov 2026): build every Kobo visit form on the web for entering, editing and deleting, then retire the sync. M4.14 (23 Nov) already shows the synced submissions read-only; M4.14b adds the write side. No schema change: it uses the 15 Kobo tables of v1.6 and the M4.12 visit tables.

Desktop reference (read-only): `Classes/KoboLocalVisitImporter`, `KoboMonitoringImporter`, `KoboMonitoringStageMapping`, `KoboVisitCompletion`, `KoboProductionSchedule`, `KoboLegacyVisitContent`, `docs/kobo-monitoring-stages.md`.

## How a web Kobo visit is stored (same as a synced one, so every report keeps working)
- `kobo_submissions`: `kobo_submission_id` empty = written on the web; `form_version` `web`; answers in `raw_json` under the field paths (Kobo layout; repeat groups as a list of repetitions); `sync_status` `local_inserted` / `local_updated`; link to the visit in `local_table` + `local_record_id`.
- Opening forms → `visits` (`O-op`, or `V-op` for vehicle forms, with the plate); monitoring and final forms → `me_visits` (kind `o`/`v`, visit number, monthly net income when the form asks it) + `kobo_monitoring_records` (project, person, role, number, date).
- Visitors: city accounts holding `visits.visitor`, 1 to 4, written to `kobo_visit_visitors` and the visit's own visitor rows.
- Effects: visit task ticked; the schedule reminder nearest the visit date settled (`web_notices`); agriculture/livestock plan the next reminder six months later (`KoboSchedule`, flag `retired_by_kobo`).
- Forms come from `kobo_forms` (per city: type, category, fixed stage, project-number / stage / plate fields) and the catalogue `resources/kobo/question-catalog.json`. The project number and beneficiary name are filled from the project; the companion lists are replaced by the visitor accounts.

## Rules
- Capabilities per city: `visits.manage` / `visits.delete` (opening), `me_visits.manage` / `me_visits.delete` (monitoring, final). Funded projects only on creation; no rehabilitation projects; vehicle forms only for vehicle projects; date required, not in the future, not before the loan.
- One opening per project and kind; one monitoring visit per person, kind and number (M4.12 rule). Stage: fixed by the form, answered in its stage field (`M1`/`M&E_1`…), final = 4, agriculture/livestock derived from the previous visit and the "production started" answer (first visit needs it; after M2 the final form is required).
- Edit keeps project, form and person; delete is soft (submission, visit, visitors, record), unticks the task when no other visit of that stage remains and puts the reminder back. Stale pages are refused (version hash).
- Visits the desktop downloaded (`kobo_submission_id` set) stay read-only until `KOBO_WEB_EDIT_SYNCED=true` (`config/kobo.php`) at cut-over.
- Audit: `kobo.visit_saved`, `kobo.visit_deleted` with v1.3 details; answer changes are fields `q{index}` whose before/after read "question: answer" (audit field names must be ASCII identifiers).
- Fix on the way: repeat-group answers were invisible to the M4.14 readers (`raw[$path]`); `KoboCatalog::lookup()` now resolves them for the answers, results and export.

## Pages
`/{locale}/kobo/visits/new/{project}` (form picker, then the form) and `/{locale}/kobo/visits/{submission}`; "New Kobo visit" button (project picker) on the Kobo page and an "Open / edit" link on each submission.

## Not ported / open
- Desktop `KoboLegacyVisitContent` mapping of Kobo answers to the old M&E questions, evaluation result and profit marks: `me_visits.result` is 0 and only the net income is stored (the desktop did the same for most forms); the answers stay in `raw_json`. Say if the M&E result must be computed from Kobo answers.
- Partner visits: the desktop's "same visit for the partner" prompt is not repeated; enter each person's visit.
- Deleting a production visit does not undo the reminders the rule planned (as on the desktop).
- Owner questions still open: the parallel-run period (when to set `KOBO_WEB_EDIT_SYNCED` and retire the desktop sync) and the Kobo token (asked at hosting, M7).

## Tests
`KoboVisitScenarioTest` (9): opening and monitoring entry with task/reminder/visitors/audit, refusals, vehicle plate, edit before/after and delete by role, synced read-only and the cut-over switch, agriculture stages and reminders, repeat groups, refused roles and cities. Browser-checked on MariaDB as administrator: form picker, validation (date before loan), save, redirect to the stored visit; demo rows removed afterwards.
