# Seat Reservation at Scale

A concurrency-safe seat reservation service built with **Laravel 12, PHP 8.5, PostgreSQL, and Docker**.

The service is designed to handle concurrent reservation requests while maintaining correctness for:

* Seat ownership
* Hot-seat concurrency
* Per-user reservation limits
* Idempotent requests
* Reservation cancellation
* Database consistency
* Health checks
* Prometheus-style metrics
* Structured request logging
* Docker-based deployment

## Tech Stack

* **Backend:** PHP 8.5 / Laravel 12
* **Database:** PostgreSQL 17
* **Containerization:** Docker / Docker Compose
* **API:** REST / JSON
* **Concurrency control:** PostgreSQL row-level locking + database constraints
* **Observability:** Request IDs, structured logs, Prometheus-style metrics
* **Load testing:** Python
* **Version control:** Git / GitHub

---

## Architecture

```text
Client
  |
  | HTTP / JSON
  v
Laravel API
  |
  +--------------------+
  |                    |
  v                    v
Reservation Logic    Health / Metrics
  |
  v
PostgreSQL
  |
  +-- shows
  +-- show_seats
  +-- reservations
  +-- reservation_seats
  +-- show_user_limits
  +-- reservation_attempts
```

The reservation decision is made inside a database transaction.

For a reservation request, the application:

1. Identifies the user from the Bearer token.
2. Normalizes and sorts the requested seat numbers.
3. Locks the user's show-level reservation-limit row.
4. Checks the idempotency key.
5. Checks the user's existing reserved-seat count.
6. Locks requested seats in deterministic order.
7. Verifies that all requested seats are available.
8. Creates the reservation.
9. Marks the seats as confirmed.
10. Updates the user's reserved-seat count.
11. Commits the transaction.

This provides an all-or-nothing reservation decision.

---

# API Endpoints

Base URL:

```text
http://localhost:8000/api
```

## 1. Create a Show

Admin-only endpoint.

### Request

```http
POST /api/shows
Authorization: Bearer <ADMIN_TOKEN>
Content-Type: application/json
```

```json
{
  "name": "Rock Concert",
  "seats": ["A1", "A2", "A3", "A4", "A5"],
  "price_paise": 150000
}
```

`price_paise` is stored as an integer to avoid floating-point money calculations.

### Response

```http
201 Created
```

The response contains the show and its seats with `available` status.

---

## 2. Reserve Seats

```http
POST /api/shows/{show_id}/reserve
Authorization: Bearer <USER_TOKEN>
Content-Type: application/json
```

### Request

```json
{
  "seats": ["A1", "A2"],
  "idempotency_key": "reservation-001"
}
```

The authenticated user's identity is derived from the Bearer token.

The request body does not contain a user ID.

### Successful response

```http
201 Created
```

### Seat conflict

If another request has already confirmed one of the requested seats:

```http
409 Conflict
```

The reservation is all-or-nothing, so no partial reservation is created.

---

## 3. Get Show Status

```http
GET /api/shows/{show_id}
```

Returns:

* Show details
* Seat details
* Available seats
* Held seats
* Confirmed seats
* Seat counts

Example:

```json
{
  "seat_counts": {
    "total": 10,
    "available": 6,
    "held": 0,
    "confirmed": 4
  }
}
```

The following invariant is maintained:

```text
available + held + confirmed = total
```

---

## 4. Cancel a Reservation

```http
POST /api/reservations/{reservation_id}/cancel
Authorization: Bearer <USER_TOKEN>
```

Only the user who created the reservation can cancel it.

Cancellation:

* Changes the reservation status to `cancelled`
* Releases its confirmed seats
* Decreases the user's reserved-seat count
* Makes the seats available for future reservations

A cancelled reservation is not deleted.

---

## 5. Health Checks

### Liveness

```http
GET /api/health/live
```

Response:

```json
{
  "status": "ok"
}
```

### Readiness

```http
GET /api/health/ready
```

The readiness endpoint verifies database connectivity.

Example:

```json
{
  "status": "ready",
  "database": "connected"
}
```

If the database is unavailable, the endpoint returns HTTP `503`.

---

## 6. Metrics

```http
GET /api/metrics
```

Returns Prometheus-style metrics including:

```text
reservations_confirmed_total
reservations_declined_total{reason="seat_taken"}
reservations_declined_total{reason="per_user_limit"}
reservations_declined_total{reason="idempotency_conflict"}
seats_available
seats_held
seats_confirmed
```

---

# Authentication

Two simple authentication mechanisms are used for the take-home exercise.

### User authentication

```http
Authorization: Bearer user-123
```

The Bearer token is treated as the authenticated user identity.

For example:

```text
user-123
user-456
user-789
```

### Admin authentication

The admin endpoint requires the configured `ADMIN_TOKEN`.

The token is supplied through the environment and is not committed to the repository.

---

# Database Design

## `shows`

Stores show-level information:

* Show name
* Price in paise
* Per-user reservation limit

Default per-user limit:

```text
4 seats
```

## `show_seats`

Stores individual seats:

* Show
* Seat number
* Status
* Hold information

Possible statuses:

```text
available
held
confirmed
```

The current implementation uses explicit cancellation rather than timed holds.

## `reservations`

Stores:

* User
* Show
* Reservation status
* Amount
* Idempotency key
* Request hash

The database has a unique constraint on:

```text
show_id + user_id + idempotency_key
```

## `reservation_seats`

Maps reservations to seats.

## `show_user_limits`

Maintains the number of currently reserved seats for each user for each show.

