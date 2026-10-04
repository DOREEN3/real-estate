# GoldERP Real Estate Demo

A browser-based real-estate management demo built with React and Vite. It demonstrates role-specific workflows for managing property listings, buyer enquiries, sales, and payments. The app currently uses local demo data; backend services and production authentication have not been implemented.

## Run locally

```sh
npm install
npm run dev
```

## Demo accounts

| Role | Email | Password |
| --- | --- | --- |
| Admin | `admin@golderp.demo` | `admin123` |
| Agent | `lydia@golderp.example` | `agent123` |
| Owner | `miriam@example.com` | `owner123` |

Select an account on the sign-in page to fill in its demo credentials. Sign out to switch roles. The demo account list is fixed: adding an agent or owner from the Admin screens creates a contact record, not a login account.

## Roles and permissions shown in the demo

| Role | Pages | Main actions and data scope |
| --- | --- | --- |
| Admin | Dashboard, Properties, Enquiries, Agents, Owners, Sales, Payments, Notifications | Sees all demo records; can add listings and enquiries, manage agent/owner records and verification, register sales, record payments, and review simulated activity. |
| Agent | Dashboard, Properties, Enquiries, Sales | Sees listings assigned to that agent, related/matching leads, and sales for those listings. Can add listings and enquiries, update enquiry status, send simulated match alerts/reminders, and register sales. Payment recording is admin-only. |
| Owner | Dashboard, Properties, Enquiries | Sees listings assigned to that owner and related/matching enquiries. Can add listings; enquiry viewing is read-only. Sales and payment management are not available in the owner workspace. |

Role checks and data filtering currently happen in the React app. They are demo behavior, not security controls; a backend must independently authenticate the user, authorize every operation, and enforce record ownership.

## Main workflows

### Properties

Users can browse and filter listings by keyword/location, listing type, property type, status, currency, and price range. Admin can attribute a new listing to a verified agent or owner. Agent- and owner-created listings are automatically attributed to the signed-in demo account. Listing details can be used to record a buyer enquiry.

### Enquiries

An enquiry stores buyer contact details and requirements, a status, and a source. Source is distinct from the buyer's preferred contact method:

- **Source:** Website, WhatsApp, SMS, or Email — where the enquiry came from.
- **Preferred contact method:** WhatsApp, Phone, or Email — how the buyer prefers to be contacted.

The pipeline filters by both status and source. Enquiries can be matched against listings; status updates and match alerts are simulated. Enquiries created by an agent are associated with that agent. Enquiries tied to a property retain its property reference; website/general enquiries may have no property reference.

### Sales and payments

Sales reference a property and record buyer, agreed price, deposit, and installment schedule. Registering a sale creates a demo deposit receipt and marks the property as sold. Admin can record further payments and view payment history/statements. Reminders and receipts are local demo activity only.

## Data relationships for backend planning

These relationships describe the current demo and a reasonable starting point for the API/database design; they are not a finalized schema:

- **User** has a role (`admin`, `agent`, or `owner`). An agent/owner login should be linked to its corresponding agent/owner profile. The current demo only has one fixed login for each role.
- **Property** may reference an assigned agent and owner. The demo uses `agentId` and `ownerId`; either can be empty for an unassigned contact.
- **Enquiry** has a source, preferred contact method, status, buyer requirements, and may reference a property and/or assigned agent. Matching may associate one enquiry with multiple suitable properties; a match should not be confused with an explicit property interest.
- **Sale** references a property and contains the buyer and agreement/payment-plan details.
- **Payment** references a sale; sale payment totals determine the remaining balance.
- **Notification/activity** currently records a message, category, timestamp, and audience as display text. Real notification delivery, recipients, and delivery status are not implemented.

When building the backend, use stable server-generated IDs and explicit foreign keys/relations rather than relying on display names or the demo's browser-generated IDs. Confirm business rules for agent assignment, enquiry ownership, sale creation, verification, and owner visibility before enforcing them in the API.

## Demo-to-backend handoff

Replace or revisit the following before production:

1. **Authentication:** Replace the hard-coded credentials in `src/data/demoUsers.js` and browser session state with server-backed sign-in, secure password handling, session/token lifecycle, and logout.
2. **Authorization:** Enforce role permissions and record-level access in the backend on every read/write; hiding a page or filtering arrays in the client is insufficient.
3. **Data persistence:** Replace `usePersistentState`/`localStorage` and the seed data in `src/data/demoData.js` with API-backed loading and mutations, including loading/error states and server validation.
4. **Identity and account management:** Decide how admin-created agent/owner profiles receive accounts, invitations, password setup, verification, and account deactivation. In the demo, profile creation does not provision a login.
5. **External integrations:** Implement and monitor actual Email, SMS, and WhatsApp delivery. The current “notifications,” alerts, and reminders are simulated and do not send messages.
6. **Audit and data rules:** Define validation, audit history, currency/payment rules, deletion behavior, and who may change enquiry status or register a sale.

## Demo limitations

Credentials are visible in the client bundle, browser storage is not shared between users or devices, and local data can be changed by the browser user. Do not use real personal, payment, or credential data with this prototype. Build and lint checks can be run with `npm run build` and `npm run lint`.
