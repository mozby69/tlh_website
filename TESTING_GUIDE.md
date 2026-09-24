# v1.2.132 Experience the Hub Checks

1. Open the public homepage on desktop and confirm **Simple Reservations / How It Works** is gone.
2. Confirm **Experience the Hub** appears directly after the Basketball / Volleyball / Events booking cards.
3. Confirm the large volleyball photo, two supporting action photos, and the full-court panorama all load sharply.
4. Hover the action cards on desktop and confirm the image movement/reflection remains subtle and does not obscure text.
5. Open the homepage at 320px, 360px, 390px, and 430px widths. Confirm the three action stories swipe horizontally without causing page-level horizontal scrolling.
6. Confirm **Check Availability** opens the Reservation Calendar and **Explore Venue** opens the venue page.
7. Enable Reduce Motion in the OS/browser and confirm the section remains fully visible without reveal/sweep animation.
8. If testing the installed PWA, refresh after accepting the new app update so the v1.2.132 cache and new WebP photos are active.

# v1.2.78 Admin Reference Scheduler Checks

1. Open Admin → New Reservation. Confirm the booking-type step shows only the three large choices: Basketball Court, Volleyball Court, and Event / Venue.
2. Click a booking type. Confirm it advances directly to the schedule screen.
3. Confirm the schedule screen uses the two-pane layout: activity/calendar on the left, duration/time on the right.
4. Change 1/2/3/4-hour quick durations and More duration options. Confirm calendar availability refreshes.
5. Confirm dates with no valid time are dimmed/disabled and selectable dates load only available time cards.
6. Select a time card. Confirm it highlights and Continue becomes available.
7. For Event / Venue, change Setup and Cleanup and confirm availability refreshes.
8. Turn on Past Date Selection. Confirm previous months/dates can be selected; turn it off and confirm past dates are blocked again.
9. Continue to Details and confirm source/status, complimentary add-ons, discount, payment, and Reference / OR fields are still present.
10. Create a dummy reservation and confirm the final server-side conflict check, pricing, payment, and saved schedule behave normally.

# TLH v1.2.71 LAN Production Testing Guide

Use a separate local test copy. Do not test destructive actions against the live database.

## 1. Create a safe XAMPP test copy

1. In phpMyAdmin, export your current `leisure_hub` database as a backup.
2. Create a new database named `leisure_hub_test`.
3. Import a copy of the current TLH database into `leisure_hub_test`. This gives you realistic test data without changing the live records.
4. Extract this website build to a separate folder such as:
   `C:\xampp\htdocs\tlh_test`
5. Copy `tlh_test\config\local.example.php` to `tlh_test\config\local.php`.
6. In `config\local.php`, set `db_name` to `leisure_hub_test` and enter the MySQL username/password used by the test server.
7. Start Apache and MySQL in XAMPP.
8. Open `http://localhost/tlh_test/admin/login.php` and sign in with an admin account from the copied database.

## 2. Prevent an old PWA cache from confusing the test

After installing a new TLH version, use one of these methods:

- Test in a new Incognito/Private browser window; or
- Open DevTools > Application > Service Workers > Unregister, then hard-refresh; or
- Clear site data for `localhost` and reload.

The package should report version `1.2.71` in `VERSION.txt`.

## 3. Test Late Extension

Use an Administrator account for the historical-overlap test.

### A. Late extension with no conflict
1. Create or use an **Approved** reservation that has already ended but is not archived. A historical test reservation is easiest.
2. Open Reservation Details. **Late Extension** should appear even though normal Edit/Reschedule actions are locked.
3. Open **Late Extension** and add 30 minutes or 1 hour.
4. Confirm the preview uses the reservation's saved venue hourly rate. If the reservation has a regular hourly Equipment Bundle, confirm that saved hourly equipment rate is also added. Shower Room and other one-time add-ons must not be charged again.
5. Enter a reason such as `Client informed us late that they used one additional hour.` and save.
6. If the revised end time is still in the past, the reservation should be **Completed**. The payable total and balance should increase by exactly the extension charge.
7. Open **Reservation History > Extension History**. Confirm a **Late Extension** badge appears and the audit text records when the late extension was entered and the original end time.
8. If this is a batch occurrence, open the batch and confirm the occurrence remains in the same batch and the batch total increased correctly.

