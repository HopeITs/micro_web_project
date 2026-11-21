# Handoff — 21 Nov 2026 (branch `rebuild`)

## Done this session
- M4.10 executive files and purchasing (spec `docs/planning/m4/m4-10-executive-purchasing-spec.md`, owner decisions 21 Nov): `ExecutiveFileService`/`PurchasingService` on the unified schema, Livewire `ExecutiveFileEditor` and `ProjectPurchases`, pages `/applications/{project}/executive-file` and `/purchases`, project page buttons and Ctrl+K actions, purchasing categories as a shared list, capability rollout through `staff:sync-export-roles` (run locally: 22 grants added, 15 removed). Full suite 162 passed. Browser-checked on ALP-1003 as legal, data entry and financial.

## Open issues
- Next-step box has no link to the finance page yet (`NextStep::for`, ACCEPTED/FINANCED steps → `finance.show` for `finance.view` holders).
- `web_notices` has no soft delete; loan, instalment and executive-file deletion removes notices permanently.
- A saved purchase row is deleted right after its confirmation, not with the grid's single save.
- Audit events are named `executive_files.*` / `purchasing.*` (plural), as the existing capability names.
- Purchasing categories list is empty in every city; staff add them under Lists.
- `staff:sync-export-roles` must be run on the real database at M5/M7 so existing holders get the M4.10 capability changes.
- `NotificationInboxService`, `LegacyStaffAdopter` still use old `pay_key` (fix with M4.16).
- SQLite tests do not catch MariaDB ONLY_FULL_GROUP_BY: browser-check every new aggregate query on the local MariaDB (`micro_unified`).
- Local test accounts `role.legal`, `role.data-entry`, `role.financial` got `role.<name>@hcsyria.org` e-mails locally (password "password").
- Another session works in the same checkout: stage only your own files. Full test runs can fail transiently while it runs tests too.

## Next
M4.12 visits (opening, M&E, closing, rehabilitation, with visitors, scheduling, results). Start with: "ابدأ M4.12".
