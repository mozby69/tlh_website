# The Leisure Hub v1.3.6

TLH v1.3.6 includes the **Generic Rentals Engine** plus deployment hardening for rental concurrency, PWA cache upgrades, and stricter reservation validation. Food Stall Rentals are now the first category inside a reusable rental system that can also support restaurant/cafe/commercial spaces, kiosks, tents, chairs, tables, and future rentables without PHP changes.

## Key rental features

- Admin-managed Rentables catalog with no default pricing.
- Exclusive spaces or quantity-based inventory.
- One-time/agreed billing or monthly recurring lease billing.
- Continuous or flexible dates where allowed.
- Multiple compatible rentables in one rental transaction.
- Manual agreed price/amount per rentable.
- Complimentary/free rentals.
- Payment history, extensions, early end, password-protected permanent delete, print, and Rental Calendar.
- Monthly billing periods with post-period Electricity/Water charges.
- Optional refundable Security Deposit ledger, kept separate from Sales and rental payments.
- Configurable additional rental charge types.
- Existing Food Stall data is migrated automatically and old Food Stall URLs redirect to the generic Rentals pages.

## Upgrade

Back up the production database before first deployment. The application automatically runs `database/upgrade_v1.3.0_generic_rentals.sql` and `database/upgrade_v1.3.6_rental_cancellations.sql` when schema privileges are available. If production MySQL does not allow CREATE TABLE, run those migrations manually first; the application will then continue normally.

Keep the server's existing `config/local.php`. It should not be replaced by deployment packages.

See `UPDATE_v1.3.0.txt` for the Generic Rentals change summary, `UPDATE_v1.3.5.txt` for deployment hardening, and `UPDATE_v1.3.6.txt` for flexible rental cancellation/refunds.


## v1.3.6 Rental Cancellation
Admin can cancel an upcoming rental even when payments already exist, choose the rental-payment refund amount, and keep the remainder as TLH's retained cancellation value. Refunds are recorded as negative rental-payment ledger transactions. Security Deposits remain separate.
