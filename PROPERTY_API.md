# Property API

All routes use the `/api/v1` prefix and JSON request/response bodies. Authenticated requests use `Authorization: Bearer <access_token>`. Property fields and status values mirror the `properties` table in `database/real_estate (1).sql`; image records use `property_images`.

## Routes

| Method | Path | Access | Purpose |
|---|---|---|---|
| GET | `/properties` | Public | Search approved, available listings |
| GET | `/properties/{id}` | Public; owners/admins can also view their non-public listings | Listing details and images |
| POST | `/properties` | Approved agent, owner, or admin | Create a pending listing |
| PUT/PATCH | `/properties/{id}` | Listing owner or admin | Update schema-backed listing fields |
| DELETE | `/properties/{id}` | Listing owner or admin | Archive by setting `availability_status=unavailable`; never deletes the row |
| GET | `/properties/my` | Authenticated | List the signed-in user’s listings, including pending and archived |
| POST | `/properties/{id}/images` | Listing owner or admin | Add an image URL |
| GET | `/admin/properties` | Admin | Search all listings and statuses |
| PATCH | `/properties/{id}/approve` | Admin | Approve a pending listing only after verification is `verified` |
| PATCH | `/properties/{id}/reject` | Admin | Reject a pending listing |

`DELETE` is intentionally a non-destructive archive operation. Property and image records are retained; no property/image deletion API is exposed.

## Search and pagination

`GET /properties` supports `page`, `limit` (1–100), `city`, `state`, `country`, `property_type`, `transaction_type`, `currency`, `min_price`, `max_price`, `bedrooms_min`, `bathrooms_min`, `sort_by`, and `sort_order`.

Example:

```text
GET /api/v1/properties?city=Nairobi&property_type=apartment&transaction_type=rent&min_price=1000&max_price=5000&currency=KES&page=1&limit=12&sort_by=price&sort_order=ASC
```

The public list always filters to `property_status=approved` and `availability_status=available`. Admin listing additionally accepts `property_status` and `availability_status`.

Responses include:

```json
{
  "success": true,
  "message": "Properties retrieved",
  "data": [],
  "pagination": { "page": 1, "limit": 12, "total": 0, "pages": 0 }
}
```

Listings include the `properties` columns, a joined `owner_name`, and `primary_image_url`. A detail response includes the listing and its ordered `property_images` records.

## Create and update

The writable schema fields are `title`, `description`, `property_type`, `transaction_type`, `price`, `currency`, `address`, `city`, `state`, `country`, `postal_code`, `latitude`, `longitude`, `bedrooms`, `bathrooms`, `square_feet`, and `availability_status`.

- `property_type`: `apartment`, `house`, `villa`, `condo`, `townhouse`, `commercial`, or `land`.
- `transaction_type`: `sale` or `rent`.
- `availability_status`: `available`, `sold`, `rented`, or `unavailable`.
- Coordinates must be supplied together and stay within latitude/longitude ranges.
- New listings are always `pending` and `available`; review status cannot be set during creation.
- Only admins can assign `owner_id`, change `property_status`, or set `featured`. Admin-assigned owners must be approved agents or owners.
- `id`, `views_count`, timestamps, and owner assignment on updates are server-managed.

Example create request:

```json
{
  "title": "Two-bedroom apartment",
  "description": "Bright apartment close to public transport",
  "property_type": "apartment",
  "transaction_type": "rent",
  "price": "45000.00",
  "currency": "KES",
  "address": "Westlands",
  "city": "Nairobi",
  "country": "Kenya",
  "latitude": -1.2676,
  "longitude": 36.8108,
  "bedrooms": 2,
  "bathrooms": 1,
  "square_feet": 950
}
```

## Images

Add an image with:

```http
POST /api/v1/properties/{id}/images
Content-Type: application/json
```

```json
{
  "image_url": "https://cdn.example.com/listings/123/front.jpg",
  "alt_text": "Front of the property",
  "is_primary": true,
  "sort_order": 0
}
```

Only HTTP(S) URLs are accepted. Marking an image primary clears the previous primary flag for that property. The API does not delete image records.

Run the model/schema regression checks using `php tests\property_api_test.php`.

## Property verification and document review

Verification records are kept in `property_verifications`; site visits are kept in
`property_verification_visits`. The API never deletes verification, visit, or document
records. Documents are stored outside the public web root, are limited to 10 MB, and
must be PDF, JPEG, or PNG. Uploads use the multipart field `document`. The document
path is never returned in API responses; use the protected download route instead.

Verification statuses are `pending`, `documents_submitted`, `under_review`,
`visit_required`, `verified`, and `rejected`. Admin review requires an uploaded
document. Final `verified` and `rejected` records cannot be reviewed again. Visit
statuses are `scheduled`, `completed`, and `cancelled`; only the assigned agent or an
administrator can record an outcome, and completed/cancelled visits cannot be changed.
Completing a visit moves a `visit_required` verification back to `under_review` for
an administrator's final decision; cancelling a visit leaves it `visit_required`.
Property approval requires the latest verification record to be `verified`; older
verified records do not override a newer pending or rejected request.

| Method | Path | Access | Purpose |
|---|---|---|---|
| POST | `/properties/{id}/verification` | Property owner or admin | Start/reuse an active verification request |
| GET | `/properties/{id}/verification` | Property owner or admin | View verification history |
| POST | `/property-verifications/{id}/document` | Property owner, assigned agent, or admin | Upload the verification document |
| GET | `/property-verifications/{id}/document` | Property owner, assigned agent, or admin | Download the private document |
| GET | `/admin/property-verifications` | Admin | Review queue; supports `status`, `page`, and `limit` |
| PATCH | `/admin/property-verifications/{id}/review` | Admin | Change review status and notes |
| POST | `/admin/property-verifications/{id}/visits` | Admin | Assign an approved agent/admin and schedule a visit |
| GET | `/property-verifications/my-visits` | Agent or admin | List assigned visits; admin sees all |
| PATCH | `/property-verification-visits/{id}` | Assigned agent or admin | Record a scheduled visit outcome |

Review request example:

```json
{
  "verification_status": "under_review",
  "review_notes": "Submitted ownership document is legible"
}
```

Visit scheduling example:

```json
{
  "assigned_to": 42,
  "scheduled_at": "2099-01-02 10:00:00",
  "visit_notes": "Inspect the original ownership documents"
}
```

To record a visit outcome, send `visit_status` as `completed` or `cancelled` and
include `visit_notes` (required for completion). Existing installations must first
run `database/property_verification_migration.sql` once; it adds generated IDs and
lookup indexes without deleting or rewriting verification data. Also run
`database/property_verification_outcome_migration.sql` once to allow outcome recorder
to remain unset until a visit outcome is submitted. Fresh installations get the
appropriate schema from `database/migrations.sql`.
