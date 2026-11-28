# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Staff of Hope Center's (Christian Hope Center, HC) micro-projects programme in five Syrian cities: Aleppo, Damascus, Homs, Latakia and Al Wadi. There are eight roles:

- administrator
- data entry
- financial
- legal
- monitoring
- media
- communication
- guest

Each account holds its own capabilities per city. Staff work mostly in Arabic and sometimes in English, on office desktops and on phones in the field.

## Product Purpose

Replace the desktop "Micro Projects" application with one web site. It follows a small-business project from application to closing:

1. application and family data;
2. checklist and assessment;
3. decision;
4. loan and instalments;
5. purchasing and official papers;
6. field visits (including Kobo forms);
7. special cases, profession meetings and reports.

Success means staff can do every desktop task on the web with the same numbers, which are reconciled against the desktop. Managers must be able to answer their questions from the data themselves.

## Operating Context

- Projects carry the number `CITY-####`.
- A project has one beneficiary or several: the owner plus partners, through `project_applicants`.
- Families are kept as households with members.
- Printed official papers follow the city letterhead.
- Data arrives by transfer from the five desktop databases. The online staging site (mp.hcsyria-apps.org) holds synthetic data only.

## Capabilities and Constraints

- The stack is Laravel, Livewire 4 and Tailwind 4, with Chart.js bundled (no CDN).
- The schema is approved by version. Any change needs a new owner approval.
- Permissions are checked per city for every page, list, count and export. Exports go through single-use tokens and are audited.
- The order of the application form, checklist and visit questions follows the printed papers and is not changed.
- Counting units (owner decision, 28 Nov 2026):
  - projects;
  - beneficiaries (distinct owners and partners);
  - people reached (beneficiaries plus their family members).

## Brand Commitments

- One brand red, `#BE1F24`.
- The Somar Sans typeface.
- Arabic RTL first, with English LTR.
- Light and dark themes.
- Official HC and Micro Projects logos.
- Look chosen 28 Nov 2026: "Soft & airy". A light canvas, white surfaces with soft shadows and large radii, and red as an accent only.

## Evidence on Hand

- Desktop source at `D:\MyProjects\MicroProject newTest` (read-only).
- Rehearsal transfer `micro_m5_run3`: 10,543 projects and 10,610 beneficiaries.
- The owner's 242 official paper files.

The program has no public users and no marketing claims.

## Product Principles

- The same number everywhere. Every count names its unit and can explain how it was calculated.
- A city's data is never shown, counted or exported outside the account's permissions.
- One way to do each thing: no duplicate screens.
- Desktop parity first. Changes in behaviour are owner decisions recorded in the task board.

## Accessibility & Inclusion

- RTL and LTR from the same rules.
- Usable at 375px.
- Keyboard and screen-reader access, with charts also available as hidden tables.
- Reduced motion respected.
