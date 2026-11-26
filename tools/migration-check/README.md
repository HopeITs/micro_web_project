# Independent migration check

Compares the restored desktop databases with a migrated database, row by row and cell by cell. It was written during the M5 rehearsal (25 November 2026) by an auditor that was not allowed to read the transfer code, and it shares none of it. Run it after every transfer, in addition to `unified:transfer`'s own reconciliation and `report:oracle`. It reads only (read-only sessions) and prints counts and record ids.

```powershell
$env:MIG_TARGET = 'micro_m5_run3'
```

```powershell
Get-ChildItem tools/migration-check/[0-9]*.php | ForEach-Object { "=== $($_.Name)"; php $_.FullName }
```

Settings: `MIG_TARGET` (required), `MIG_SOURCE_PREFIX` (default `m5src_`), `MIG_DB_USER` / `MIG_DB_PASSWORD` (a user that may only read the sources and the target).

| Script | Checks |
|---|---|
| 10_generic | every city table with one source table: rows and cells |
| 20_lookups | every list value: name, parents, attributes, English name |
| 30_visits | visit, rehabilitation and office answers |
| 40_attachments | attachment rows, paths and stored files |
| 50_notices | notices and their links |
| 60_kobo_staff | Kobo forms, staff users, accounts, roles and capabilities |
| 70_links | project links derived for checklists and M&E visits |
| 80_latakia | the Latakia-only applicant fields and excluded tables |
| 90_cross | foreign keys, cross-city references, project numbers |
| 91_money | amounts per city, currency, project and loan |

## Reading the output

These are differences that follow an approved rule; anything else must be explained or fixed.

- Empty text or spaces stored as empty; text trimmed; `-1` ("not answered") stored as empty.
- A row without its owner in the source is not transferred (`required_missing`); recorded exceptions are printed as `explained`.
- Money and loan rate/instalment count rounded to two decimals (owner, 24 November 2026).
- Derived columns (`checklists.project_id`, `me_visits.project_id`), re-pointed Kobo local records, and the placeholder of deleted visitors.
- Desktop FTP images are names only until M5.4 copies the files.

When a transfer rule or the schema changes, update the matching script: a check that still expects the old rule reports differences, and one that ignores a new column proves nothing about it.
