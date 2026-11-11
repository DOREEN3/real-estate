# Sales Payments API

The active payment workflow uses the `payments`, `payment_receipts`, and
`installments` tables. Rental payments are deferred and are not exposed by the
active API. All administrative endpoints require an admin bearer access
token; the Daraja callback is public for provider delivery.

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/v1/admin/sales/{id}/payments` | Record a manual or pending provider payment |
| GET | `/api/v1/admin/sales/{id}/payments` | List payments for a sale |
| GET | `/api/v1/admin/payments` | Filter and list sale payments |
| GET | `/api/v1/admin/payments/{id}` | Retrieve a sale payment and receipt |
| PATCH | `/api/v1/admin/payments/{id}/status` | Resolve a pending provider payment |
| POST | `/api/v1/admin/sales/{id}/payments/mpesa/stk-push` | Initiate an M-Pesa STK Push |
| POST | `/api/v1/payments/providers/mpesa/callback` | Receive and verify a Daraja callback |

## Manual payment

```json
{
  "amount": "12500.00",
  "payment_type": "installment",
  "payment_method": "bank_transfer",
  "payment_source": "manual",
  "transaction_reference": "TXN-100",
  "payment_date": "2026-11-05T10:30:00+03:00",
  "installment_id": 81
}
```

`amount`, `payment_type` (`deposit`, `installment`, or `full`), and
`payment_method` (`cash`, `mobile_money`, or `bank_transfer`) are required.
`payment_source` defaults to `manual`; `transaction_reference`,
`payment_date`, and `installment_id` are optional. Amounts must be positive
values fitting `DECIMAL(15,2)`.

Manual payments are recorded as `completed` and receive a receipt in the same
transaction. The acting admin supplies the `recorded_by` and `issued_by`
values. Completed payments alone count toward the sale balance. A deposit
must reference that sale's installment number 0. Payments on a scheduled sale
must be allocated to an installment. Amounts are checked against the
outstanding sale and installment balances.

## M-Pesa STK Push

`POST /api/v1/admin/sales/{id}/payments/mpesa/stk-push` accepts `amount`,
`payment_type`, `payer_phone`, and optional `installment_id`. The amount must
be a whole number of Kenyan shillings. Initiating the STK Push creates a
pending provider payment with no receipt; initiation alone does not count as
payment received.

Daraja callbacks are stored in the callback inbox and checked against the
provider's STK status before changing the payment. Only a verified successful
callback completes the payment, updates installment status, and issues a
receipt. Failed callbacks remain failed without a receipt. The callback
inbox allows callbacks that arrive before the checkout reference is attached
to the payment to be processed later.

For other pending provider payments, an admin can use
`PATCH /api/v1/admin/payments/{id}/status` with `payment_status` set to
`completed` or `failed`. Confirmation rechecks the outstanding balances.

All list endpoints are paginated (`page`, default 1; `limit`, default 12,
maximum 100). Sale-payment filters are `sale_id`, `payment_type`,
`payment_method`, `payment_source`, and `payment_status`. Payments and
receipts cannot be edited or deleted through these endpoints.
