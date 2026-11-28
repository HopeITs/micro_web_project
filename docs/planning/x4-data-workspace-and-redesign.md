# X4 — One data workspace and a modern redesign

Owner request, 28 November 2026: finish X4 so the whole UX change is visible. The owner named these problems:

- duplicate screens;
- patterns that differ from page to page;
- clumsy, crowded layouts with little polish;
- no place to download all application forms or all families;
- no difference between the number of projects and the number of beneficiaries (a project can have partners, so there are more beneficiaries than projects);
- old-fashioned charts.

The owner wants a modern design that is flexible and dynamic.

Owner decisions so far:

- **Look:** "Soft & airy". A light canvas, white cards with soft shadows and large radii, and brand red used as an accent only. Dark mode is kept.
- **Analytics:** merge Explore, Search, Statistics and the Report builder into **one page** without losing any idea. It has a builder, suggestions, tables and charts, every multi-filter and condition the database allows, and lets you see and export all the data and every calculation.

No schema change is planned. Everything below reads the existing tables (schema v1.6 on `rebuild`).

## 1. What exists today (evidence, 28 Nov)

| Page | Engine | What only it has |
|---|---|---|
| Explore `/explore` | `Dataset` registry (projects, people, instalments) + `QuerySpec`/`ReportSpec` | list / pivot / chart on one filter bar, 25 ready questions, ask box, drill-down |
| Search `/search` | `SearchModes` (10 modes + 12 detail types = 22) | family, educations, attachments, checklists, loans, visit dates, M&E visits, special cases, meetings, the 12 person detail tables; loan summary; "All application details" export |
| Statistics `/statistics` | `StatisticsService` (15 desktop axes) | axes not groupable in Explore (parish, subtype, category, sustainability…), "all / funded / both" switch, per-city columns, share %, request-year and fund-year lists |
| Report builder `/reports` | `ReportSpec` | rows × columns pivot, measures count/distinct/sum/avg/min/max/share |

There are two engines for the same questions, four menu items, three chart icons and two search icons in the sidebar.

Counting today: every "count" is a count of projects. The real data on the M5 rehearsal (`micro_m5_run3`) shows the gap:

- 10,543 projects with an applicant;
- **10,610 distinct beneficiaries**;
- 118 partners;
- 84 projects with 2 people and 2 projects with 3.

The synthetic data has no partners at all.

## 2. The data workspace (one page, replaces the four)

The sidebar gets one item, **«البيانات والتقارير» / "Data & reports"**. The old URLs redirect into it with the same filters, so saved links keep working.

**Layout (desktop):**

- **Top:** the source switcher and the ask box (it accepts Arabic and English questions).
- **Below the ask box:** the condition builder.
- **Body:** a result area with four views of the same result:
  1. **Summary**: KPI cards (projects · beneficiaries · families · loans per currency · instalments paid/overdue) with the share of the whole.
  2. **Table**: the rows, with a column picker, sort, a totals row and drill-down into a record.
  3. **Pivot**: rows × columns, with any measure and per-city columns.
  4. **Chart**: picked automatically from the shape of the result. You can change it.
- **Side panel** (a drawer on phones): suggestions and "How this is calculated".

**Sources:** one registry of datasets. Today's three datasets are joined by one dataset per search mode:

- families;
- family members;
- loans;
- visit dates;
- M&E visits;
- checklists;
- special cases;
- profession meetings;
- attachments;
- educations;
- the 12 person detail tables.

The 22 search types therefore become 22 sources of one engine, and Search and Explore stop being two things. Each source keeps its own capabilities per city, exactly as today.

**Conditions (the builder):**

- Every field of the source can be filtered, plus the fields of its parent. For example, a visit can be filtered by its project's state, and a family member by the head's city.
- Operators by type:
  - is / is not;
  - contains;
  - empty / not empty;
  - between, before / after, greater / less than;
  - date presets (this year, last 30 days…).
- New: **AND / OR groups**. "Matches all of" / "matches any of", one level deep.
- Multi-value pickers keep their live counts.
- The "all / funded / both" switch becomes a one-tap preset.

**Measures (calculations):**

- count of rows;
- **distinct beneficiaries** (the owner plus partners, through `project_applicants`);
- distinct families;
- sum / average / min / max of any number or money field, never added across currencies;
- share %;
- a running total.

Every number on the page has a "how is this calculated" note in plain words: the source, the filters in force, the formula, and what was left out. For example: "Beneficiaries: distinct people linked to the projects as owner or partner. 3 projects without a recorded person are not counted."

**Suggestions:**

- Every source has ready questions. The 15 statistics axes and the 25 Explore questions become ready questions of the projects source, so nothing is lost.
- Live suggestions follow from the current result. Examples:
  - "split by city";
  - "compare with last year";
  - "show the 10 largest";
  - "show beneficiaries instead of projects";
  - "open these 37 projects".

