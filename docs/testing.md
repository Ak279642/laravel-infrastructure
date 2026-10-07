# Testing and quality gates

Run:

```bash
composer validate --strict
composer test
composer lint
composer analyse
```

CI tests declared PHP/Laravel combinations and has a dedicated quality job for Composer validation, PHPUnit, Pint and Larastan.

Security/correctness regression coverage includes query allow-lists, cache invalidation/locking, validation context, storage paths, responses/exceptions, logging redaction, slugs/files and provider lifetimes.
