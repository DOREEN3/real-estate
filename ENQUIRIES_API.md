# Enquiries API

These endpoints use the existing `enquiries`, `enquiry_follow_ups`,
`property_matches`, and `inquiries` tables from
`database/real_estate (1).sql`. Buyer requirements (`enquiries`) and messages
about a specific listing (`inquiries`) are separate workflows.

All routes use the `/api/v1` prefix. Authenticated requests use a bearer access
token. Responses use the common `{ "success": true, "message": "...", ... }`
envelope. List responses also include `pagination`.

## Buyer requirements

| Method | Path | Access | Purpose |
|---|---|---|---|
| POST | `/enquiries` | Public, rate-limited | Create a buyer lead |
| GET | `/admin/enquiries` | Admin | List and filter buyer leads |
| GET | `/enquiries/{id}` | Admin | Read one lead |
| PATCH | `/enquiries/{id}/status` | Admin | Change the lead status |
| GET | `/enquiries/{id}/follow-ups` | Admin | Read its contact history |
| POST | `/enquiries/{id}/follow-ups` | Admin | Record a contact attempt and next step |
| GET | `/enquiries/{id}/matches` | Admin | Read saved property matches |
| POST | `/enquiries/{id}/matches/refresh` | Admin | Find and persist currently suitable listings |
| PATCH | `/property-matches/{id}/status` | Admin | Track a match response |

`POST /enquiries` accepts `lead_name` (required), plus optional `phone`,
`email`, `enquiry_source`, `preferred_contact`, `listing_type`, `property_type`,
`country`, `city`, `area`, `budget_currency`, `min_budget`, `max_budget`, and
`message`. Unknown fields (including caller-supplied IDs, status, and actor
IDs) are rejected. Budget values must be non-negative `DECIMAL(15,2)` values;
the minimum cannot exceed the maximum. Email and field lengths are validated.
New leads use the database default `NEW` status.

Source values: `Website`, `WhatsApp`, `SMS`, `Email`, `Phone`, `Referral`, or
`Other`. Preferred contact: `Phone`, `WhatsApp`, `Email`, or `SMS`. Listing
types accept `For Sale`/`sale`, `For Rent`/`rent`, or `BnB`. Property types
follow the `properties.property_type` enum. Status values are `NEW`,
`CONTACTED`, `FOLLOW_UP`, `MATCHED`, and `CLOSED`.
Status updates accept either `{ "status": "FOLLOW_UP" }` or
`{ "enquiry_status": "FOLLOW_UP" }`; the latter is retained as a database-field
alias. If both keys are present they must contain the same value.

Admin list filtering supports `status`, `source`, `page`, and `limit` (1–100).
The response contains the stored database column names:

```json
{
  "success": true,
  "message": "Enquiry received",
  "enquiry": {
    "id": 41,
    "lead_name": "Grace Wanjiku",
    "enquiry_source": "WhatsApp",
    "enquiry_status": "NEW"
  },
  "matches": []
}
```

Match discovery runs when a lead is created or an admin explicitly refreshes it.
The GET endpoint only reads persisted matches. Discovery uses approved,
available properties and the enquiry's listing
type, property type, country, city, area (searched in property address), budget
currency, and price range. Each discovered pair is retained in
`property_matches`; the table's unique `(enquiry_id, property_id)` key prevents
duplicate matches. Since the property schema does not define BnB listings, a
BnB lead currently returns no listing matches. Match statuses are `NEW`,
`CONTACTED`, `INTERESTED`, `DECLINED`, and `CLOSED`.

Follow-up requests require `follow_up_type` (`Call`, `WhatsApp`, `Email`,
`SMS`, `Meeting`, or `Other`) and accept `follow_up_status`, `notes`, and an
ISO 8601 `next_follow_up_at`. The API sets `user_id` from the authenticated
admin; caller-supplied actor IDs are rejected.

The current buyer-lead schema has no assigned-agent or lead-owner field.
Therefore lead listing, status changes, follow-up access, and matching are
admin-only; exposing all buyer contact details to every agent would not be
properly scoped. Add an explicit assignment relation before enabling
agent-scoped lead access.

## Property-specific inquiries

| Method | Path | Access | Purpose |
|---|---|---|---|
| POST | `/properties/{id}/inquiries` | Public, rate-limited | Send a message about an approved, available property |
| GET | `/admin/inquiries` | Admin | List all property messages |
| GET | `/properties/{id}/inquiries` | Property owner or admin | View messages for one property |
| PATCH | `/inquiries/{id}/status` | Property owner or admin | Mark a message `new`, `read`, `replied`, or `closed` |

Property inquiry submission requires `name`, valid `email`, and `message`;
`phone` is optional. The property must still be approved and available at
submission time. A signed-in submitter's user ID is recorded when available;
anonymous submissions use `NULL`. Owners can access only messages for
properties whose `owner_id` matches their account. No endpoint physically
deletes leads, follow-ups, matches, or property inquiries, and no outbound email
or messaging service is invoked.

Admin property-inquiry listing and owner property-specific listing support
`status`, `page`, and `limit` filters.

Run the schema-backed checks with:

```powershell
C:\xampp\php\php.exe tests\enquiry_api_test.php
```
