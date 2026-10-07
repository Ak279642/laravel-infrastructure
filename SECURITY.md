# Security Policy

## Supported versions

Security fixes are provided for the latest supported release line. Until the first stable release is tagged, security fixes are applied to `main`.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability.

Report security concerns privately to **ak279642@gmail.com** with:

- the affected version or commit;
- a minimal reproduction;
- impact and attack conditions;
- any suggested mitigation.

Please avoid including production credentials, personal data, access tokens, API keys, or other secrets in reports.

## Security expectations

Applications using this package remain responsible for database constraints, authorization, storage permissions, cache-store security, and validating application-specific repository allow-lists.

Direct database writes such as `DB::table(...)->update(...)` do not trigger Eloquent model events. Applications performing such writes must explicitly invalidate affected repository/model cache tags.

## Disclosure

Please allow reasonable time for investigation and a coordinated fix before public disclosure. Confirmed vulnerabilities will be documented with affected versions, remediation guidance, and a release or patch when appropriate.
