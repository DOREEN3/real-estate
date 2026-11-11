# Extras & Archived Modules Backup

This directory serves as a safe backup for features, workflows, and endpoints that were implemented beyond the core specifications outlined in `userrequirements.txt`.

---

## 1. Property Verification & Physical Site Visits Subsystem (`extras/property-verification/`)

### What it does:
- Provides an enterprise verification workflow for properties.
- Includes physical site visit scheduling (`/visits`), assigning verification officers, capturing visit outcomes, uploading and downloading title deeds / verification documents into private storage.

### Why it was moved to Extras (Overengineering Analysis):
- **User Requirement 8** states:
  > *"Verified agents and property owners to list the properties directly but enquiry contacts to be done to us."*
- Requirement 8 specifies account-level authorization: verified agents and property owners list listings directly once approved by the platform administrator, with customer inquiries directed through the platform office.
- The platform already fulfills this requirement via `UserController` (`account_status` = `'pending'` / `'approved'`) and Property review (`property_status` = `'pending'` / `'approved'`).
- The multi-stage physical on-site inspection visit workflow, officer booking, and private document deed vault exceeded the client's simple listing and approval requirement.

### Contents:
- `controllers/PropertyVerificationController.php`: Verification review, document upload, and site visit booking handlers.
- `models/PropertyVerificationModel.php`: Database interactions for `property_verifications` and `property_verification_visits`.
- `database/property_verification_migration.sql` & `property_verification_outcome_migration.sql`: DDL schema for verification tables.
- `tests/property_verification_test.php`: Automated unit tests.

### How to re-enable:
1. Re-mount the verification routes in `routes/PropertyRoutes.php`.
2. Add route permissions to `Middleware::$routeRoleRequirements`.
3. Require `PropertyVerificationController` in `PropertyRoutes.php`.

---

## 2. Tenancy & Rent Roll Management ERP (`extras/deferred-rentals/`)

### What it does:
- Comprehensive property management system (PMS) covering tenant onboarding, active leases/tenancies, recurring monthly rent charges, rent payments, and rent receipts.

### Why it was moved to Extras (Overengineering Analysis):
- **User Requirement 9** states:
  > *"Software to accommodate properties for sale, BnB and rental properties worldwide."*
- Requirement 9 is a property catalog listing classification (`listing_type` or `type` = 'sale', 'bnb', 'rental') to allow worldwide browsing and inquiries for rentals and BnBs alongside sales.
- Building a full tenancy lease-accounting billing engine exceeded the catalog requirement and added significant unnecessary complexity to what is primarily a real-estate sales, BnB, and rental listing platform.

### Contents:
- `app/controllers/RentalController.php`: Tenancy and rent lifecycle controller.
- `app/models/RentalModel.php`: Tenancy and billing models.
- `routes/RentalRoutes.php`: Active rental routing.
- `docs/RENTALS_API.md`: API documentation.

---

## 3. CRM Internal Enquiry Follow-up Ticketing (`extras/crm-followups/`)

### What it does:
- Internal call-center CRM ticket follow-up logs (`enquiry_follow_ups`) attached to enquiries.

### Why it was moved to Extras:
- **User Requirement 2 & 3** specified saving enquiries and automatically matching them to properties. Detailed agent internal call ticketing was not part of the required automated matching workflow.
