# database/

**No schema is built yet.** Table creation is a later, approved phase.

The database-design source of truth lives in the project design repo:

- `C:\IPT102\docs\ERD\schema.dbml` -- maintainable text schema
- `C:\IPT102\docs\VulcaTrack-Database-Notes_1.md` -- field-by-field rationale
- `C:\IPT102\docs\decisions\project-decisions.md` -- authoritative decisions

Phase 1 created an **empty** `vulcatrack` database (`utf8mb4` / `utf8mb4_unicode_ci`)
only to verify the PHP -> MySQL connection via `health.php`.

When the schema phase is approved, migration / seed SQL will be added here.
