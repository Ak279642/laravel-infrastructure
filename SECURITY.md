# Security Policy

## Supported versions

Security fixes are provided for the latest stable minor release in the current major series.

| Version | Supported |
| --- | --- |
| 1.x | Yes |
| < 1.0 | No |

## Reporting a vulnerability

Please do **not** open a public GitHub issue for a suspected vulnerability.

Use GitHub's private security-advisory reporting flow for this repository. Include:

- affected package version or commit;
- a minimal reproduction;
- impact and realistic attack conditions;
- any suggested mitigation, if known.

Please allow reasonable time for triage and a coordinated fix before public disclosure.

## Security expectations

The package treats these areas as security boundaries:

- repository filter, sort, relation, search-column and scope allow-lists;
- deterministic cache keys and cache invalidation;
- validation-context isolation in long-running processes;
- sensitive-data redaction in logs;
- file path and filename validation;
- transaction boundaries controlled by application actions/orchestrators.

Direct database writes, raw pivot mutations, or external storage changes bypass Eloquent lifecycle hooks. Applications using those operations must explicitly invalidate affected repository caches.

## Dependency security

CI runs `composer audit --no-dev` against the latest supported Laravel/PHP combination. Older Laravel versions remain in the compatibility matrix, but known advisories in historical framework versions do not block installing those versions solely for compatibility tests.
