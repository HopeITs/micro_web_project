# M4.17 — Search (22 types), statistics, cross-city reports

Desktop reference (read-only, `D:\MyProjects\MicroProject newTest\MyWorkApplication`): `Search_Form.cs`, `Search_Form.Reporting.cs`, `Statistics_Form.cs`, `Statistics_Form.Reporting.cs`, `Classes\Select.cs` (loan report 534-640, loan summary 665-842), `Classes\CheckList.cs` (432-611), `Classes\StatisticsReportQuery.cs`, `Classes\CityReportingService.cs`.

Web reference to port (old schema, not loaded, do not keep `OperationalTables` calls): `app/Services/Reports/Search/*`, `app/Services/Reports/ApplicationFilters.php`, `ApplicationReport.php`, `app/Http/Controllers/SearchReportController.php`, `ReportController.php`, `resources/views/reports/*`, `routes/legacy-web.php:68-71`, `tests/Legacy/Feature/{SearchReports,OtherSearchModes,DetailSearch,LoanFilters,ApplicationStatistics}Test.php`.

## Owner decisions (23 November 2026)
1. **Search is one page with the 22 types** as on the desktop ("Search by"), with the desktop columns and filters, across every permitted city with a City column, a project counter, a link to the record, Excel/CSV.
2. **Statistics: the 15 desktop axes with a chart, plus the free report builder** (smart-search plan, phase 3).
3. **Permissions follow the website capabilities**, not desktop role numbers: a type is offered in the cities where the account holds the view capability of its module; export needs `reports.export` (money also `finance.export`).
4. **Search is read only.** The desktop's delete of a family member from the results is not carried over.

## Parts
- **A. Search** — filters service + 22 modes + page.
- **B. Report builder** — free pivot on the existing Datasets + the chart component.
- **C. Statistics** — the 15 axes (uses A's filters and B's chart).
- Cross-city: every part runs over the account's permitted cities in one query (one database), so the desktop `CityReportingService` becomes the City column, a city filter and per-city breakdown. No per-city failure/coverage text is needed.

## A. Search

### Common rules
- Base: the project's state in force (desktop `Current_State = 1`), one row per the desktop's grain for the mode.
- Cities: those where the account holds **all** capabilities of the mode; a mode with no city is not listed; the page is refused (403, audited) when no mode is available. City filter (multi) on every mode.
- Filters (multi-select, by lookup id, shown **only on modes whose query can honour them** — the desktop showed some on the wrong modes): fund type, project type, type detail (sub type), status, donor, donor group, category, sub category, career, parish, marital status, home street, project street, partnership (individual = 1 / partnership > 1), sex, age at request (age ranges list; **all selected ranges apply** — the desktop used only the first), request date range, fund date range (loan date taken), requested amount range, fund amount in USD range (`SP: amount / exchange rate`, `USD: amount`, rounded to 2). An empty "to" means open-ended (the desktop treated it as 0; not carried over). Free-text box (number, with or without city code, or name; Arabic folded as elsewhere) on every mode; it travels in the Livewire body, never in a URL. Mode, city and lookup-id filters may be in the URL.
- Result: 50 rows per page, header sort where cheap, counter = distinct projects (city + number) when the mode has a project number, else rows. Each row links to its record where the reader may open it (project, person, finance, checklist, special case, visit, meeting, attachments).
- Export: Excel/CSV of all matching rows with the visible columns plus City, through the existing single-use token pattern (`DatasetExport` style; no search text in a URL), streamed with `TableExport`/`CsvExport`, `throttle:10,1`, audited `reports.exported` with mode, row count; listing audited `reports.search` (mode, no term).
- Sensitive columns (national number, mobile) only with `people.view` in the row's city.

