# Slugs

Models can configure a legacy single slug with `slugOptions()` or multiple independent fields with `slugFields()`.

Each slug can define source columns, separator, uniqueness, update regeneration and scoped uniqueness.

Manual non-empty slugs are preserved. Use a database unique index for final concurrency protection where uniqueness is required.
