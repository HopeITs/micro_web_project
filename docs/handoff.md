# Handoff — 21 Nov 2026 (branch `rebuild`, last commit c2461c1)

## Done this session
- M4.8 lifecycle, states, task assignment, next-step box (`app/Services/Lifecycle/*`, `ProjectLifecycle/ProjectTasks/ProjectNextStep` Livewire). Revised: state changes follow the owner's transition table (`LifecycleService::NEXT`) and the administrator can undo their own last change the same day.
- M4.9 finance on the unified schema (spec `docs/planning/m4/m4-9-finance-spec.md`): finance page per project, generated instalment schedule, payment schedule `/payments`, notices. 130 tests pass. Browser-checked on ALP-1003 (local data now has a loan + 12 instalments there).

## Open issues (see task board rows M4.8, M4.9)
- Data-entry role cannot be made responsible for study tasks (has `tasks.view`, not `tasks.manage`) — ask owner.
- Next-step box has no link to the finance page yet (`NextStep::for`, ACCEPTED/FINANCED steps → `finance.show` for `finance.view` holders).
- `web_notices` has no soft delete; loan/instalment deletion removes notices permanently.
- `NotificationInboxService`, `LegacyStaffAdopter` still use old `pay_key` (fix with M4.16).
- SQLite tests do not catch MariaDB ONLY_FULL_GROUP_BY: browser-check every new aggregate query on the local MariaDB (`micro_unified`).
- Local test accounts need an e-mail since the e-mail sign-in merge; `role.administrator` got `role.administrator@hcsyria.org` locally.
- Another session works in the same checkout (official papers, admin pages): stage only your own files.

## Next
M4.10 executive files and purchasing (depends on M4.9). Start with: "ابدأ M4.10".
