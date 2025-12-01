# Attendee API

Private JSON API consumed by the Delegate Hub and other admin tools. All requests target `POST /api/attendee.php` (relative to the project `public/` web root) and must include a bearer token defined by `API_BEARER_TOKEN` in `.env`.

## Authentication

- Send the shared secret via `Authorization: Bearer <token>`.
- Requests missing the header return `401`; invalid tokens return `403`.
- All payloads must be valid JSON with `Content-Type: application/json`.
- Health checks may use `GET /api/attendee.php` with the same Authorization header plus `X-WFOT-Ping: <value>`; the API replies with `{"status":"ok","message":"Attendee API reachable","ping":"<value>"}`.

```bash
curl -X POST \
  -H "Authorization: Bearer $API_BEARER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action":"getDetails","registrationId":"recXXXX"}' \
  https://example.org/api/attendee.php
```

## Request Envelope

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `action` | string | no (default `getDetails`) | One of `getDetails`, `checkIn`, `redeemItem` |
| `bookingId` | string | conditional | Airtable record ID (e.g., `recXXXX`). Required for booking-specific operations unless a linked `registrationId` is provided |
| `registrationId` | string | conditional | Airtable registration ID. Required for check-in; optional for other actions if `bookingId` present |
| `bookableItemId` | string | conditional | Needed for `redeemItem` |
| `session` | string | conditional | Session name/ID for `checkIn` |
| `user` | string | conditional | Username to attribute check-ins/redemptions |

IDs are sanitized server-side; only alphanumeric characters are accepted.

## Actions

### `getDetails`

Returns profile information for a registration plus linked booking, booked items, and prior check-ins.

**Required fields**: `registrationId` or `bookingId` (one must be supplied).

**Sample response**:

```json
{
  "ID": "GAM2026-123",
  "Title": "Dr",
  "First Name": "Jane",
  "Last Name": "Doe",
  "Organisation": "WFOT",
  "Role": "Delegate",
  "Photo": "https://v0.airtableusercontent.com/.../large.jpg",
  "About You": "Bio text…",
  "Membership Type": "Full",
  "Access Requirements": "Wheelchair access",
  "Booking": {
    "Booking ID": "recABC",
    "Status": "Confirmed",
    "Payment Method": "Stripe",
    "Items": [
      {
        "Booked Item ID": "recItem1",
        "Item": "Welcome Dinner",
        "Item Total": 120,
        "Redeemed": false
      }
    ]
  },
  "Check-Ins": [
    {
      "Session": "Opening Plenary",
      "Check In Date": "2025-11-27T09:00:00Z",
      "Check In By": "admin@example.org"
    }
  ]
}
```

Errors:
- `400` if neither ID provided.
- `404` when records referenced do not exist or lack required relationships.

### `checkIn`

Creates a check-in record linked to a registration/session pair, unless one already exists.

**Required fields**: `registrationId`, `session`, `user`.

**Responses**:
- `{"status":"ok","check_in_id":"recChk"}` on success.
- `{"status":"already_checked_in",...}` when a check-in for the same registration/session exists (payload echoes `check_in_id`, `check_in_date`, `check_in_by`).
- `400`, `404`, or `500` error objects for invalid input, missing registration, or Airtable write failures.

### `redeemItem`

Marks a booked item as redeemed. Callers may pass either `bookingId` or `registrationId` (the API resolves the linked booking when only a registration is supplied).

**Required fields**: `bookableItemId`, `user`, plus either `bookingId` or `registrationId`.

**Responses**:
- `{"status":"ok","booked_item_id":"recItem"}` after successfully flagging `Redeemed` and storing `Redeemed By`.
- `{"status":"already_redeemed","booked_item_id":"recItem","redeemed_by":"staff@example.org"}` when the item was redeemed previously.
- `404` when the booking, registration, or item cannot be found; `500` on Airtable errors.

## Error Format

Errors are returned with an HTTP error code plus a JSON body: `{"error":"Message"}`. The API never exposes Airtable internals—inspect server logs when troubleshooting.

## Implementation Reference

See `public/api/attendee.php` for the latest business rules and field mappings. Airtable table IDs used:

- `BOOKED_ITEMS_TABLE (tbluEJs6UHGhLbvJX)`
- `CHECKINS_TABLE (tbluoEBBrpvvJnWak)`
- `MEMBER_ORGS_TABLE (tbli6ExwLjMLb3Hca)`

Use the CSV snapshots in `docs/data/` for field IDs if you need to extend the endpoint.
