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
