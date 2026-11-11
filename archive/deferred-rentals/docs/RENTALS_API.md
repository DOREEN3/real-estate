# Rentals API

All endpoints in this document require an authenticated admin JWT. Tenant
records are contact records and are not linked to login accounts.

## Tenants

| Method | Route | Description |
|---|---|---|
| POST | `/api/v1/admin/tenants` | Create a tenant contact |
| GET | `/api/v1/admin/tenants` | Paginate tenants |
| GET | `/api/v1/admin/tenants/{id}` | Retrieve a tenant |

`POST /api/v1/admin/tenants` accepts:

```json
{
  "full_name": "Grace Wanjiku",
  "phone": "+254700000000",
  "email": "grace@example.com",
  "national_id": "12345678"
}
```

`email` and `national_id` are optional and may be `null`. Tenant lists accept
`page` and `limit` query parameters; `limit` is between 1 and 100.

## Tenancies

| Method | Route | Description |
|---|---|---|
| POST | `/api/v1/admin/tenancies` | Create a tenancy and its initial rent charge |
| GET | `/api/v1/admin/tenancies` | Paginate and filter tenancies |
| GET | `/api/v1/admin/tenancies/{id}` | Retrieve a tenancy and its rent charges |
| PATCH | `/api/v1/admin/tenancies/{id}/status` | End an active tenancy |

`POST /api/v1/admin/tenancies` accepts:

```json
{
  "tenant_id": 12,
  "property_id": 34,
  "monthly_rent": "50000.00",
  "currency": "KES",
  "rent_due_day": 5,
  "start_date": "2026-07-20",
  "end_date": null
}
```

`currency` and `end_date` are optional. If omitted, currency is inherited from
the property (or defaults to `KES` when the property's currency is invalid).
The property must be approved for rent, available, and have no overlapping
active tenancy. Creating a tenancy marks its property as rented and creates a
full-month charge for the start month; the charge is not prorated. The initial
charge is due on the later of the start date or configured rent due day, with
the due day clamped to the last day of the month.

Tenancy lists accept `page`, `limit`, `tenant_id`, `property_id`, and
`tenancy_status` (`active` or `ended`) query parameters.

End a tenancy with:

```json
{
  "tenancy_status": "ended",
  "end_date": "2026-12-31"
}
```

`end_date` is optional and defaults to the current date. Ending a tenancy
releases the property when no other active tenancy remains.

## Rent charges

| Method | Route | Description |
|---|---|---|
| GET | `/api/v1/admin/tenancies/{id}/rent-charges` | Paginate charges for a tenancy |
| POST | `/api/v1/admin/tenancies/{id}/rent-charges` | Generate missing monthly charges through a month |
| GET | `/api/v1/admin/rent-charges` | Paginate and filter all charges |
| GET | `/api/v1/admin/rent-charges/{id}` | Retrieve a charge and its paid balance |

Generate charges with:

```json
{
  "through_month": "2026-12"
}
```

The requested month is inclusive, at most 120 months can be generated per
request, and existing `(tenancy_id, rent_month)` charges are left unchanged.
Recurring due dates use the tenancy's configured due day, clamped to the end of
each month. Charges inherit the tenancy currency. Lists accept `page` and
`limit`; the all-charges route also accepts `tenancy_id`, `tenant_id`, and
`property_id`.

Rent payments are recorded and retrieved through the endpoints documented in
[PAYMENTS_API.md](./PAYMENTS_API.md).
