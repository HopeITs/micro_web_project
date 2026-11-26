# M4 gate (b): acceptance pre-run per role — 26 November 2026

**What this is.** A pre-run of the staff guide ([m4-gate-acceptance.html](m4-gate-acceptance.html)) done by the assistant, signed in as each of the eight role test accounts, on the **local rehearsal site** (`http://localhost:8032`, branch `m5-rehearsal`, database `micro_m5_run3`, the transferred real data of 25 November).

**What this is not.** It is not the staff's signed acceptance, and it is not the online check the delivery rule requires on `mp.hcsyria-apps.org`. Nothing was done on the online site. The M4 gate (b) stays open.

**How.** Pages were opened and forms were filled and saved through the browser session of each role (the browser pane was hidden, so fields and buttons were driven by script in the page, not by mouse). No page with personal records was captured. Every test record carries `ZZ` in its name and was removed or undone through the site where the site allows it.

## Result in one table

| Role | Menu = guide | Open / refuse (61 pages) | Write steps |
|---|---|---|---|
| Administrator | yes (+ design gallery, F4) | as the guide | 6 of 7 passed, 1 passed as media |
| Data entry | yes | as the guide | 9 passed, 2 partly, 1 not run |
| Financial | yes | as the guide | 5 passed, 1 not run |
| Legal | yes | as the guide | 5 passed, 2 covered by other roles |
| Media | yes | as the guide | 2 passed, 2 blocked (F7) |
| Guest | yes | as the guide | 3 passed (read only), F3 |
| Monitoring | yes | as the guide | 3 passed, 1 not completed, 1 covered |
| Communication | yes | as the guide | 2 passed, 1 partly (F1, no tasks assigned) |

