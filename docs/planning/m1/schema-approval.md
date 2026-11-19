# M1 unified schema — approval record

- **Approved by the user:** 19 November 2026, in reply to the review page, with the word "موافق".
- **What was approved:** `unified-schema-v1` as presented in [m1-schema-review.html](m1-schema-review.html):
  - 137 tables and 1,398 columns.
  - The project-number rules: the original number is kept and is unique per city; the next number is suggested and can be edited; the check and reservation happen under a lock; numbers are shown as `CITY-####`.
  - Lookups merged by name, with `lookup_legacy_map` linking each city's old ids to the new values.
  - The transfer rules (M1.6) and the list of source tables that are not imported.
- **Approved SHA-256:** `89d34b148216158425ec3d769ff31c297062ab4807c0b7c14b14d5d94edd3688`.
  - It covers [unified-schema-v1.json](unified-schema-v1.json), built from [unified-schema-v1.php](unified-schema-v1.php) by [build-schema.php](build-schema.php).
- **What this allows:** M2.1 may write the migrations for exactly this schema without asking again.
- **What needs a new approval:** any change to tables, columns, keys or transfer rules. The changed version gets a new SHA and its own approval.
- **Not covered by this approval:** production activation, hosting, and importing the real data into a shared environment.
- **Open item:** the complete Aleppo backup. The 19 November Aleppo file has only 95 tables, so Aleppo's design comes from its 22 October backup. A complete backup will be checked against this schema before M5.
