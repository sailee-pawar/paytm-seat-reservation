import os
import sys
import time
import uuid
import requests
from concurrent.futures import ThreadPoolExecutor, as_completed


BASE_URL = os.getenv("BASE_URL", "http://localhost:8000/api")
ADMIN_TOKEN = os.getenv("ADMIN_TOKEN")

TOTAL_REQUESTS = int(os.getenv("TOTAL_REQUESTS", "20000"))
WORKERS = int(os.getenv("WORKERS", "200"))

SEAT = os.getenv("SEAT", "A1")


def create_show():
    if not ADMIN_TOKEN:
        raise RuntimeError("ADMIN_TOKEN environment variable is required.")

    show_name = f"Burst Test {uuid.uuid4()}"

    response = requests.post(
        f"{BASE_URL}/shows",
        headers={
            "Authorization": f"Bearer {ADMIN_TOKEN}",
            "Content-Type": "application/json",
        },
        json={
            "name": show_name,
            "seats": [SEAT],
            "price_paise": 150000,
        },
        timeout=30,
    )

    response.raise_for_status()

    data = response.json()

    print(f"Created show: {data['id']}")

    return data["id"]


def reserve(show_id, request_number):
    user_id = f"burst-user-{request_number}"

    response = requests.post(
        f"{BASE_URL}/shows/{show_id}/reserve",
        headers={
            "Authorization": f"Bearer {user_id}",
            "Content-Type": "application/json",
        },
        json={
            "seats": [SEAT],
            "idempotency_key": f"burst-{request_number}-{uuid.uuid4()}",
        },
        timeout=30,
    )

    return response.status_code


def get_show(show_id):
    response = requests.get(
        f"{BASE_URL}/shows/{show_id}",
        timeout=30,
    )

    response.raise_for_status()

    return response.json()


def main():
    print("======================================")
    print(" Seat Reservation 20K Burst Test")
    print("======================================")
    print(f"Base URL      : {BASE_URL}")
    print(f"Requests      : {TOTAL_REQUESTS}")
    print(f"Workers       : {WORKERS}")
    print(f"Target seat   : {SEAT}")
    print()

    show_id = create_show()

    print()
    print("Starting concurrent reservation requests...")
    print()

    start = time.perf_counter()

    results = {
        201: 0,
        409: 0,
        500: 0,
        "other": 0,
    }

    with ThreadPoolExecutor(max_workers=WORKERS) as executor:
        futures = [
            executor.submit(reserve, show_id, i)
            for i in range(TOTAL_REQUESTS)
        ]

        for future in as_completed(futures):
            try:
                status = future.result()

                if status in results:
                    results[status] += 1
                else:
                    results["other"] += 1

            except Exception as exc:
                print(f"Request error: {exc}")
                results["other"] += 1

    elapsed = time.perf_counter() - start

    print()
    print("======================================")
    print(" Reservation Results")
    print("======================================")
    print(f"201 success : {results[201]}")
    print(f"409 declined: {results[409]}")
    print(f"500 errors  : {results[500]}")
    print(f"Other errors: {results['other']}")
    print(f"Elapsed     : {elapsed:.2f}s")
    print(
        f"Throughput  : "
        f"{TOTAL_REQUESTS / elapsed:.2f} requests/sec"
    )

    print()
    print("Checking final seat state...")

    show = get_show(show_id)

    counts = show["seat_counts"]

    print()
    print("======================================")
    print(" Final Reconciliation")
    print("======================================")
    print(f"Total     : {counts['total']}")
    print(f"Available : {counts['available']}")
    print(f"Held      : {counts['held']}")
    print(f"Confirmed : {counts['confirmed']}")

    total = (
        counts["available"]
        + counts["held"]
        + counts["confirmed"]
    )

    print()
    print(f"Invariant : {total} == {counts['total']}")

    if total != counts["total"]:
        print("FAIL: reconciliation invariant violated")
        sys.exit(1)

    if results[500] > 0 or results["other"] > 0:
        print("FAIL: unexpected errors detected")
        sys.exit(1)

    if results[201] != 1:
        print(
            "FAIL: hot-seat reservation should have exactly "
            "one successful reservation"
        )
        sys.exit(1)

    if counts["confirmed"] != 1:
        print("FAIL: seat was confirmed more than once")
        sys.exit(1)

    print()
    print("PASS: 20K hot-seat concurrency test passed.")


if __name__ == "__main__":
    main()