### B. Late extension that reaches into future time
1. Use a reservation whose original end time has just passed.
2. Add enough time that the new end is still in the future.
3. Save with no conflicting booking.
4. Expected: status becomes **Approved** again and the extended schedule/cleanup period holds the calendar until it finishes.

### C. Future conflict must remain blocked
1. Prepare another secured reservation that overlaps the future portion of the proposed extension or its moved cleanup period.
2. Try the late extension.
3. Expected: the page reports an extension conflict and does **not** allow the save. There is no historical override for time that has not fully passed.

### D. Historical overlap acknowledgement
1. In the test database, prepare two completed/secured historical reservations so extending the first one would overlap the second in already-passed time.
2. Open Late Extension as an **Administrator**.
3. Expected: TLH shows **Historical overlap detected** and reveals **Record Historical Overlap**.
4. Leave the acknowledgement unchecked and submit. Expected: server-side validation refuses the save.
5. Check the acknowledgement and save. Expected: the late extension succeeds.
6. Open Extension History. Confirm **Historical Overlap Acknowledged** appears and the audit text lists the conflicting reservation reference(s) and the administrator.
7. Repeat as a **Staff** user. Expected: a historical overlap cannot be overridden.

PASS if late extensions can accurately record past extra usage, future conflicts are never overridden, and historical overlap overrides require an Administrator acknowledgement with audit history.

## 4. Quick smoke test

Before testing special cases, confirm these pages open without a PHP error:

- Admin Dashboard
- Reservations
- Batch Groups
- Booking Calendar
- Payments & Collections
- Rates Settings
- Reports
- Public Reserve page
- Public Track Reservation page

Create one temporary unpaid reservation, open it, edit it, print it, then delete it. This confirms the basic create/read/update/delete path works.


## 5. Test the Past Date Selection control

### Single Reservation
1. Open **New Reservation**. Confirm **Enable Past Date Selection** is unchecked by default.
2. Open the Reservation Date picker. Dates before today should be unavailable.
3. Try today's date. Start times that have already passed should not be selectable in the normal UI.
4. Check **Enable Past Date Selection**. Past dates should immediately become selectable.
5. Create one historical test reservation. If its approved schedule has already ended, it should save as **Completed** and use pricing applicable to the historical reservation date.
6. Return to New Reservation. The option should be unchecked again by default; enabling it is per-entry and is not permanently remembered.

### Batch Reservation / Add Missing Dates
1. Open **Batch Reservation**. Confirm historical dates are blocked while the option is unchecked.
2. Test both **Recurring Schedule** and **Specific Dates**; neither should allow a date before today while historical entry is off.
3. Check **Enable Past Date Selection**. Past recurring ranges and specific dates should become selectable.
4. Create a small mixed historical/future batch and verify past approved occurrences become Completed while future occurrences keep their selected status.
5. Open an existing batch and choose **Add Missing / Past Dates**. Past dates must remain unavailable until you check **Enable Past Date Selection** on that screen.
6. Uncheck the option after selecting a historical date. TLH should restore today's minimum and clear any selected date earlier than today.

PASS if historical entry is impossible by default and works normally only after the admin explicitly enables the control.

## 6. Test flexible discount and Final Amount synchronization

1. Create a future single reservation with a calculated total of `1,500`.
2. Apply a `500` discount. Expected payable: `1,000`.
3. Open Reservation Details > Update Status / Final Amount.
4. Change Final Amount to `1,200` and save.
5. Expected Reservation Summary:
   - Calculated Total: `1,500`
   - Discount: `300`
   - Client Payable: `1,200`
6. Print the reservation. The printout should show the same values.
7. Optional: clear Final Amount and save. Expected: TLH returns to the calculated total and removes the flexible discount.

PASS if the displayed/stored discount always equals Calculated Total minus Client Payable whenever the final amount is lower.

## 7. Test “Keep Current Payable Total” during reschedule

1. Create a future reservation with a discount, for example gross `3,200`, discount `500`, payable `2,700`.
2. Reschedule it to a longer duration so the recalculated gross is higher.
3. Select **Keep Current Payable Total**.
4. The preview must show:
   - the new calculated gross for the new schedule;
   - an automatically recalculated effective discount/adjustment;
   - Client Payable still exactly `2,700`.
5. Save and reopen the reservation.

PASS if the gross and discount change as needed but the payable stays fixed and the summary remains mathematically consistent.

