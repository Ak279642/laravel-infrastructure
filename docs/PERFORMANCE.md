# Performance benchmarks

Run the opt-in benchmark:

~~~bash
composer benchmark
~~~

It compares representative scenarios:

- direct Eloquent;
- repository with withoutCache();
- repository cache miss;
- repository cache hit;
- filtered query;
- sorting;
- pagination;
- relation-heavy query.

The benchmark prints timings but does not enforce timing thresholds in CI. Absolute numbers vary by database, cache backend, network, serialization, and model complexity, so the package does not publish misleading universal speed claims.

Use the package benchmark as a regression signal, then repeat the scenarios against a production-like MySQL/PostgreSQL plus Redis/Memcached environment.

Expected qualitative result: repository overhead should remain small relative to database work, while cache hits avoid repeated database query cost. Profile the real application before optimizing.
