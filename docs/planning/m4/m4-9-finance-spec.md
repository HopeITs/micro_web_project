# M4.9 — Finance: donor, loan, instalments, payment schedule

Desktop reference: `Loans_Form`, `Payments_Form`, AdminPanel donor rules. Existing logic to reuse: `app/Services/Finance/{FinanceService,PaymentScheduleService,LoanMath,Money,Fields}.php` (built in P5.1 on the old `op_*` tables; see `docs/delivery/p5-1-finance.md` and `p5-1-workflow-contract.md`). Rebuild them on the unified schema (v1.3): `projects.donor_id/donor_group_id`, `donors`, `donor_groups`, `loans`, `loan_installments`, `web_notices`, `project_tasks`, `project_state_history` via `App\Services\Lifecycle\LifecycleService` (M4.8).

## Owner decisions (21 Nov 2026)

1. **Instalment schedule: generated, then editable.** A "Create the instalment schedule" action fills rows monthly from the instalment amount, the instalment count and a first due date (default: one month after the loan date); the last row takes the remainder so the rows add up to the returned amount. Every row stays editable before saving; manual add-row also exists.
2. **Deleting:** the financial user (`finance.manage`) may delete an **unpaid** instalment only. A loan may be deleted only by the administrator and only while none of its instalments is paid; the project then goes back to Accepted (through LifecycleService), and the notices the loan created are removed. Every deletion is soft (`deleted_at`) and audited with details.
3. **Notices as the desktop, now:** on the first loan generate the visit schedule notices (opening + M1… per the existing `ProjectNotices::generateVisitSchedule` rules: standard, solar, agricultural, rehabilitation) — only when the city has a monitoring account; one payment-due notice per financial account for each new unpaid instalment; instalment notices follow amount/due date and are seen when paid; clearance + trophy notices after the last instalment. Port the needed parts of `App\Services\Staff\ProjectNotices` to the unified schema (`web_notices.legacy_pay_key`/`loan_installment_id`, no `source_id`).
4. **Payment schedule page** (`/payments`, all permitted cities) opens on **unpaid instalments due by the end of this month, including overdue ones**, with count tiles (overdue, due this month, paid this month) that filter the list; year/month/city/donor-group/currency filters; count and sum per currency; link to the project's finance page.

## Rules kept from P5.1 / desktop

- Funded states (Accepted, Financed, Finished, Canceled, Closed): donor mandatory; no donor outside them. A loan needs a funded project with a donor. One loan per project.
- First loan: project → Financed (`LifecycleService::enterNamed`), loan task "تسليم كامل المبلغ و تحديد الأقساط" ticked if assigned, visit notices.
- Payments count and rate derived as `LoanMath::derived` (rounded to one decimal as the desktop).
- A paid last row with less than one and a half instalments left → Finished once, then clearance notices.
- Choosing a donor group sets the project's donor to the group's donor (desktop).
- Amounts are exact decimals now (unified `dec(15,2)`): the old float/whole-unit limits of P5.1 no longer apply.
- Currencies USD and SP; opposite-currency figure with the exchange rate.
- Version hash on every write, project row lock, retry-safe.
- Capabilities: `finance.view` read, `finance.manage` write (financial and administrator roles), both with `applications.view`; `finance.export` for Excel/CSV of the schedule (OpenSpout, as other exports). Audit events `finance.*` with v1.3 details (fields before/after).

## UI (HC layout, Livewire, AR/EN, RTL, 375px)

- Project page: "Finance" button (finance.view) and the loan section summary; the next-step box (M4.8) already says "record the loan" / "follow the instalments".
- Finance page per project: donor and group, loan form, instalment grid with paid/received date, generate-schedule action, totals (paid, remaining, count), delete actions per decision 2.
- Payment schedule page per decision 4; sidebar item "جدول الدفعات" (Navigation already lists it as a later task).

## Done when

Role scenarios pass (financial records a loan and schedule, pays instalments to Finished; data entry/guest read-only or refused; administrator deletes a loan without paid instalments; another city refused), the full suite passes, and the pages render in AR/EN and at 375px.