## 8. Test introductory-rate locking

Default rates use an introductory period through Oct 31, 2026 unless Rates Settings were changed.

1. Create a regular Fan booking on a date inside the introductory period, such as Oct 30, 2026.
2. Confirm the saved venue rate is the introductory rate.
3. Reschedule it to Nov 2, 2026.
4. With **Keep Original Booked Rate**, the original introductory hourly rate must remain.
5. Repeat or preview using **Apply Rate for the New Date**. The regular post-introductory rate should be used instead.
6. Repeat with one occurrence inside a batch. The occurrence must remain in the same batch and default to **Keep Original Batch Occurrence Rate**.

## 9. Test payment eligibility consistency

Use temporary test reservations.

### Approved / Completed
A secured Approved or Completed reservation with a balance should offer payment from the appropriate standard payment screens.

### Rejected
Mark a reservation Rejected. It must not offer a standard payment action from Reservations, Calendar, or Payments & Collections.

### No Show
Mark a reservation No Show. It must not offer a standard payment action.

### Lapsed Pending / For Review
Create a past Pending or For Review reservation. It should show **Needs Resolution** and payment collection must be blocked until the outcome is resolved.

### Cancelled
Cancel a reservation that still has a cancellation balance. It must not appear as a normal collection item in Payments & Collections or Calendar quick payment. Open Reservation Details instead; the contextual **Record Cancellation Payment** action should remain available.

## 10. Test Add Missing Dates on a batch

1. Create a normal batch for one client.
2. Open the batch and select **Add Missing / Past Dates**.
3. Confirm these fields are locked to the existing batch:
   - Client/contact details
   - Reservation type
   - Guest/package basis
   - Cooling option
   - Purpose
   - Setup/cleanup terms for events
   - Shower Room and complimentary state
   - Equipment Bundle and complimentary state
4. Enter only the missing schedule dates/times and create them.
5. Reopen the batch.

PASS if the new occurrences use the same client and commercial terms, remain under the same batch reference, and the occurrence numbering/date range/totals refresh correctly.

## 11. Test batch “Fully Paid” wording with an excluded balance

1. Create an unpaid batch with at least two occurrences.
2. Cancel one occurrence so it has an outstanding cancellation charge.
3. Pay all still-eligible active occurrences using Batch Payment.
4. Open the Batch page.

Expected: it must **not** say Fully Paid while the cancelled occurrence still has a balance. It should say **No Eligible Batch Balance · Review Individual Balances**.

5. Open the cancelled occurrence and settle its cancellation balance.
6. Return to the batch.

Expected: the batch can now show **Fully Paid** once the overall balance is zero.

## 12. Test protected Delete actions

- Create an unpaid temporary single reservation and delete it: deletion should succeed for an Administrator.
- Create a reservation, record any payment, then try to delete it: deletion must be blocked.
- Create an unpaid test batch and delete the entire batch: deletion should succeed if no payment history exists.
- Create a batch with payment history and try to delete it: deletion must be blocked.

## 13. Test recent pricing features

For both single and batch reservations:

- Shower Room normal fee
- Complimentary Shower Room = access retained, fee `0`
- Equipment Bundle on Regular booking = hourly rate × billable hours
- Complimentary Equipment Bundle = access retained, fee `0`
- Tournament / Big Event Equipment Bundle = Included
- Flexible discount updates Client Payable live
- Payment status and remaining balance use the discounted payable, not the gross total

## 14. LAN production checks

1. Confirm `install.php`, `database.sql`, `leisure_hub.sql`, and old `.sql` backup files are **not** present in the website folder.
2. Confirm `config/local.php` contains the LAN server's intended MySQL credentials and is not shared with users.
3. Open Admin > Database Backup, create a test backup, download it, then confirm the generated SQL file is stored outside the Apache document root (the default location is a sibling private `tlh-private/backups` folder).
4. In phpMyAdmin or MySQL, run `SELECT NOW(), CURDATE();` and compare it with the Admin Dashboard time/date; both should reflect Philippine time.
5. Export a report containing a temporary client/purpose beginning with `=`, `+`, `-`, or `@`; opening the CSV/XLS in Excel should display it as text rather than execute it as a formula.
6. Visit Contact and Leasing once while online, disconnect the LAN client from the server, and refresh. The service worker must not show a stale cached form.
7. From another device on the same LAN, open `http://<server-ip>/<tlh-folder>/` and test public pages plus Admin login. HTTPS is intentionally not required for this LAN release.
8. Confirm the router has no port-forwarding rule exposing the TLH Apache server to the public internet.