This row is locked during reservation to make the per-user limit concurrency-safe.

## `reservation_attempts`

Stores declined reservation attempts and their reason for observability.

---

# Concurrency Control

The critical reservation decision is protected by a PostgreSQL transaction.

The application uses:

```text
DB transaction
      |
      +-- lock user/show limit row
      |
      +-- check idempotency
      |
      +-- check per-user limit
      |
      +-- lock requested seats
      |
      +-- verify availability
      |
      +-- confirm reservation
      |
      +-- update counters
      |
      +-- commit
```

Requested seats are locked in deterministic order.

For example:

```text
A1, A2, A3
```

is always locked in that order.

This reduces the possibility of deadlocks when concurrent requests contain overlapping seats.

---

# Idempotency

Each reservation request requires an `idempotency_key`.

For the same:

```text
user + show + idempotency_key
```

the system stores the original request hash and reservation.

### Same key + same request

The original reservation is returned.

No second reservation is created.

### Same key + different request

The request is rejected with:

```http
409 Conflict
```

This prevents accidental reuse of an idempotency key for a different reservation.

---

# Per-User Reservation Limit

The default limit is:

```text
4 seats per user per show
```

The `show_user_limits` row is locked using `SELECT ... FOR UPDATE`.

Therefore, concurrent requests from the same user cannot independently pass the limit check.

Example:

```text
10 concurrent requests
same user
1 seat each
limit = 4
```

Expected result:

```text
201 = 4
409 = 6
```

---

# Cancellation

The implementation uses explicit cancellation instead of timed holds.

```http
POST /api/reservations/{id}/cancel
```

The cancellation transaction locks:

1. Reservation
2. User limit row
3. Reservation seats

Seats are released only if they are currently confirmed.

The reservation itself remains stored with:

```text
status = cancelled
```

This preserves the reservation history.

---

# Docker Setup

## Requirements

* Docker
* Docker Compose

## Start the application

```bash
docker compose up --build
```

The application is available at:

```text
http://localhost:8000
```

PostgreSQL is available to the application through the Docker service name:

```text
postgres:5432
```

## Stop the application

```bash
docker compose down
```

To also remove the PostgreSQL volume:

```bash
docker compose down -v
```

---

# Environment Variables

The following values are configured through environment variables:

```text
APP_KEY
ADMIN_TOKEN
DB_CONNECTION
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD
```

Secrets should be stored in `.env` locally or as deployment environment variables.

The `.env` file is excluded from Git.

---

# Local API Example

Create a show:

```bash
curl -X POST http://localhost:8000/api/shows \
  -H "Authorization: Bearer <ADMIN_TOKEN>" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Rock Concert\",\"seats\":[\"A1\",\"A2\",\"A3\",\"A4\",\"A5\"],\"price_paise\":150000}"
```

Reserve a seat:

```bash
curl -X POST http://localhost:8000/api/shows/1/reserve \
  -H "Authorization: Bearer user-123" \
  -H "Content-Type: application/json" \
  -d "{\"seats\":[\"A1\"],\"idempotency_key\":\"reservation-001\"}"
```

Check the show:

```bash
curl http://localhost:8000/api/shows/1
```

Check readiness:

```bash
curl http://localhost:8000/api/health/ready
```

Check metrics:

```bash
curl http://localhost:8000/api/metrics
```

---

# Correctness Tests

The implementation has been tested for:

### Hot-seat concurrency

20 concurrent users attempting to reserve the same seat:

```text
201 success: 1
409 declined: 19
Other errors: 0
```

### Idempotency concurrency

20 concurrent requests using the same:

```text
user
show
idempotency key
request body
```

All requests returned the same reservation rather than creating duplicate reservations.

### Per-user concurrency

10 concurrent one-seat requests from the same user with a limit of 4:

```text
201 success: 4
409 declined: 6
Other errors: 0
```

### Reconciliation

The show endpoint verifies:

```text
available + held + confirmed = total
```

### Cancellation

Cancelled seats become available again and can be reserved by a subsequent request using a new idempotency key.

---

# Observability

Each request receives an `X-Request-ID`.

Clients can provide their own:

```http
X-Request-ID: test-123
```

Otherwise, the application generates a UUID.

The request ID is:

* Added to the response
* Included in application logging context
* Useful for tracing individual requests

Reservation declines are categorized by reason, including:

```text
seat_taken
per_user_limit
idempotency_conflict
seat_not_found
unknown
```

---

# AI Usage

AI assistance was used during development for:

* Understanding concurrency and idempotency patterns
* Reviewing database locking approaches
* Designing API and database structures
* Debugging Docker and Laravel configuration
* Improving observability and testing approaches
* Reviewing the implementation against the assignment requirements

All generated suggestions were reviewed, tested, and adapted before being included in the implementation.

---

# Future Improvements

Possible production improvements include:

* Replace the simple Bearer-token authentication with a real authentication service.
* Run Laravel behind a production-grade PHP application server instead of the development server.
* Add Redis for distributed caching and rate limiting.
* Add a dedicated metrics system such as Prometheus/Grafana.
* Add distributed tracing.
* Add automated integration and concurrency tests to CI.
* Implement timed seat holds with background expiry processing if required.
* Add database connection pooling.
* Add horizontal application scaling.
* Add rate limiting for reservation endpoints.
* Add automated deployment through CI/CD.

---

# Project Status

Core reservation functionality and concurrency correctness have been implemented and tested locally using Docker and PostgreSQL.

The project is being prepared for public deployment and high-concurrency burst testing as part of the Paytm Backend Engineering take-home exercise.
