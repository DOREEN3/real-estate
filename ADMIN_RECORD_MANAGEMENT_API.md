# Admin Record Management, Deletion & Transaction Reversal API
## Technical Reference and Administrative Lifecycle Rules

---

## 1. Core Principles

In our real estate management platform, **deleting a record is fundamentally distinguished from reversing or cancelling a transaction**:

1. **Financial History Integrity**:
   - Posted financial transactions (payments, receipts) must never be permanently deleted from the database.
   - Deleting a payment destroys the audit trail of who received or posted funds and disrupts annual financial reconciliations.
   - Payments are reversed, refunded, or voided. Reversing an incorrect payment preserves the original transaction, logs the administrator and reason, and automatically excludes the reversed funds from installment and sale totals.
2. **Account Deactivation vs. Permanent Deletion**:
   - Agents and owners are linked to active properties, sales, enquiries, and tenancies.
   - Permanently deleting an account row cascades or orphans critical business records.
   - Instead, accounts can be **suspended** (temporary login block), **deactivated** (permanent access revocation with records preserved), or **safely deleted** (anonymizing personal identifiers only after role obligations and property ownership are reassigned).
3. **Draft Deletion**:
   - Sales, tenancies, or charges that have zero completed payments are considered unconfirmed drafts. These can be deleted permanently or soft-deleted to keep the dashboard clean.
4. **Mandatory Audit Trail**:
   - Every sensitive administrative action requires:
     - An authenticated administrator (`JWT sub`).
     - A mandatory reason for the action (minimum 5 characters).
     - Execution timestamp.
     - An immutable entry in `audit_logs`.

---

## 2. API Endpoints Quick Reference

| Category | Endpoint | Method | Action | Obligation / Validation Gate |
| :--- | :--- | :--- | :--- | :--- |
| **Accounts** | `/api/v1/admin/users/{id}/suspend` | `PATCH` | Suspend login | Revokes refresh tokens; records reason |
| **Accounts** | `/api/v1/admin/users/{id}/reactivate` | `PATCH` | Restore access | Restores `approved` status |
| **Accounts** | `/api/v1/admin/users/{id}/deactivate` | `PATCH` | Deactivate account | Permanent access cut; preserves all data; last admin protected |
| **Accounts** | `/api/v1/admin/users/{id}/delete` | `POST` / `DELETE` | Safe account deletion | Requires 0 active listings & 0 pending installments; anonymizes personal PII |
| **Accounts** | `/api/v1/admin/users/{id}/transfer-properties` | `POST` | Reassign properties | Transfers `owner_id` and/or `managed_by` to an approved target account |
| **Sales** | `/api/v1/admin/sales/{id}/cancel` | `POST` | Cancel sale | Marks sale `cancelled`; cancels unpaid installments; returns property to `available` |
| **Sales** | `/api/v1/admin/sales/{id}` | `DELETE` | Delete draft sale | Blocked if completed payments exist; otherwise cleans draft installments |
| **Payments** | `/api/v1/admin/payments/{id}/reverse` | `POST` | Reverse, refund, or void | Excludes from balances; reverts installment & sale completion status |
| **Tenancies** | `/api/v1/admin/tenancies/{id}/terminate` | `POST` | Terminate lease | Sets `end_date`; updates property availability via helper |
| **Tenancies** | `/api/v1/admin/tenancies/{id}` | `DELETE` | Delete draft lease | Blocked if any completed payment exists |
| **Installments** | `/api/v1/admin/installments/{id}/cancel` | `POST` | Cancel installment | Allowed only if 0 payments allocated |
| **Installments** | `/api/v1/admin/installments/{id}` | `PATCH` | Adjust amount / due date | `amount_due` cannot be lower than already paid amount |
| **Rent Charges** | `/api/v1/admin/rent-charges/{id}/void` | `POST` | Void charge | Allowed only if 0 payments recorded |
| **Rent Charges** | `/api/v1/admin/rent-charges/{id}` | `PATCH` | Adjust charge | Cannot adjust lower than paid amount |
| **Tenants** | `/api/v1/admin/tenants/{id}/archive` | `PATCH` | Archive tenant | Marks `archived`; retains lease history |
| **Audit** | `/api/v1/admin/audit-logs` | `GET` | Query audit trail | Paginated; filterable by `resource_type`, `action`, `user_id` |

---

## 3. Stage 1: Admin Account Management

### 3.1 Suspend Account
- **Endpoint**: `PATCH /api/v1/admin/users/{id}/suspend`
- **Headers**: `Authorization: Bearer <ADMIN_JWT>`
- **Request Body**:
```json
{
  "reason": "Policy violation under review"
}
```
- **Response**: `200 OK`
```json
{
  "success": true,
  "message": "User account suspended successfully"
}
```

### 3.2 Reactivate Account
- **Endpoint**: `PATCH /api/v1/admin/users/{id}/reactivate`
- **Request Body**:
```json
{
  "reason": "Investigation cleared, restoring access"
}
```

### 3.3 Deactivate Account Directly
- **Endpoint**: `PATCH /api/v1/admin/users/{id}/deactivate`
- **Request Body**:
```json
{
  "reason": "Agent departed the agency on 2026-11-10"
}
```
- **Response**: `200 OK`
```json
{
  "success": true,
  "message": "Account deactivated successfully"
}
```

### 3.4 Transfer Property Management / Ownership
- **Endpoint**: `POST /api/v1/admin/users/{id}/transfer-properties`
- **Path Param**: `{id}` = Source user ID
- **Request Body**:
```json
{
  "target_user_id": 5,
  "transfer_type": "all",
  "reason": "Reassigning managed portfolio following agent resignation"
}
```
*Note: `transfer_type` can be `"all"`, `"managed"`, or `"owned"`.*
- **Response**: `200 OK`
```json
{
  "success": true,
  "message": "Properties transferred successfully",
  "owned_transferred": 2,
  "managed_transferred": 3
}
```