PASS if TLH works across the trusted LAN, sensitive deployment files are absent, backups stay private, and database/PHP dates agree.

## 15. What to send when something fails

Send:

1. The page name.
2. The reservation or batch reference.
3. What values you entered.
4. What you expected.
5. What TLH actually displayed or saved.
6. A screenshot if the issue is visual.

For financial issues, include the Calculated Total, Discount, Client Payable, Paid, and Balance values shown on screen.


## 16. Test compact Next 7 Days dashboard

1. Create or use test data with at least 5 secured reservations on the same future date within the next 7 days.
2. Open the Admin Dashboard.
3. The **Next 7 Days** panel should show only the first 3 reservation previews for that day.
4. A `+ 2 more bookings` link should appear underneath; click it and confirm the Reservations page opens filtered to that date.
5. Click **Open Calendar** and confirm the Booking Calendar opens normally.
6. If the week contains many busy days, the Coming Up panel should stay bounded instead of making the entire Dashboard excessively tall; its contents can scroll inside the panel.

PASS if the total weekly count and each day's booking count remain correct while only three previews per day are rendered.

## v1.2.73 — Compact Visual Scheduler

1. Open Public **Reserve** and Admin **New Reservation**.
2. Confirm the booking calendar is visible but there is no large time-card grid.
3. Pick a date, then choose **Duration** from the dropdown.
4. Open **Select Time** and confirm available times are selectable while Booked/Past/Beyond-closing entries are disabled.
5. Select an available time and confirm the small schedule summary and live availability message update.
6. In Admin, turn on **Enable Past Date Selection** and confirm historical calendar dates/time options become available where no conflict exists.
7. Complete a test booking and confirm pricing/conflict validation remains unchanged.


## v1.2.74 — Simplified Progressive Booking Flow

1. Open Public **Reserve**. Confirm the first screen shows only three booking-type choices: Basketball, Volleyball, and Event / Venue.
2. Select each booking type and confirm the selected card is highlighted; go to Schedule and confirm the choice is preserved.
3. On Schedule, confirm the page shows the calendar plus only **Duration** and **Select Time** controls, the small selected-schedule summary, and live availability.
4. Confirm **Select Time** disables Booked, Past, and Beyond-closing options and that Continue stays disabled until an available time is selected.
5. For Event / Venue, confirm Setup Time and Cleanup Time appear in the Schedule step and changing either one refreshes availability.
6. Continue to Details. Confirm Expected Guests, Cooling, package/price preview, client information, purpose, add-ons, and requests appear here rather than on the Schedule step.
7. Continue to Payment & Review and submit a test request. Confirm the saved schedule, package, estimated total, add-ons, and payment intention match the selections.
8. Open Admin **New Reservation** and repeat the flow. Confirm **Enable Past Date Selection** appears on Schedule, and source/status, complimentary add-ons, discount, payment/OR, and payment date remain available in later steps.
9. Turn on Past Date Selection in Admin and confirm historical dates/times can be selected where no conflict exists; turn it off and confirm past dates are blocked again.
10. Submit one normal Admin reservation and one historical Admin reservation and confirm all existing conflict, pricing, payment, and status rules are unchanged.

PASS if the booking form feels progressively disclosed, the Schedule step remains compact, and all existing backend rules remain authoritative.


## v1.2.75 — Public Booking Modal Test

1. Open `reserve.php`. The booking wizard should open automatically as a centered modal.
2. Close the modal using the X button, the dark backdrop, and the Escape key. Each method should return to the simple Reserve landing panel.
3. Click **Start Reservation** to reopen the modal.
4. Complete all four steps. Moving between steps should scroll the modal content to the top without scrolling the page behind it.
5. Verify the Schedule step still loads Duration and Select Time dropdowns and disables booked/unavailable times.
6. On a phone-sized browser, verify the booking modal fills the screen and only the modal content scrolls.
7. Submit an intentionally invalid form and confirm validation/errors remain visible inside the open modal.
8. Verify Admin → New Reservation still uses the normal full-page four-step workflow and is not converted into a modal.


