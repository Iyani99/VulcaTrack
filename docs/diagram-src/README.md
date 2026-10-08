# VulcaTrack diagram generators

Technical diagrams show useful system detail. Paper diagrams intentionally simplify
the presentation for the research paper; they do not change application behavior.
Each PHP generator prints an SVG, and `build.sh` renders a 3× PNG from it.

Run from the repository root with Git Bash:

```sh
bash docs/diagram-src/build.sh                 # all maintained diagrams
bash docs/diagram-src/build.sh activity-paper  # one target
```

| Target | Generator | SVG and PNG output base | Use |
|---|---|---|---|
| `erd` | `erd.php` | `docs/ERD/VulcaTrack-ERD_1` | Shared canonical eight-table ERD |
| `dfd0` | `dfd-level0.php` | `docs/flows/VulcaTrack-DFD-0-Context` | Paper context DFD |
| `dfd1` | `dfd-level1.php` | `docs/flows/VulcaTrack-DFD-1-Level1` | Technical Level 1 DFD |
| `activity-detailed` | `activity-otg-detailed.php` | `docs/flows/VulcaTrack-Activity-Diagram-OTG-Detailed` | Technical Rescue lifecycle |
| `activity-paper` | `activity-book-rescue-paper.php` | `docs/flows/VulcaTrack-Activity-Diagram-Book-Rescue-Paper` | Paper Book a Rescue |
| `sequence-detailed` | `sequence-pos-detailed.php` | `docs/flows/VulcaTrack-Sequence-Diagram-POS-Detailed` | Technical POS transaction |
| `sequence-paper` | `sequence-pos-paper.php` | `docs/flows/VulcaTrack-Sequence-Diagram-POS-Paper` | Paper Process Sale |
| `use-case-detailed` | `use-case-detailed.php` | `docs/VulcaTrack-Use-Case-Diagram-Detailed` | Technical system coverage |
| `use-case-paper` | `use-case-paper.php` | `docs/VulcaTrack-Use-Case-Diagram-Paper` | Paper Customer/Admin functions |

Edit generators, then rebuild their paired SVG and PNG. The ERD reads table,
column, and key details from `docs/ERD/schema.dbml`; it is shared by both uses.
The technical Activity and POS Sequence generators come from the detailed
pre-alignment sources and were checked against current behavior. The old Use
Case PNG contained a proposed Customer-account management function and had no
source, so the current technical version is a new reproducible generator.

Keep text readable at page size when changing a layout. The generators require
PHP (`C:\xampp\php\php.exe` on the dev machine) and Google Chrome. Override
the defaults with `PHP=... CHROME=... SCALE=...` when needed.
