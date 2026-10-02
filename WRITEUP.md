# Seat Reservation at Scale — Engineering Write-up

## 1. Overview

This project implements a concurrency-safe seat reservation service using Laravel, PostgreSQL and Docker.

The main goal is correctness under concurrent requests, particularly preventing double-booking when many users attempt to reserve the same seat at the same time.

The implementation supports:

* Atomic seat reservation
* Per-user reservation limits
* Idempotent reservation requests
* All-or-nothing multi-seat reservations
* Reservation cancellation
* Health and readiness endpoints
* Prometheus-style metrics
* Structured request/correlation logging
* Docker-based local deployment
* Concurrent burst testing

---

## 2. Technology Stack

* PHP 8.5
* Laravel 12
* PostgreSQL 17
* Docker / Docker Compose
* Python for concurrency testing
* REST/JSON APIs

---

## 3. Atomic Reservation Mechanism

The reservation operation is executed inside a PostgreSQL database transaction.

The following locking sequence is used:

1. Create the `(show_id, user_id)` limit row if it does not already exist.
2. Lock the show-user limit row using `SELECT ... FOR UPDATE`.
3. Check the idempotency key.
4. Validate the user's remaining reservation capacity.
5. Lock all requested seats using `SELECT ... FOR UPDATE`.
6. Lock seats in deterministic seat-number order.
7. Verify that every requested seat exists and is available.
8. Create the reservation.
9. Mark the seats as confirmed.
10. Create the reservation-seat records.
11. Increment the user's reserved-seat count.
12. Commit the transaction.

Because the requested seat rows are locked before changing their state, concurrent transactions cannot both successfully reserve the same seat.

The database unique constraints provide additional protection against duplicate data.

---

## 4. Concurrency and Deadlock Strategy

The main concurrency risk is multiple transactions attempting to reserve overlapping seats.

To reduce deadlock risk, requested seats are always locked in deterministic order:

```php
->orderBy('seat_number')
->lockForUpdate()
```

The reservation flow also uses a consistent lock order:

```text
Show/User limit
       ↓
Requested seats
       ↓
Reservation records
       ↓
Seat status updates
```

Cancellation follows a deterministic locking strategy as well.

The transaction is kept focused on database decision-making so that locks are not held while performing unnecessary external work.

---

## 5. Hot-Seat Protection

A hot-seat scenario occurs when many users simultaneously attempt to reserve the same seat.

For example:

```text
User 1 ─┐
User 2 ─┤
User 3 ─┤
User 4 ─┤──> Seat A1
...     ┤
User N ─┘
```

All requests attempt to lock the same `show_seats` row.

Only the transaction that successfully observes the seat as available can confirm it.

Subsequent transactions observe the updated status and receive a `409 Conflict`.

This prevents double-selling without relying on application-memory locks.

---

## 6. Per-User Reservation Limit

The default per-user limit is four seats.

A dedicated `show_user_limits` table maintains the number of currently reserved seats for each user and show.

The row is locked before checking and updating the count.

Example:

```text
Current reserved seats = 3
Requested seats        = 2
Limit                  = 4

3 + 2 > 4
```

The request is therefore rejected.

This check is performed inside the same database transaction as the reservation.

---

## 7. All-or-Nothing Reservation

Multi-seat reservations use an all-or-nothing policy.

If a user requests:

```json
{
    "seats": ["A1", "A2", "A3"]
}
```

and `A2` is already unavailable, the complete request is rejected.

The service does not reserve `A1` and `A3` partially.

This keeps the reservation state predictable and prevents partially completed booking requests.

---

## 8. Idempotency

Reservation requests require an idempotency key.

The database maintains a unique constraint on:

```text
(show_id, user_id, idempotency_key)
```

The request body is also hashed.

Therefore:

### Same key + same request

The original reservation is returned.

```text
Request 1 → Reservation #101
Request 2 → Reservation #101
Request 3 → Reservation #101
```

No additional reservation is created.

### Same key + different request

The request is rejected with `409 Conflict`.

This prevents accidental reuse of an idempotency key for a different reservation.

---

## 9. Cancellation

The implementation uses cancellation rather than timed reservation holds.

When a confirmed reservation is cancelled:

1. The reservation is locked.
2. Ownership is verified.
3. The user's limit row is locked.
4. Associated seats are locked.
5. Confirmed seats are changed back to `available`.
6. The user's reserved-seat count is decremented.
7. The reservation status becomes `cancelled`.
8. The transaction commits.

The seats can subsequently be reserved by another user.

---

## 10. Consistency vs Availability