## v1.2.76 — Straightforward Client Availability Test

1. Open Public **Reserve** and go to the Schedule step. Confirm the layout is a simple single column: Duration → Calendar → Available Time.
2. Confirm there is no Available/Limited/Fully Booked legend and no grid of individual time cards.
3. With a known secured reservation in the month, choose a duration that leaves no valid window on a test date. That date should be dimmed and not clickable.
4. Change Duration and confirm the calendar rechecks the month; dates may enable or disable based on whether at least one complete window fits.
5. Select an enabled date. Open **Select an available time** and confirm the dropdown contains only selectable ranges such as `4:00 PM – 6:00 PM`; it must not list Booked/Past/Beyond-closing rows.
6. Confirm the helper text reports how many available times remain for the selected date.
7. For **Event / Venue**, change Setup Time and Cleanup Time and confirm both the calendar and time dropdown refresh.
8. Select a time and confirm the live status says **Available to request** and Continue becomes enabled.
9. Complete and submit a test request. Confirm the server still rejects a schedule if another secured reservation takes it between the live check and final submission.
10. Verify Admin → New Reservation still works with its existing staff controls and historical-date option.

PASS if clients only see dates/times they can act on, while TLH keeps all existing server-side conflict and approval rules.


## v1.2.77 — Minimal Reference-Style Public Booking Modal

1. Open `reserve.php`. Confirm there is no booking-page launcher, modal toolbar title, progress bar, or Step X heading.
2. Confirm the modal first shows only Basketball Court, Volleyball Court, and Event / Venue choices plus the close button.
3. Click each booking type and confirm it advances directly to the schedule screen and the icon/title update correctly.
4. Confirm the schedule screen uses two panes on desktop: type/calendar on the left and duration/time choices on the right.
5. Change duration using 1, 2, 3, and 4 hour buttons. Confirm the calendar availability refreshes.
6. Use More and choose a 30-minute increment such as 1.5 hours. Confirm availability refreshes and the end time is derived correctly.
7. Confirm dates with no valid slot are dimmed/disabled and cannot be selected.
8. Select an enabled date. Confirm only available time choices appear.
9. Select a time card. Confirm it becomes selected, Continue becomes usable after the live check, and the calculated schedule/end time is preserved into review.
10. For Event / Venue, change Setup and Cleanup. Confirm calendar/time availability refreshes.
11. Continue through Details and Payment/Review. Confirm there are no Step X headings and submission still works normally.
12. On mobile, confirm the two-pane scheduler stacks cleanly with no horizontal scrolling.
13. Click the close X. From an internal referring page it should return there; opening `reserve.php` directly should fall back to Home.
14. Confirm Admin New Reservation remains unchanged.

No database migration is required for v1.2.77.


## v1.2.79 — Optional Email Regression
1. Submit a public reservation with the Email field blank; it must succeed when all other required fields are valid.
2. Submit with an invalid non-empty email; validation must reject it.
3. Track the no-email reservation using its reference number and saved mobile number.
4. Print the no-email reservation from the tracking result.
5. Confirm an email-backed reservation can still be tracked using reference + email.

## v1.2.80 Reservation-First Landing Page

1. Open the public homepage on desktop and confirm the real Leisure Hub court photo fills the hero background.
2. Confirm Basketball Court, Volleyball Court, and Events Venue appear as the three dominant clickable image cards.
3. Click Basketball Court and confirm the booking modal opens directly on the schedule screen with Basketball Court selected.
4. Repeat for Volleyball Court and Events Venue and confirm the selected type is preserved.
5. Open Reserve Now from the main navigation and confirm the normal booking-type selection screen still appears.
6. Confirm Reservation Calendar and Track Reservation remain available as secondary homepage links.
7. Resize to tablet and mobile widths and confirm the reservation cards stack cleanly without horizontal scrolling.
8. Confirm the Admin Portal and Admin New Reservation page are unchanged.

## v1.2.81 Professional Landing Page Refinement

Validate the public homepage at desktop, tablet, and phone widths. Confirm the three primary reservation cards remain the dominant calls to action and that Basketball, Volleyball, and Events Venue each open `reserve.php` with the correct `type` query parameter. Check that Reservation Calendar and Track Reservation remain accessible from the hero, the three-step process is readable, the venue overview/aerial image loads, and the mobile navigation remains usable. No database migration is required.

