# Filtering and query security

Normal filters must be listed in `$allowedFilters`.

Dotted relation filters must be listed in `$allowedRelationFilters` and their relation must also be listed in `$allowedRelations`. Related columns are schema-checked.

Supported operators include equality/comparison, LIKE/ILIKE, IN, NOT IN, BETWEEN, NULL and NOT NULL.

Request-driven scopes must be listed in `$allowedScopes`. Sorts, relations and search columns have their own explicit allow-lists.

Never pass raw request values as repository column/relation/scope names outside these allow-listed APIs.
