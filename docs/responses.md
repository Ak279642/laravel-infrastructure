# API responses

Use `MessageResponse` and `ResourceResponse` for stable success envelopes, including paginator metadata.

The exception renderer only normalizes requests that explicitly expect JSON, leaving normal Laravel web rendering intact.

Correlation IDs are returned on API responses for request tracing.