For seat reservations, correctness is more important than accepting a request that could result in an incorrect seat state.

The service therefore favors strong consistency for the reservation decision.

The critical state is stored in PostgreSQL, and the reservation decision is made atomically inside a database transaction.

The system may reject a request with `409 Conflict` when another transaction has already reserved the requested seat.

This is preferable to allowing two users to believe they successfully purchased the same seat.

---

## 11. Database Consistency Invariant

For every show:

```text
available + held + confirmed = total
```

The `GET /shows/{id}` endpoint exposes these counts.

The concurrency tests verify that this invariant remains true after the reservation burst.

---

## 12. Observability

The service includes:

### Request IDs

Every request receives an `X-Request-ID`.

If the client does not provide one, the application generates a UUID.

The request ID is included in application logging context and returned in the response.

### Structured logs

Reservation events include fields such as:

```text
request_id
reservation_id
show_id
user_id
seats
amount_paise
```

Declined reservations record a reason such as:

```text
seat_taken
per_user_limit
idempotency_conflict
seat_not_found
```

Authorization tokens are not logged.

### Health endpoints

Liveness:

```text
GET /api/health/live
```

Readiness:

```text
GET /api/health/ready
```

The readiness endpoint verifies database connectivity.

### Metrics

The service exposes Prometheus-style metrics through:

```text
GET /api/metrics
```

Current metrics include:

* Confirmed reservations
* Declined reservations by reason
* Available seats
* Held seats
* Confirmed seats

---

## 13. Concurrency Testing

A Python burst-testing script was created at:

```text
scripts/burst_test.py
```

The script creates a fresh show containing a single seat and sends concurrent reservation requests from different users for that same seat.

### Local test

A 1,000-request hot-seat test produced:

```text
201 success : 1
409 declined: 999
500 errors  : 0
Other errors: 0
```

Final state:

```text
Total     : 1
Available : 0
Held      : 0
Confirmed : 1
```

The reconciliation invariant passed:

```text
available + held + confirmed = total
```

A 20,000-request burst test was also executed against the local Docker service.

The purpose of the test is to verify that the hot-seat remains single-owner under a high-volume concurrent request burst and that unexpected server errors do not occur.

The exact timing and throughput depend on the local machine and Docker environment.

---

## 14. Docker

The application can be started using Docker Compose.

The setup contains:

```text
Laravel Application
       |
       v
PostgreSQL 17
```

The application exposes port `8000`.

Example:

```bash
docker compose up -d --build
```

Health check:

```bash
curl http://localhost:8000/api/health/live
```

---

## 15. Security Considerations

The service uses separate authorization mechanisms for:

* Admin show creation
* User reservation/cancellation

The admin token is supplied through an environment variable rather than committed to source control.

Environment files containing secrets are excluded from Git.

Authorization tokens are not written to application logs.

---

## 16. AI Usage

AI assistance was used during development for:

* Reviewing the reservation architecture
* Discussing database locking and concurrency strategies
* Reviewing idempotency approaches
* Generating and refining test scenarios
* Debugging Docker and Laravel configuration
* Improving API documentation
* Reviewing edge cases around cancellation and concurrent requests

The final implementation, configuration, testing and validation were executed against the actual local application and PostgreSQL database.

---

## 17. Production Improvements

For a production deployment, the following improvements would be considered:

1. Use a production PHP application server instead of Laravel's development server.
2. Add connection pooling and tune PostgreSQL connections.
3. Add distributed rate limiting for abusive traffic.
4. Add authentication through a production identity provider or signed tokens.
5. Add centralized logs and dashboards.
6. Add database backups and point-in-time recovery.
7. Add automated integration and load tests in CI/CD.
8. Add application-level latency and database transaction metrics.
9. Use managed PostgreSQL for production.
10. Deploy multiple application instances behind a load balancer.
11. Add retry handling for transient infrastructure failures.
12. Add alerts for reservation failures, database saturation and elevated latency.

---

## 18. Final Design Summary

The core correctness property of the system is enforced by the database rather than by application-memory synchronization.

The important sequence is:

```text
Request
   |
   v
Validate input
   |
   v
Begin DB transaction
   |
   v
Lock user/show limit
   |
   v
Check idempotency
   |
   v
Lock requested seats in deterministic order
   |
   v
Verify availability
   |
   v
Create reservation
   |
   v
Confirm seats
   |
   v
Update user limit
   |
   v
Commit
```

This design ensures that concurrent requests cannot both successfully reserve the same seat.

The implementation was tested with concurrent hot-seat requests and verified using the final seat-state reconciliation invariant.
