# Sales API

The API uses the existing `sales`, `installments`, `payments`, and
`properties` tables in `database/real_estate (2).sql`. All endpoints require
an admin bearer access token.

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/v1/admin/sales` | Create a sale and its optional installment schedule |
| GET | `/api/v1/admin/sales` | Paginate and filter sales |
| GET | `/api/v1/admin/sales/{id}` | Retrieve a sale with its installment schedule and balance |
| GET | `/api/v1/admin/sales/{id}/installments` | Retrieve installments with paid and outstanding amounts |

## Create a sale

Example installment sale:

```json
{
  "property_id": 25,
  "buyer_name": "Grace Wanjiku",
  "buyer_email": "grace@example.com",
  "sale_price": "5000000.00",
  "deposit_amount": "1000000.00",
  "number_of_installments": 4,
  "installment_frequency": "monthly",
  "schedule_start_date": "2026-11-15"
}
```

`property_id`, `buyer_name`, and `sale_price` are required. The property must
exist, be approved and available, and have no in-progress or completed sale.
`enquiry_id`, `buyer_email`, and `currency` are optional; currency defaults to
the property's currency. Sale price must be positive, and the agreed deposit
must be between zero and the sale price.

`number_of_installments` defaults to 0 and is limited to 120. With no
installment arrangement, the sale is created without installment rows.
`deposit_amount` remains the agreed amount only; it is **not** counted as a
payment or subtracted from the outstanding balance. A cash sale can be
recorded this way and its payment entered separately through the payments
API.

When installments are requested, the deposit must be positive and less than
the sale price. `installment_frequency` must be `monthly`, and
`schedule_start_date` is required. The start date is the due date of the
deposit installment (number 0); regular installments 1 through N are due
monthly thereafter. Month-end dates are clamped to the last day of shorter
months. The remaining sale balance is distributed in cents across the
installments, with any remainder assigned one cent at a time to the earliest
installments, so all scheduled amounts sum exactly to the sale price.

Sale and installment rows are inserted in one transaction. Every new
installment starts `pending`. No payment or receipt is created when a sale is
created. The database's unique `(sale_id, installment_number)` key identifies
the deposit row as installment 0.

## Balances and payment tracking

Sale `total_paid` and `outstanding_balance` include only payments whose
`payment_status` is `completed`. The agreed `sales.deposit_amount` is not
counted until an actual payment is recorded and completed. A scheduled deposit
is paid by recording a `deposit` payment against installment 0. Payments for
regular installments must be linked to their installment row. Partial
payments each receive a separate payment record and, once completed, a
separate receipt. Installment status becomes `partial` or `paid` based on the
sum of completed payments against that installment.

Payments cannot exceed the remaining sale or installment balance. Provider
payments are checked again at confirmation because other payments may have
completed while a provider attempt was pending.

The list endpoint supports `sale_status`, `property_id`, `page`, and `limit`
(1–100).

Sale records and schedules have no edit or delete endpoint in this workflow.
The API does not change property availability or sale status.