## v1.2.82 Minimal Hero Copy

1. Open the public homepage and confirm the hero shows **THE LEISURE HUB.**
2. Confirm the second line reads **Designed for Premium Experience.**
3. Confirm the hero contains one primary **Book Now!** button and no extra hero paragraph or utility links.
4. Click **Book Now!** and confirm the normal public booking flow opens.
5. Confirm the three Basketball Court, Volleyball Court, and Events Venue reservation cards remain directly below the hero copy and still open the correct booking type.
6. Check desktop and mobile widths for clean wrapping and no horizontal overflow.

No database migration is required.


## v1.2.83 Compact Premium Hero

1. Open the public homepage on a desktop-size browser.
2. Confirm `THE LEISURE HUB.` is the dominant hero line.
3. Confirm `Designed for Premium Experience.` is visibly smaller and stays on one line when normal desktop width allows.
4. Confirm the `Book Now!` button remains easy to see but no longer dominates the hero.
5. Confirm the Basketball, Volleyball, and Events booking cards appear higher in the first viewport than in v1.2.82.
6. Check tablet and mobile widths and confirm the supporting line wraps naturally without horizontal overflow.
7. Click each reservation card and confirm the correct booking type still opens.

## v1.2.122 Native Experience QA

1. Public Reserve: verify the 4-step progress header reads Court → Schedule → Details → Review and Back preserves prior selections.
2. Public Reserve: choose a date/time, continue to Details/Review, and confirm the compact booking summary shows the correct type, date, time, and duration. Tap Edit and confirm it returns to Schedule.
3. Availability: switch the “Book as” selector between Basketball, Volleyball, and Events; confirm all secured venue blocks remain visible because the physical venue calendar is shared, while Reserve links preserve the selected booking type.
4. Availability on a phone: tap a future date; confirm the date is highlighted and its unavailable time blocks appear below before the Reserve CTA.
5. Submit a test website request; confirm the submit button changes to “Submitting…” and cannot be double-submitted.
6. Booking Success: confirm reference copy works, the reservation summary is correct, and Track Reservation opens the matching record without putting private contact data in the URL.
7. Track Reservation: verify the Submitted → For Review → Approved → Payment → Completed timeline matches pending, for-review, approved, paid/partial, and completed test records.
8. Admin mobile: verify Dashboard, Reservations, Calendar, Payments/forms, notifications, and the fixed bottom tab bar remain usable at 320, 360, 390, 430, and 760 px widths.
9. PWA: verify first-launch splash remains one-time only, then reload/reopen and confirm v1.2.122 static assets update from the new service-worker cache.
10. Conflict safety: verify public submit, Admin Reschedule, and Admin Extend still perform authoritative server-side conflict checks even when live availability requests fail.

## v1.2.124 - Compact native booking spacing
1. In the installed iPhone/Android PWA, open Reserve and confirm Step 1 content begins shortly below the progress header instead of being vertically centered in the viewport.
2. Select a court, date, and open the available-time screen. Confirm Back to dates, heading, venue card, and available-time note begin compactly below the progress header with no large blank band.
3. Confirm the iPhone notch/Dynamic Island safe area is still respected.
4. Confirm the date screen Back button remains reachable and does not overlap the calendar content.
5. Confirm the sticky Continue button still works while scrolling long time-slot lists.

## v1.2.125 — Installed PWA Reservation Calendar safe area
1. Install/open the app from the iPhone Home Screen on a device with a notch or Dynamic Island.
2. Open **Calendar** from the bottom navigation.
3. Confirm the previous-month button, `RESERVATION CALENDAR`, month/year title, and next-month button all sit fully below the iOS status bar/Dynamic Island.
4. Confirm the normal mobile-browser Calendar remains compact and does not receive unnecessary extra top padding.

## v1.2.126 - Admin Portal Mobile Optimization Audit

Test the Admin Portal at 320, 360, 390, 430, 760, and 900 px widths, plus an installed iPhone PWA with a notch/Dynamic Island.

