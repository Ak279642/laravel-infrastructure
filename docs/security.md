# Security

Core boundaries:

- filters, relation filters, sorts, search columns, eager relations and request scopes are allow-listed;
- relation/filter identifiers are validated and related columns are schema-checked;
- selected/aggregate repository columns reject unsafe/unknown identifiers;
- query values use Laravel parameter binding;
- file directories reject traversal/absolute/null-byte/control-character paths;
- storage audit scans explicit model-owned directories only;
- logs recursively redact credentials/secrets and cap payload size/depth;
- production API errors do not expose internal exception details;
- cache invalidation is after-commit for cache-aware Eloquent models.

Application code should never map untrusted request identifiers directly into low-level Eloquent/raw SQL APIs.


Additional transactional/file guarantees:

- validation-context write mutations are applied after commit and skipped on rollback;
- file replacement/delete cleanup is applied after commit, preventing rollback from restoring a row whose old file was already deleted;
- custom upload filenames cannot contain path separators or begin with a dot;
- storage-audit directories and referenced paths reject absolute, traversal, null-byte, control-character, and malformed path segments before any deletion scan proceeds.


## Optional HTTP security middleware

Two reusable middleware classes are provided but are not automatically placed in the global middleware stack.

`SecurityHeaders` removes common disclosure headers and adds:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `Referrer-Policy: strict-origin-when-cross-origin`

`RejectSensitivePaths` rejects common source/configuration probes, double-decoded traversal attempts, environment files, Composer/npm manifests, framework source directories, and sensitive `storage` subdirectories. API/JSON requests receive the package's 404 JSON envelope, while normal web requests use Laravel's 404 handling. Blocked probes are logged through the redacted package logger.

Register them by class or use the package aliases:

```php
Route::middleware([
    'infrastructure.reject-sensitive-paths',
    'infrastructure.security-headers',
])->group(function (): void {
    // Routes...
});
```

These middleware are opt-in because host applications may have their own proxy/CDN security headers or route-level handling.
