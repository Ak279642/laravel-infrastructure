# Transactions

`BaseAction` owns use-case transaction boundaries through the `TransactionManager` contract.

```php
return $this->transactional(fn () => $service->execute());
```

Retry attempts are configurable with `LARAVEL_INFRASTRUCTURE_TRANSACTION_ATTEMPTS`.

Cache-aware Eloquent invalidation is handled after commit, preventing rolled-back transactions from invalidating committed cache state.