### 3.5 Safely Delete User Account
- **Endpoint**: `POST /api/v1/admin/users/{id}/delete` or `DELETE /api/v1/admin/users/{id}`
- **Request Body**:
```json
{
  "reason": "GDPR compliance request from user"
}
```
- **Response (If Blocked)**: `409 Conflict`
```json
{
  "success": false,
  "message": "Cannot delete user account due to active obligations",
  "blocking": [
    "2 active property listing(s) exist. Resolve or reassign them before the account can be deactivated."
  ],
  "warnings": []
}
```
- **Response (When Clean)**: `200 OK`
```json
{
  "success": true,
  "message": "User account deleted and personal details anonymized safely"
}
```

---

## 4. Stage 2: Transaction Management

### 4.1 Sale Cancellation
- **Endpoint**: `POST /api/v1/admin/sales/{id}/cancel`
- **Request Body**:
```json
{
  "reason": "Buyer mortgage declined by lender"
}
```
- **Behavior**:
  - Sets `sale_status = 'cancelled'`.
  - Sets all remaining unpaid installments to `cancelled`.
  - Releases property availability status back to `available`.
  - Completed payments remain on record for financial reconciliation or refunding.

### 4.2 Delete Draft Sale
- **Endpoint**: `DELETE /api/v1/admin/sales/{id}`
- **Request Body**:
```json
{
  "reason": "Duplicate draft entry created by accident"
}
```
- **Rule**: If any completed payment has been posted to this sale, returns `409 Conflict`:
```json
{
  "success": false,
  "message": "Cannot delete sale with completed payments. Cancel the sale or reverse the payments first."
}
```

### 4.3 Payment Reversal, Refund, or Void
- **Endpoint**: `POST /api/v1/admin/payments/{id}/reverse`
- **Request Body**:
```json
{
  "action": "reverse",
  "reason": "Posted KSh 50,000 instead of KSh 5,000; reversing to re-record corrected voucher"
}
```
*`action` can be `"reverse"`, `"refund"`, or `"void"`.*
- **System Impact**:
  - `payment_status` becomes `reversed`, `refunded`, or `voided`.
  - Payment is instantly excluded from all `total_paid` calculations.
  - Linked installment paid amount drops; installment status drops from `paid` back to `partial` or `pending`.
  - Sale completion check (`refreshSaleState`) detects balance > 0 and moves `sale_status` back from `completed` to `in_progress`.
  - `next_installment_due` is re-scheduled.

### 4.4 Tenancy Termination
- **Endpoint**: `POST /api/v1/admin/tenancies/{id}/terminate`
- **Request Body**:
```json
{
  "end_date": "2026-11-30",
  "reason": "Early lease termination by mutual tenant agreement"
}
```

### 4.5 Delete Draft Tenancy
- **Endpoint**: `DELETE /api/v1/admin/tenancies/{id}`
- **Request Body**:
```json
{
  "reason": "Unconfirmed draft lease"
}
```

### 4.6 Cancel or Adjust Installments
- **Cancel Installment**: `POST /api/v1/admin/installments/{id}/cancel`
  - Body: `{"reason": "Deposit waived"}` (Allowed only if 0 payments made).
- **Adjust Installment**: `PATCH /api/v1/admin/installments/{id}`
  - Body: `{"amount_due": "15000.00", "due_date": "2026-12-15", "reason": "Restructured schedule"}`

### 4.7 Void or Adjust Rent Charges
- **Void Rent Charge**: `POST /api/v1/admin/rent-charges/{id}/void`
  - Body: `{"reason": "Billed for maintenance-occupied month"}` (Allowed only if 0 payments made).
- **Adjust Rent Charge**: `PATCH /api/v1/admin/rent-charges/{id}`
  - Body: `{"amount_due": "20000.00", "due_date": "2026-12-05", "reason": "Rent discount applied"}`

### 4.8 Archive Tenant
- **Endpoint**: `PATCH /api/v1/admin/tenants/{id}/archive`
- **Request Body**:
```json
{
  "reason": "Tenant relocated abroad"
}
```

---

## 5. Audit Log API

Query administrative and sensitive action history:
- **Endpoint**: `GET /api/v1/admin/audit-logs`
- **Query Parameters**:
  - `resource_type` (e.g. `users`, `sales`, `payments`, `tenancies`, `installments`, `rent_charges`, `tenants`)
  - `resource_id` (integer)
  - `action` (e.g. `payment_reverse`, `sale_cancelled`, `user_suspended`, `properties_transferred`)
  - `user_id` (administrator ID)
  - `page` (default 1)
  - `limit` (default 20, max 100)
- **Response**: `200 OK`
```json
{
  "success": true,
  "message": "Audit logs retrieved successfully",
  "data": [
    {
      "id": 14,
      "user_id": 1,
      "action": "payment_reverse",
      "resource_type": "payments",
      "resource_id": 25,
      "ip_address": "127.0.0.1",
      "user_agent": "Mozilla/5.0 ...",
      "details": {
        "action": "reverse",
        "target_status": "reversed",
        "reason": "Posted KSh 50,000 instead of KSh 5,000",
        "previous_status": "completed",
        "amount": "50000.00",
        "sale_id": 12,
        "installment_id": 34
      },
      "created_at": "2026-11-11 08:30:15",
      "administrator_name": "Super Admin",
      "administrator_email": "admin@example.com"
    }
  ],
  "pagination": {
    "page": 1,
    "limit": 20,
    "total": 1,
    "pages": 1
  }
}
```