The open/refuse matrix (61 pages × 8 roles, including the project's 26 sub-pages and papers) equals the appendix of the guide exactly. No page answered 5xx, none took longer than 2.5 s, and `storage/logs/laravel.log` got no new line during the run.

## Common steps (as administrator)

| # | Step | Result |
|---|---|---|
| م1 | Wrong password | pass: refused with "الايميل أو كلمة المرور غير صحيحة." |
| م2 | Sign in | pass for all eight accounts |
| م3 | Ctrl+K, type a project number | **fails as written** (F2): the project is listed, but under nine action shortcuts, and Enter opens the executive file |
| م4 | Language switch | pass: `/en/...`, direction ltr, no untranslated keys seen |
| م5 | Phone width 375 px | pass: no horizontal scroll, menu opens (24 links) |
| م6 | Print the application | pass: five sheets |
| م7 | Sign out, then Back | pass: sign-in page, no data |

## Write steps per role

**Administrator**
1. New account with one role in one city — pass: `zz.acceptance`, guest in Homs only (one grant row), one-time password shown once; then disabled. Signing in as that account was not tried.
2. Change state — pass: DAM-2370 Waiting → Delayed, only the allowed next states offered; "undo the last change" returned it to Waiting.
3. Lists: add, hide — pass: value added to purchase categories, appeared in the purchases form, hidden, shown again. Delete answered "used in records, hidden instead" because a removed purchase row had used it.
4. City paper settings — page opens; the save was run as media (below).
5. Send a notice — pass: arrived in the data-entry account's notifications.
6. Audit log — pass: every action above listed with account, time, record and "n fields changed".
7. Visitor statistics and export — pass: Excel and CSV download.

**Data entry**
1. Preliminary application for a new beneficiary — pass: number 2371 suggested for Damascus, saved as DAM-2371.
2. Existing national number — pass: warning with "use this person" before saving.
3. Convert to full — partly: "check completeness" lists the gaps; the full form was not filled.
4. Agriculture annex — not run.
5. Checklist save and print — pass (saved, printed, deleted).
6. Attachment — pass: uploaded, opened (200), deleted (404 afterwards).
7. Opening visit — pass on DAM-2350: plate number required, saved with one visitor, shown in the project's visits, deleted. A Kobo-written opening visit (DAM-2356) is read only, as intended. Its appearance in "visit results" was not checked (that page shows answer counts).
8. Special case — pass: saved, listed in the city list, deleted.
9. Meeting draft → final — partly: draft saved; final refused until the counts equal the attendance; a draft's print answers 409; deleted. A complete final meeting and its print were not done.
10. Purchases — pass on DAM-2356: one row saved, then removed.
11. Purchase papers — pass: four papers, one sheet each; the receipt pledge shows the purchase. Saving a return in "purchase papers data" was not run.
12. Search and export — pass with a note (C1): export audited with 1,057 rows for a filter the database counts as 1,056.

**Financial**
1. Loan on an Accepted project — pass on DAM-2339: loan saved, 12 monthly rows generated and saved.
2. Mark an instalment paid — pass: paid 1, remaining 11.
3. Overdue filter and export — pass: tile 1,895 = export 1,895 = database 1,895.
4. Promissory note, clearance, card — pass: amount in figures and in words; the card shows the 12 due dates and amounts.
5. Disbursement/contract data, save and print — not run (the page opens with Save and Save-and-print).
6. Executive file — pass: read only, no controls.

Afterwards the administrator un-marked the instalment and deleted the loan; the project is Accepted again.

**Legal**
1. Executive file — pass: created (legal accounts notified), then deleted with its notices. The guide says "add a procedure"; the file has no procedures, only its own fields — the guide's wording should change.
2. Purchases — opens with save rights; the save itself was run as data entry.
3. Purchase papers — open (run as data entry).
4. Finance — pass: read only, no controls.
5. Disbursement/contract data — pass: no save.
6. Legal attachment — pass: only the four legal categories offered; uploaded, downloaded, deleted.
7. Payment schedule — pass: no export button.

**Media**
1–2. Download a blank paper; add/replace/restore a file — **blocked (F7)**: the library says the paper files are not installed on this site.
3. City phone — pass: the mobile saved in the Damascus settings printed in a paper's footer; restored afterwards. The second phone is not part of the footer.
4. Project — pass: read only.

**Guest** — projects, people, search and statistics open with no add/edit/export controls; no task board or notifications. See F3.

**Monitoring**
1. M&E visit — pass on DAM-2350: net profit, one mark per visitor and no repeated visitor are enforced; saved. Monitoring has no delete; the administrator deleted it.
2. Kobo form on the web — not completed: the form opens, show-if/required/constraint messages work, a synced visit is read only; the scripted fill did not get past "choose from the available options", so no visit was saved. Needs a manual run.
3. Special cases — pass: read only.
4. Meeting — list, form and report open; the draft save was run as data entry.
5. People or finance — pass: refused (403).

**Communication**
1. Projects and task board — partly: both open; the test account has no task responsibilities, so "my tasks" is empty.
2. Search and statistics — pass.
3. No export anywhere — **fails on one page (F1)**.

## Findings

| # | Finding | Weight |
|---|---|---|
| F1 | Communication sees Excel/CSV on Notifications and `/notifications/export` answers 200; the guide says this role has no export at all. Either the guide or the page is wrong. | decision |
| F2 | Ctrl+K: typing a project number lists the action shortcuts (executive file, purchases, special cases) above the project, and Enter opens the executive file. | usability |
| F3 | One item of the private rehearsal security list was confirmed on the guest role's project page. Details are kept with that list, outside the repository. | security, before staff testing |
| F4 | "مكونات التصميم" (design gallery) is in every role's menu and opens for all roles. | cleanup before hosting |
| F5 | This site is `m5-rehearsal`: it lacks the UX restructure and the later commits of `rebuild` (`/families` is 404). Staff testing on real data would not see the current screens until `rebuild` is merged in. | planning |
| F6 | On a project still in "On Hold", the special-cases page shows the alert "لم يتم إدخال مبلغ القرض لهذا المشروع". | minor |
| F7 | Official paper files are not imported on the rehearsal site (`papers:import` not run), so the library cannot be tested here. | setup |
| F8 | The finance page shows the remaining instalment count as "12.0". | cosmetic |
| C1 | To check: search export audited 1,057 rows where the database counts 1,056 projects in that state (header row counted, or a real difference). | check |
| C2 | To check: type-to-search lists in the Arabic application form show English names for some list values. | check |
| C3 | To check: the audit row of a sent notice shows the form's default city (Latakia) although the recipient is not tied to it. | check |

Resolved during the run: the financial role has the loan and instalment controls on an Accepted or Financed project (the earlier empty page was a project still Waiting).

## Left in the rehearsal database

The site offers no delete for these: project DAM-2371 and its test person (`ZZ`), the disabled account `zz.acceptance`, one hidden purchase category (`ZZ`), one notice to the data-entry test account, a settings row for Damascus holding the values it already showed, and the audit events of the run. Soft-deleted: one opening visit, one M&E visit, one purchase row, one executive file, one special case, one loan with 12 instalments. Sign-in details of the local test accounts are kept outside the repository.

## Not covered

Annex, full application, final meeting with print, purchase return, disbursement/contract data save, Kobo web visit save, sign-in as the new account, English and phone width for each write step, and everything on the online site.