- Dashboard: action cards, today's schedule, buttons, and bottom tabs fit without horizontal scrolling.
- Reservations / Needs Resolution / Cancelled / Archives: every row becomes a readable mobile card and all actions remain reachable.
- Batch Reservations: occurrence list, payment history, specific-date rows, live availability, and batch payment dialogs fit on one-column mobile layouts.
- Booking Calendar: month controls respect safe areas; mobile reservation list and reservation detail sheet remain reachable above the bottom tabs.
- Payment & Collections: filters collapse cleanly, metrics remain legible, finance blocks do not overflow, and action buttons are touch sized.
- Reports: report selector scrolls horizontally, filters stack, summaries remain readable, and result rows become labeled cards instead of a wide scrolling table.
- Notifications / Inquiries / Announcements: content wraps, status controls remain touch friendly, and notification popovers stay within the usable viewport.
- Users: password reset and account actions remain usable without horizontal scrolling.
- Tenants / Gallery / Hero Slides: media cards collapse cleanly and edit/delete controls remain reachable.
- Settings / Rates / Backup: forms are single-column, inputs do not trigger iOS focus zoom, backup rows become labeled cards, and destructive actions remain clearly separated.
- Reservation View / Edit / Reschedule / Extend: finance summaries, collapsible sections, payment history, action menus, and dialogs fit the phone viewport.
- Admin Login: safe-area padding is respected and inputs use a 16 px mobile font size to prevent iOS zoom.
- More drawer: sidebar can scroll independently, respects top/bottom safe areas, and can be closed without the bottom tabs intercepting taps.
- Confirm no admin page produces document-level horizontal scrolling.

### v1.2.134 Mobile Hero CTA
- On <=720px, confirm BOOK NOW! renders as the green split-action button with dark arrow tile.
- Confirm it scrolls to Book Your Experience Now! and does not overlap hero copy on 320-430px widths.
- With Reduce Motion enabled, confirm the one-time sheen does not animate.


## Calendar Viewer checks (v1.2.137)
1. Create a user with role **Calendar Viewer (Read Only)** from Admin > Users.
2. Sign in with that account and confirm the landing page is Booking Calendar.
3. Confirm only Calendar Access / View Website / Sign out are available in the side menu.
4. Open a calendar reservation and confirm details are visible but status, payment, edit, print, reschedule, extend, cancel, and full-reservation actions are unavailable.
5. Manually browse to `admin/index.php`, `admin/reservations.php`, `admin/payments.php`, and `admin/users.php`; each must return to Booking Calendar.
6. Confirm Administrator and Staff accounts retain their existing access.


## v1.2.138 User deletion checks
1. Sign in as an Administrator and open Admin > Users.
2. Confirm other user rows show Disable/Enable and Delete actions.
3. Confirm the current signed-in account shows no Disable/Delete controls.
4. Cancel the Delete confirmation and verify the account remains.
5. Delete a test Staff or Calendar Viewer account and verify it disappears from Users.
6. Verify the deleted account cannot sign in and the old username can be reused for a new account.
7. Verify reservations/payments previously handled by the deleted account remain intact and keep historical attribution.


## v1.2.139 Cancellation refund ledger checks
1. Fully pay a future reservation, then cancel it. Confirm the page shows Client Paid, 50% Cancellation Charge, Refund to Client, and Net Retained After Refund.
2. Choose **No — Refund Later**. Confirm the reservation becomes Cancelled with Refund Pending and no negative payment is created yet.
3. Open Cancellation Settlement, record the pending refund, and confirm Payment History contains the original positive payment plus a negative Refund transaction.
4. Confirm Gross Payments stays unchanged, Refunds increases, and Net Collected equals Gross Payments minus Refunds.
5. Repeat with a partially paid reservation above the 50% charge; only the excess above the cancellation charge should be refundable.
6. Repeat with payment below the 50% charge; no refund should be offered and the remaining cancellation balance should stay due.

## v1.2.140 Password-confirmed permanent delete checks
1. Open an Administrator-created reservation with no payment and choose **More > Delete reservation permanently**. Confirm the delete page appears and requires the current Administrator password.
2. Enter an incorrect password. Confirm nothing is deleted and an error message is shown.
3. Open a fully paid reservation and note its payment amount in Payments/Reports. Delete it using the correct Administrator password. Confirm the reservation and its payment history disappear and the corresponding Sales/Collections totals decrease by the deleted ledger amount.
4. Delete one paid occurrence from a batch with payments shared across multiple dates. Confirm the other occurrences and their payments remain intact and the batch totals/payment master are recalculated.
5. Confirm a non-Administrator account cannot access the permanent-delete route.
