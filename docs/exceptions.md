# Exceptions

Package exceptions map common application failures to predictable JSON status/error envelopes.

Framework validation, authentication, authorization, not-found, method-not-allowed and rate-limit exceptions remain compatible.

Production 500 responses use a generic message and never expose raw SQL, stack traces or internal exception details.
