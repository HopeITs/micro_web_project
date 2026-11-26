---
name: micro-data-migration
description: Runbook for moving the Micro Projects desktop databases (ALP, DAM, HMS, LAT, WAD) into the unified web schema - rehearsals and the final cut-over. Use for any task that restores, imports, reconciles, audits or accepts migrated Micro Projects data.
---

# Micro Projects data migration

Rehearsal record and lessons: `docs/delivery/m5-rehearsal.md`. Plan: `docs/planning/rebuild-plan-2026-11.md` (M5, M7).

## Rules (never break)
1. Sources are read only. Restore each backup into its own `m5src_<city>` (or a new prefix per run); the importer reads it with the SELECT-only account.
2. A dump names its own database (`CREATE DATABASE`/`USE hcsyria_micro*`). Redirect both before loading, restore with `--one-database`, and check afterwards that no other local database changed.
3. Target is a new database per run, its own checkout or worktree, its own `storage/`, its own session cookie and port. Never the development database.
4. Mail off (`MAIL_MAILER=log`), no cloud services that receive personal data, nothing uploaded to any server.
5. No business rows, names, numbers, dumps, credentials or attachments in git, reports, chat or tickets. Reports carry counts and ids only.
6. One writer (the coordinator). Auditors read and report. Subagents never commit or push.
7. The checker must not share code or assumptions with the importer.

## Order
1. Record each archive: file name, size, SHA-256, dump date, latest activity date per city. Ask when a city shows no recent activity.
2. Restore; compare table and row counts with the dump and schema with the approved source.
3. New target: `php artisan migrate --force` on the approved schema version (`database/schema/approved.php`).
4. `php artisan unified:transfer <CITY> --source-db=<db> --archive=<zip> --report=<json>` in the order LAT, WAD, DAM, HMS, ALP.
5. Independent reconciliation (field by field, money per city and currency, references, lists, attachments).
6. `php artisan report:oracle` against the desktop SQL.
7. Role walk-through in the browser at full volume; then staff testing with the review list.

## Stop conditions
- Any unexplained difference, a failed city, a restore that touched another database, or a real e-mail leaving the machine.
- A transfer rule or schema change is needed: ask the owner first.

## Known traps
- Lists merge only when name, parents and every attribute match. "Name equal" is not "value equal".
- A column that looks like a list id may hold something else: `person_checklistmembers.Opinion` is the list's `Index`, `kobo_sync_conflict.Visit_ID` is an M&E visit. Read what the desktop code writes.
- 0 is often a real answer; "not answered" is -1 in visit answers.
- Run `tools/migration-check` after every transfer, and update it with every rule change.
- Before cut-over ask each city for its latest activity date; the 25 November Aleppo backup held nothing after 20 November.
- "0 unexplained" proves only what the checker compares. For every new column or rule, add the comparison that would have caught its loss.
- A city import is not repeatable on the same target: after a failure rebuild the target database.
- Imported accounts arrive disabled and without e-mail.
- Desktop FTP attachments are only names; files must be copied separately (M5.4).
- Checklists and M&E visits are linked to a project by inference (`project_chosen`): staff must review those cases.
