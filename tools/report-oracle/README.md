# Report oracle

## M4 gate: `php artisan report:oracle` (current)

Compares what the website's reports and exports give with what the desktop's own SQL gives on the city source databases, for every project the unified database holds. The web side runs in process the same services the pages and exports use (`SearchService`, `PaymentScheduleService`, `LifecycleService`), as a temporary account holding every capability in every city, inside a transaction that is rolled back: nothing is written to either database.

```powershell
php artisan report:oracle --database=micro_m3_sample --source-user=root --out=docs/delivery/m4-gate-oracle-report.json
```

- `--database`: the unified database holding the transferred data (default: the app database). `micro_m3_sample` holds the full M3 transfer of the five cities.
- `--sources`: prefix of the city source databases (default `m1src_`, so `m1src_alp` … `m1src_wad`, the 19 November backups).
- `--source-host`, `--source-port`, `--source-user`: the source server (default: the app's connection). The password comes from the `ORACLE_SOURCE_PASSWORD` environment variable, never the command line.
- `--only=loans,details`: run some checks only.
- Exit code 0 when there are no unexplained differences, 1 otherwise.

The report holds counts, project numbers and the differing values. Names, national numbers, birth dates and free text about people are masked (`•`) in the examples.

| Check | Compares |
|---|---|
| applications | every project: description, request date, current status, both directions |
| statistics | projects by status and by request year, per city |
| loans | the 15 figures of the Loans and Payments report per loan (`BuildLoanReportQuery`) |
| instalments | the payment schedule export (paid and unpaid): count and amount per project, currency and paid state, and totals per city |
| family | every family member row (`FamilyMember_bind`) |
| details | the twelve detail search types (`bind_person_tables` / `bind_project_tables`), every row as a multiset |
| other modes | attachments, visit dates, M&E visits, special cases, educations |
| state history | every project's state history (state, date, current) |

Detail and other-mode exports are read in Arabic (list names as the desktop stores them). Arabic vowel marks, tatweel and the hamza forms of alef are ignored, because the shared lists of M3 keep one spelling for all cities.

### Known, explained differences (listed, not failures)

Each one was checked on the source data (24 Nov 2026). Each is limited to the projects it applies to, found by a query on the source database.

- **Loans**:
  - "Total instalments" may differ by one cent and "Return rate" by its last digit: the desktop rounds a float column, the web the exact decimal.
  - "Remaining instalments" is rounded to two decimals.
  - Where nothing is paid, the desktop shows the paid amount, remaining amount and remaining instalments blank; the web shows 0 or the full amount.
  - Months late is blank on the web for Closed projects; the desktop blanks it only for Canceled ones (spec M4.17).
  - A loan with nothing to return and no instalments: blank in the desktop, 0 on the web.
- **Several projects per person**: the desktop keys M&E visits by person and shows them under every project of that person; the web keeps each visit on the project the transfer linked it to.
- **Partners**:
  - The desktop's project-level searches (materials, energy sources, special cases) repeat each row once per applicant.
  - The desktop's visit dates read only the beneficiary's M&E visits; the web reads those of the project, partners included.
- **Two visits of a kind** (two openings, or two M&E visits with the same number): the desktop shows the first one entered, the web the earliest date.
- **Applicant without a family row**: the desktop's detail searches reach people through family rows only; the web lists them.
- **Family rows without a family book**: skipped by the M3 transfer and recorded in `docs/planning/m3/reconcile-<city>.json`.
- **Project files from the desktop file table** (PDF, no image type): the desktop attachment search reads the image table only.
- **Amounts with three decimals** (15 material prices and one energy cost) are stored with two. M3 recorded fewer of them as `money_rounded`. The owner accepted the rounding on 24 Nov 2026: money columns keep two decimals.

## P6.3: `oracle.php` (superseded)

`oracle.php` compared the old P6 web over HTTP (CSV downloads from `/en/search-reports/export?mode=…`, `/en/applications/export` …) with the restored `micro_projects_source_<city>` databases. Those routes no longer exist: M4 exports go through single-use tokens. The file stays as the reference for the desktop SQL that `report:oracle` reuses.
