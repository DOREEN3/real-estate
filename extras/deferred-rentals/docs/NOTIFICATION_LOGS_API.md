# Notification Logs API

The workflow uses the existing `notification_logs` table in
`database/real_estate (1).sql`. It records pending notifications and provider
delivery outcomes; it does not send email, SMS, or other notifications itself.
All routes require an admin bearer access token because records contain
recipient contact details.

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/v1/admin/notification-logs` | Record a pending notification |
| GET | `/api/v1/admin/notification-logs` | List and filter logs |
| GET | `/api/v1/admin/notification-logs/{id}` | Read one log |
| PATCH | `/api/v1/admin/notification-logs/{id}/status` | Record a delivery outcome or retry |

## Create a pending log

`POST /api/v1/admin/notification-logs` accepts:

```json
{
  "recipient_name": "Grace Wanjiku",
  "recipient_contact": "+254712345678",
  "channel": "sms",
  "notification_type": "enquiry_follow_up",
  "message": "We received your enquiry.",
  "enquiry_id": 41
}
```

`recipient_contact`, `channel`, `notification_type`, and `message` are
required. Channels are `email`, `sms`, or `whatsapp` (case-insensitive input,
stored lowercase). The nullable relationship fields are `enquiry_id`, `property_id`,
`sale_id`, `installment_id`, `rent_charge_id`, and `rent_payment_id`; when
provided, each must refer to an existing row. New records use the schema's
`pending` status and default retry count. Caller-supplied status, timestamps,
provider results, and retry counts are rejected.

## Delivery status

The status request accepts `delivery_status` and optional `provider`,
`provider_reference`, and `error_message`. Transitions are restricted to:

```json
{
  "delivery_status": "sent",
  "provider": "sms_gateway",
  "provider_reference": "provider-message-id"
}
```

| Current | Allowed next statuses |
|---|---|
| `pending` | `sent`, `failed` |
| `sent` | `delivered`, `failed` |
| `failed` | `pending` |
| `delivered` | none |

Changing a failed log back to `pending` records one retry by incrementing
`retry_count`. The API does not dispatch that retry. `sent_at` is set on the
first transition to `sent` and preserved across retries; it represents the
first successful send attempt, not the latest attempt. `delivered_at` is set
when marked `delivered`. A failed transition stores the supplied error message, while a
non-failed status clears it. Concurrent stale status updates return `409`.

The list endpoint supports `delivery_status`, the supported `channel` values,
`notification_type`,
all six relationship IDs, `page`, and `limit` (1–100). Responses use the
standard success envelope and include pagination.

Logs are intentionally not editable or deletable through this API.