### Modes (key → grain; capabilities; filters; columns as the old web classes list them)
| # | Key | Grain / source | Capabilities |
|---|---|---|---|
| 0 | applications | project × beneficiary | applications.view, people.view |
| 1 | family | family member (no project filters) | people.view |
| 2 | loans | loan (one per project) | finance.view, applications.view; export + finance.export |
| 3 | attachments | attachment | the attachment category grants as M4.11 + projects.view |
| 4 | visit-dates | project: Opening, M1–M5, Closing dates | visits.view |
| 5 | me-visits | M&E visit; "last visit only" switch | visits.view |
| 6 | checklists | checklist (≈50 columns incl. need-assessment marks total) | assessments.view |
| 7 | special-cases | reuse `SpecialCaseListService` query | special_cases.view |
| 8 | materials | project_materials | applications.view |
| 9 | project-power | project_power_sources | applications.view |
| 10–19 | home-power, relief-aids, aid-sources, assets, illness, losses, current-works, work-experiences, courses, goals | person sub-table row → family provider's project | people.view, applications.view |
| 20 | educations | family member education (beneficiary's own from person_educations) | people.view, applications.view |
| 21 | meetings | profession meeting; filters category/sub/career + meeting date | profession_meetings.view |

Use the capability names that exist in `AccountAccess`; where a name above does not exist, take the module's view capability and note it in the report.

Mode specifics:
- **applications**: columns of the desktop mode 0 (project, beneficiary and parents, spouse, career, description, specialty, category, types, fund type, request date, state, state date, state count, fund date, fund amount, donor, group, waiting months, sustainability years, birth/age at request, parish, priests, home area/address, register, national number, mobiles, family counts, military fields for men, sex, marital status, project area/location, applicant relation, form kind). No photo column. Sustainability: closed/cancelled → loan date to state date; financed/finished → loan date to today; else empty. Totals: projects count, members count (sum of people at home). Rows whose beneficiary name repeats are marked (a chip, not only colour).
- **loans**: columns of desktop mode 2. `SP = amount` (× rate when USD), `USD = amount / rate` when SP; paid = sum of paid instalments; remaining = max(0, returned amount − paid); overdue months = 0 when nothing remains, else months from the latest unpaid due date to today + 1 month, not for closed projects. Summary panel in SYP and USD: loans, repayments (paid ≥ 0, by the loan's currency, no conversion), returned (paid < 0), net, repayment loans (donor group name contains "Installment"), repayments balance. Never add different currencies together.
- **me-visits**: evaluation from the result ranges list; visitor opinions (first and last visitor mark).
- Kobo visits are not part of modes 4/5 beyond what the visits module already lists.

### Not in M4.17
Full application details export ("كل معلومات الاستمارة"), print, Word → M4.18. Photo column → M4.18.

## B. Report builder (smart-search plan phase 3)
Page «التقارير» (`ReportBuilder` Livewire) on the existing Datasets (projects, people, installments): rows and optional columns from any groupable field (lookup, enum, bool, date by year/month, city); measures count, sum, average, min, max, share %; the same smart filter bar (`HasSmartFilters`); one GROUP BY generated from `QuerySpec`, always city-scoped; money measures are never summed across currencies (currency becomes a forced grouping); result as a pivot table with totals and as a chart; a cell links to the filtered list (drill-down); saved in `saved_views` as kind `report` (no schema change; if `kind` cannot hold it, stop and report). Export Excel/CSV with `reports.export` (`finance.export` for installments), audited `reports.statistics` / `reports.exported`.
Chart: one reusable `x-hc.chart` component (bar, stacked bar, pie/doughnut, line), **Chart.js bundled through Vite** (no CDN; CSP), HC tokens, dark mode, RTL, a text table alternative for screen readers.

## C. Statistics (desktop `Statistics_Form`)
Axes: 0 project status, 1 funding type, 2 project type, 3 new vs developing (type detail, desktop order), 4 categories, 5 sub categories, 6 partnership (Individual / N partners), 7 parishes, 8 sex, 9 marital status, 10 age ranges, 11 donors, 12 fund year, 13 fund amount in USD (buckets: no rate, ≤ 0, 1–1000 … 5001–6000, > 6000), 14 sustainability (< 1, 1–2, 2–3, 3–4, 4–5, > 5 years; ongoing = financed/finished, closed = cancelled/closed).
- Two series: all projects (distinct) and funded projects. **Funded = state in force is Financed, Finished, Canceled or Closed** (desktop state ids 4, 5, 7, 8 — match by the unified state key/name, not the number). Switch: both / all / funded. Axes 11–13 have one series.
- Beneficiary axes (7–10) count project owners (desktop: parish, marital status and age count owners; sex counted every beneficiary — here owners for all four, **deliberate difference, to confirm with the owner**).
- Filters: A's filter service, plus request-year and fund-year lists. Every filter applies to every axis (the desktop skipped the request-date range on axes 11–13 — index bug, not carried over).
- Display: table (label, all, funded, share), totals, chart type selector (B's component), per-city breakdown columns when more than one city is selected, a number links to the applications search with the same filters. Export Excel/CSV (`reports.export`), audited.
- Capability: `applications.view` (as the menu entry); cities = where held.

## Verification
Scenario tests per role (administrator, data entry, financial, legal, guest, one-city account): what each sees, refusals, another city's rows never leak, export authorised and audited, counts equal direct SQL on the seeded data. Browser check on local MariaDB (`ONLY_FULL_GROUP_BY` is not caught by SQLite) in Arabic and English, 1366px and 375px. `tools/report-oracle` re-pointing to the new URLs is left to the M4 gate.
