# Architecture

Recommended flow:

```text
Controller -> Action -> Service -> Repository -> Eloquent Model
```

- Controllers own HTTP concerns.
- Actions orchestrate use cases and transaction boundaries.
- Services own business rules.
- Repositories own querying, persistence, cache-aware reads, validation reuse and query allow-lists.
- Models own Eloquent state/relations and may opt into cache, slug and file concerns.

The package is modular: consumers can use individual components without adopting the entire stack.
