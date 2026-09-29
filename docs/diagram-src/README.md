# Paper diagrams — generators

Plain-PHP generators for the four research-paper diagrams. Each script prints an SVG;
`build.sh` writes it to the established file name and renders a 3× PNG for Word
with headless Chrome.

```
bash docs/diagram-src/build.sh        # from the repository root
```

| Generator | Output (`.svg` + `.png`) | Page |
|---|---|---|
| `erd.php` | `docs/ERD/VulcaTrack-ERD_1` | landscape |
| `activity-otg.php` | `docs/flows/VulcaTrack-Activity-Diagram-OTG` | portrait |
| `dfd-level1.php` | `docs/flows/VulcaTrack-DFD-1-Level1` | landscape |
| `sequence-pos.php` | `docs/flows/VulcaTrack-Sequence-Diagram-POS` | landscape |

- **Edit the generator, not the generated `.svg`** — a rebuild overwrites it.
- The ERD reads its tables, columns and PK / FK / UNIQUE markers from
  `docs/ERD/schema.dbml` (the schema of record), so it cannot drift from the schema;
  only table positions, connector routes and cardinalities are written in `erd.php`.
- Readability rule: each canvas is sized for the page it is printed on, so text is
  about 8.5–10 pt once the diagram is scaled to the page width (≈ 2–3× the previous
  versions). Keep that ratio when adding content — enlarge the canvas *and* the
  text, or split the diagram, rather than shrinking the font.
- Every fact on these diagrams comes from the committed code, `schema.dbml` and
  `docs/decisions/project-decisions.md`. Planned or uncommitted features are left off
  until they are committed.
- Needs PHP (`C:\xampp\php\php.exe` on the dev machine) and Google Chrome.
  Override with `PHP=... CHROME=... SCALE=...`.
