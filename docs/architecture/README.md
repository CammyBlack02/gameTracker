# gameTracker architecture

Nine diagrams at four levels of abstraction. Start at level 1 and go down only
as far as you need.

These are for **re-orienting** after time away, for **seeing the shape of the
in-flight refactor**, and for **explaining the system to someone else**.

They are deliberately **not a navigation aid**. There are no function names and
no line numbers, and file paths appear only where the path is itself the
architectural fact. If you are looking for which file to edit, read the code.

| Level | Diagram | File |
|---|---|---|
| 1 — from outside | 1. System context | [1-outside.md](1-outside.md) |
| | 2. User journey | [1-outside.md](1-outside.md) |
| 2 — the two generations | 3. v1 vs v2 | [2-apis.md](2-apis.md) |
| | 4. Data model | [2-apis.md](2-apis.md) |
| 3 — the refactor in flight | 5. Convergence map | [3-refactor.md](3-refactor.md) |
| | 6. Target end-state *(aspirational)* | [3-refactor.md](3-refactor.md) |
| 4 — subsystems | 7. Write path: journal, tombstones, undo | [4-subsystems.md](4-subsystems.md) |
| | 8. iOS delta sync | [4-subsystems.md](4-subsystems.md) |
| | 9. Image storage pipeline | [4-subsystems.md](4-subsystems.md) |

## Keeping these honest

Every diagram carries a **"Goes stale when:"** line naming the change that
invalidates it. `tests/docs/test_architecture_docs.sh` enforces that the line
exists, that every mermaid block declares a parsable diagram type, and that
every repo path named here still exists — that last one is what actually catches
drift.

**Diagram 5 moves most.** It maps which code paths have converged onto
`src/Services`, so any PR that moves a path on or off the services should update
it in the same PR.

Row counts and similar figures are dated snapshots ("as of 2026-08-04"), not
standing facts, and are allowed to drift.
