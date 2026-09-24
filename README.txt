THE LEISURE HUB v1.3.0 - GENERIC RENTALS ENGINE

This full application package upgrades Food Stall Rentals into a reusable Rentals
module. Existing Food Stall data is preserved and automatically copied into the new
generic rental tables.

Highlights
- Admin-managed rental categories and rentable catalog.
- Food stalls, restaurant/cafe/commercial spaces, kiosks, tents, chairs, tables,
  and future rentables can be added without PHP changes.
- Exclusive-space and quantity-based availability.
- One-time/agreed and monthly recurring billing.
- Continuous and flexible dates where enabled.
- Manual negotiated pricing; no default price is stored on a rentable.
- Payments, extensions, early termination, complimentary rentals, final charges,
  print, permanent delete, and Rental Calendar.
- Monthly billing periods support Electricity and Water final charges.
- Optional refundable Security Deposit tracking, separate from Sales and rental payments.
- Configurable additional rental charge types.
- Existing Food Stall URLs redirect to the generic Rentals pages.

IMPORTANT BEFORE DEPLOYMENT
1. Back up the production database.
2. Keep your existing config/local.php on the server.
3. Deploy the application files.
4. The app will attempt to create the v1.3.0 schema automatically. If your MySQL
   user cannot CREATE TABLE, run database/upgrade_v1.3.0_generic_rentals.sql first.
5. Open Admin > Manage Rentables and verify the migrated Food Stall entries.

See README.md and UPDATE_v1.3.0.txt for more details.