**Save and share:** saved views (the existing `saved_views` table) hold the source, conditions, view, pivot and chart. A view can be pinned to My desk, and the desk shows pinned views as live cards.

**Export, all of it:**

- "Export what I see": the current table, pivot or chart data as Excel or CSV.
- "Export everything in this source": all rows and all columns the account may read, without the cap.
- The Excel workbook has three sheets:
  1. Data;
  2. Summary / pivot;
  3. **Definitions**: the filters, formulas and date of extraction, so you can check every calculation.
- A **downloads panel** lists the full extracts:
  - **all application forms** (every form field, one row per project; the 3,000 cap is removed and the file is streamed);
  - **all families with their members** (one row per member, with the household columns);
  - all people;
  - all payments;
  - all visits.
- The existing rules apply: `reports.export` / `finance.export` per city, single-use tokens, sensitive columns marked, and every export audited.

## 3. Projects and beneficiaries everywhere

- Wherever a count appears, it names its unit: "201 projects · 214 beneficiaries".
- This applies to the projects list tiles, My desk, the data workspace, the statistics axes, the exports' summary sheet and the person-based axes (sex, age, marital status, parish).
- Person-based axes can count either the beneficiaries (owner + partners) or the project owners.
- The synthetic seeders gain partners on some projects, so the difference shows in testing and on the online staging site.

## 4. Redesign ("Soft & airy") — one system, every screen

**Tokens:**

- warmer light canvas;
- white surfaces;
- 16px card radius and 10px control radius;
- two soft shadow levels;
- a 4px spacing scale;
- one type scale (Somar Sans; tabular numbers);
- brand red only for the primary action, the current place and the focus ring;
- semantic colours (ok, warn, info, danger) for states only;
- a refreshed chart palette, checked for contrast in light and dark.

**Shell:**

- The sidebar is regrouped by job (Work · People · Money · Field · Data · Administration).
- Each item has one distinct icon.
- The sidebar can be collapsed to an icon rail on desktop.
- The header has a page title area, and the search sits in the header.

**One page pattern:** page header (title, one-line purpose, primary action), then a toolbar (filters / views / export), then content cards. The same filter bar, table, empty state, pagination and export buttons are used on every list.

**Charts:**

- rounded bars with a soft gradient;
- area fill on lines;
- doughnuts with the total in the centre;
- sparkline KPI cards;
- subtle grids and modern tooltips;
- value labels kept, plus a hidden table for screen readers;
- reduced motion respected.

**Screens, in order:** My desk, projects list, project page, people/families, payments, visits, special cases, meetings, accounts, lookups, papers, sign-in.

**Duplicates removed:**

- the four analytics pages become one;
- visit results and visitor statistics move to tabs of the Visits page;
- the nine pages still on the old `layouts.app` are either moved to the HC layout or removed when unused.

**What stays untouched:**

- the order of the application form, checklist and visit form questions (they follow the printed papers, owner decision of 26 Nov);
- the official papers' print layouts;
- permissions;
- the schema.

## 5. Delivery in three waves

Each wave is delivered the same way:

- automated tests run locally;
- the work is pushed to `rebuild`, deployed to https://mp.hcsyria-apps.org and verified there;
- the verification covers every role, Arabic and English, 375px and printing/exports;
- the task board is updated.

| Wave | Content | Done when |
|---|---|---|
| **W1 Look and counts** | tokens, shell, page pattern, chart restyle, KPI cards, beneficiaries next to projects everywhere, synthetic partners | the desk and the projects list show the new look and the three counts. The analytics pages get the new look, and get the counts in W2, when they become the workspace. |
| **W2 Data workspace** | new sources for the 22 search types, condition builder with AND/OR, measures incl. beneficiaries, calculations panel, suggestions, full exports and the downloads panel, redirects; Search / Statistics / Report builder menu items removed | every former search type, axis and report can be produced in the workspace with the same numbers (checked against `report:oracle` on the rehearsal data) |
| **W3 Every other screen** | project page, people/families, payments, visits (with results and visitor statistics as tabs), special cases, meetings, admin pages, sign-in; old `layouts.app` pages | no page left on the old look; no duplicate menu items |

## 6. Owner decisions (28 November 2026)

1. **Three figures.**
   - Projects.
   - Beneficiaries: distinct people linked to a project as owner or partner (`project_applicants`).
   - People reached: the beneficiaries plus the members of their families (`family_members` of the beneficiaries' families), with each person counted once.
2. **Order: look first.** The waves run W1 → W2 → W3 as in section 5.
3. **Sensitive columns in full extracts:** they are included for accounts allowed to see them, marked as sensitive and audited, as today.
