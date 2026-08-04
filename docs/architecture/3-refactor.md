# Level 3 — The refactor in flight

## 5. Convergence map

The same rules once existed three times over: once for the web, once for the
phone, once for the CLI. Sub-project #6b is collapsing them onto `src/Services`.

The useful thing to see here is that this is **not** three copies of everything.
It is a partially completed migration — and this diagram says how far it has got.

Verified against `origin/main` at `3379fce`, 2026-08-04.

```mermaid
flowchart LR
    services["src/Services<br/>the shared rules"]

    subgraph done["Converged"]
        d1["api/games.php<br/>list, get, platforms"]
        d2["api/v2/games<br/>list, get"]
        d3["api/v2/items<br/>list, get"]
        d4["src/Cli<br/>the gt CLI"]
    end

    subgraph todo["Still holding their own SQL"]
        t1["api/games.php<br/>create, update, delete"]
        t2["api/items.php<br/>everything"]
        t3["api/v2/sync<br/>changes, push"]
    end

    d1 --> services
    d2 --> services
    d3 --> services
    d4 --> services

    t1 -.->|"own SQL"| db[("MySQL")]
    t2 -.->|"own SQL"| db
    t3 -.->|"own SQL"| db
    services --> db

    classDef ok fill:#1b5e20,stroke:#4caf50,color:#fff
    classDef pending fill:#5d1a1a,stroke:#e57373,color:#fff
    class d1,d2,d3,d4 ok
    class t1,t2,t3 pending
```

| Path | Rules live where |
|---|---|
| `api/games.php` reads — list, get, platforms | `src/Services` |
| `api/games.php` writes — create, update, delete | own SQL |
| `api/items.php` | own SQL |
| `api/v2/games` list + get | `src/Services` |
| `api/v2/items` list + get | `src/Services` |
| `api/v2/sync` changes + push | own SQL |
| `bin/gt` via `src/Cli` | `src/` natively |

The CLI was built on the services from the start, which is why it is the most
complete of the three: filters, writes, journalling and undo all live in `src/`
and nothing else had to be taught them.

**Goes stale when:** any path moves onto or off `src/Services`. A PR that moves
one should update this diagram in the same PR.

## 6. Target end-state

> **This diagram is aspirational.** It is a reading of where the refactor is
> heading, not a description of code that exists. Diagram 5 is the truth. The gap
> between the two is the roadmap.

```mermaid
flowchart TB
    web["Browser"] --> t1
    ios["iPhone"] --> t2
    dev["You, on the box"] --> t3

    subgraph adapters["Three thin transport adapters"]
        t1["v1<br/>session cookie<br/>{ success, ... }"]
        t2["v2<br/>bearer token<br/>{ data } / { error }"]
        t3["gt CLI<br/>no auth needed<br/>table or JSON"]
    end

    t1 --> svc
    t2 --> svc
    t3 --> svc

    svc["src/Services<br/>every rule, exactly once"]
    svc --> w["src/Services/Write<br/>the only write SQL"]
    svc --> q["src/Query<br/>filters, paging"]
    w --> j["src/Journal<br/>undo"]
    w --> db[("MySQL")]
    q --> db

    classDef goal fill:none,stroke:#888,stroke-dasharray:5 5
    class adapters,t1,t2,t3,svc,w,q,j goal
```

In this shape an adapter's only jobs are to authenticate, to translate the wire
format, and to choose an HTTP status. Everything else is shared. The value is not
tidiness: it is that a rule fixed once is fixed everywhere, instead of being
fixed on the phone and left wrong on the web.

**Goes stale when:** the intended end-state changes — including if you decide
against it.
