# Architecture

## Intended flow

~~~text
Controller
    ↓
Action / Orchestrator
    ↓
Service
    ↓
Repository
    ↓
Eloquent Model
    ↓
Database
~~~

Controller: HTTP concerns only.

Action / Orchestrator: application operation and transaction boundary.

Service: complete business logic.

Repository: persistence/query mechanics, allow-lists, and cache-aware reads.

Model: Eloquent state, relations, and lifecycle hooks.

## Do / Don't

### Transactions

Don't put DB::transaction() inside repository methods.

Do let an Action/Orchestrator own the transaction and call a Service.

### Business rules

Don't put domain business rules into BaseRepository.

Do keep BaseRepository generic and business behavior in application Services/Actions.

### Request-controlled queries

Don't accept arbitrary columns, relations, sorts, or scopes.

Do use explicit repository allow-lists.

## Protected boundaries

Architecture tests verify:

- infrastructure source cannot depend on host App classes;
- repository code cannot own transaction boundaries;
- repositories cannot depend on HTTP/controller layers.

The package avoids creating interfaces/factories/DTOs merely for appearance. Existing abstractions remain only where they solve a concrete boundary problem.
