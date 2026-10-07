# Contributing

1. Create a focused branch.
2. Preserve Controller → Action → Service → Repository → Model.
3. Add a regression test for every bug fix.
4. Do not weaken default repository caching or automatic invalidation.
5. Keep request-controlled query surfaces behind explicit allow-lists.
6. Keep transactions out of repositories.
7. Run:

~~~bash
composer validate --strict
composer test
composer analyse
composer format:check
composer audit --no-dev
~~~

For cache/performance changes also run:

~~~bash
composer benchmark
~~~

Public API changes require explicit backward-compatibility review and changelog documentation.
