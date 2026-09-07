TLH OFFICIAL WEBSITE


## v1.2.132 — Experience the Hub Visual Showcase

- Replaced the public homepage **Simple Reservations / How It Works** block with a photo-led **Experience the Hub** section using four authentic Leisure Hub court images.
- Desktop uses an editorial mosaic: one dominant volleyball story, two supporting training/action stories, and a full-width court panorama.
- Mobile uses a compact horizontal swipe rail for the three action stories, followed by the full-court feature panel.
- Added **Check Availability** and **Explore Venue** calls to action directly on the panorama.
- Added restrained scroll-in reveals, desktop hover image movement, and a soft reflection sweep; all motion respects **Reduce Motion**.
- Optimized the supplied PNG photography into lightweight WebP assets and included them in the PWA static cache.
- No reservation logic, database schema, booking cards, admin workflows, or desktop/mobile navigation behavior was changed. PWA cache advanced to v1.2.132.

## v1.2.78 — Reference-Style Admin New Reservation Scheduler

- Admin → New Reservation now uses the same clean reference-style two-pane scheduler as the public booking modal.
- Admin booking type cards are simplified to Basketball, Volleyball, and Event / Venue and advance directly to schedule selection.
- Schedule selection shows the activity/calendar on the left and duration/available time cards on the right.
- Monthly availability disables dates with no usable time for the selected duration; only actually available start times are shown.
- Past Date Selection remains available to staff as a compact admin-only control above the scheduler.
- Event Setup/Cleanup remain available and automatically refresh date/time availability.
- Existing admin-only pricing, complimentary add-ons, flexible discount, payment, OR/reference, source/status, and server-side locking/conflict rules are unchanged.
- No database migration required. PWA cache advanced to v1.2.78.

## v1.2.79 — Optional Client Email
- Public reservation email is now optional.
- When supplied, email is still validated normally.
- Public tracking and client printing now accept the reservation reference plus either the saved email address or saved mobile number, so clients without email can still access their reservation.
- Admin reservation email remains optional.
- No database migration required.


## v1.2.77 — Reference-Style Minimal Public Booking Modal

- Reworked the public booking modal to closely follow the clean two-pane scheduler reference: booking type selection first, then court/calendar on the left and duration/available times on the right.
- Removed the public modal toolbar, step-progress bar, step headings, explanatory page launcher, availability legend, and other redundant booking copy.
- Booking type cards now advance directly to the scheduler.
- Available dates remain database-driven: dates with no valid slot for the selected duration are disabled.
- Available start times are rendered as simple selectable time cards; unavailable times are not shown.
- Common 1–4 hour durations use quick buttons, with a compact More selector retaining all supported 30-minute duration increments.
- Restored explicit schedule derivation on the public scheduler so the hidden end time stays synchronized with duration/start-time selections for live pricing/review.
- Public modal close returns to the previous same-site page (or Home when opened directly).
- Admin New Reservation is unchanged. No database migration is required. PWA cache advanced to v1.2.77.

## v1.2.76 — Straightforward Availability-First Booking

- Simplified the public booking modal so the client follows one clear path: Booking Type → Date & Time → Details → Payment & Review.
- The public Schedule step is now a single-column layout: choose duration, choose an enabled calendar date, then choose an available time.
- Added a privacy-safe monthly availability endpoint that checks the whole visible month efficiently with one reservation-window query. Dates with no valid time for the selected duration are dimmed and disabled.
- The Select Time dropdown now lists only actual available time ranges, for example `4:00 PM – 6:00 PM`; booked, past, and beyond-closing options are no longer mixed into the client list.
- Event setup/cleanup still refresh both date and time availability because those blocking periods affect conflicts.
- Public availability wording is reduced to `Available to request`; final schedule confirmation still occurs only after Admin approval and authoritative submission checks.
- Simplified the booking-type cards and removed the redundant View Availability action from the Reserve landing panel.
- Admin New Reservation keeps its current full staff workflow. No database migration is required. Advanced the PWA cache version to v1.2.76.

## v1.2.75 — Public Booking Modal

- Public Reserve is now presented as a centered pop-up booking modal instead of occupying the entire page.
- Visiting Reserve opens the guided four-step booking form automatically; closing it returns the visitor to a simple reservation landing panel with Start Reservation and View Availability actions.
- The modal keeps Booking Type → Schedule → Details → Payment & Review, including the compact calendar, Duration dropdown, Select Time dropdown, live availability, pricing, and payment-intention rules.
- The modal scrolls internally on long steps, becomes full-screen on phones, supports Escape/backdrop/close controls, traps keyboard focus while open, and restores focus when closed.
- Admin New Reservation remains a full-page workflow so staff-only controls are not cramped into a modal.
- No database migration is required. Advanced the PWA cache version to v1.2.75.

## v1.2.74 — Simplified Progressive Booking Flow

- Simplified both **Public Reserve** and **Admin New Reservation** into four focused stages: Booking Type → Schedule → Details → Payment & Review.
- Added large Basketball / Volleyball / Event selection cards so users choose what they are booking before seeing schedule or pricing controls.
- Kept the compact visual calendar, Duration dropdown, and Select Time dropdown while moving guest count, cooling, package pricing, and add-ons into the Details step.
- Event setup/cleanup remain in the Schedule step because those blocks affect real-time availability.
- Admin keeps **Enable Past Date Selection**, source/status, complimentary add-ons, discounts, payment date/reference, and all existing staff-only controls.
- Fixed schedule derivation so the calendar date can live outside the schedule-control container while the hidden end time still updates correctly.
- Updated step navigation and live-availability button locking for the new four-step flow.
- No database migration is required. Advanced the PWA cache version to v1.2.74.

## v1.2.71 — Late Extension

- Added **Late Extension** for approved/completed reservations whose original booked end time has already passed.
- Uses the reservation's saved venue hourly rate and saved hourly Equipment Bundle rate; one-time add-ons are not charged again and existing discounts/payments remain attached.
- If the revised event end is still historical, the reservation is stored as **Completed**. If the late extension reaches back into future time, it is reopened as **Approved** so the remaining schedule is protected.
- Normal and late extension conflict checks now analyze only the newly added blocking time beyond the reservation's existing cleanup block.
- Future overlaps remain a hard block. Fully historical overlaps can be recorded only by an **Administrator** after checking **Record Historical Overlap**; the acknowledgement, conflicting reservation references, administrator, and timestamp are written to Extension History.
- Completed reservations continue to hold the venue until their cleanup period ends, preventing a late extension from creating a cleanup-window double booking.
- Late Extension is exposed from Reservation Details, Reservations, and the Booking Calendar, including the correct action label.
- Batch occurrences remain in the same batch and the batch payable total is automatically resynchronized after a late extension.
- No database migration is required. Advanced the PWA cache version to v1.2.71.

## v1.2.70 — LAN Production Release

- Prepared TLH for trusted local-network deployment while intentionally keeping normal HTTP available and leaving brute-force/spam rate limiting disabled for now.
- Removed the bundled installer, schema seed, real database dump, old SQL backup, and development lint artifact from the deployable website package.
- Added `config/local.example.php` plus environment-variable overrides so LAN database credentials can be kept separate from the shared application code; no database username/password is baked into the production package.
- Pins each MySQL connection to `+08:00` so `NOW()` / `CURDATE()` agree with PHP `Asia/Manila` reporting.
- Database Backup now defaults to a private folder outside the Apache document root; `TLH_BACKUP_DIR` / `backup_dir` can override that location.
- Hardened CSV/XLS report text against spreadsheet formula injection without changing numeric report fields.
- Contact and Leasing form pages are no longer cached by the PWA service worker because their CSRF/session state must stay live.
- Admin pages now send no-store/noindex directives; writable upload folders use `0755` instead of `0777`.
- Future payment/refund dates remain allowed by design. Existing reservation, batch, discount, payment, reschedule, delete, historical-date, and dashboard behavior is unchanged.
- No database migration is required. Advanced the PWA cache version to v1.2.70.

## v1.2.69 — Admin-Controlled Past Date Entry

- Changed historical reservation entry from always-on to an explicit **Enable Past Date Selection** admin control.
- New Reservation now blocks past dates/times by default in both the browser and the authoritative server-side create handler.
- Batch Reservation and **Add Missing Dates** now use the same opt-in rule for recurring ranges and specific-date rows.
- Live single and batch availability checks follow the toggle, so historical schedules are not previewed unless the administrator intentionally enables them.
- Turning the option back off restores today's minimum date and clears currently selected dates that are earlier than today.
- Historical pricing, auto-completion of already-ended approved occurrences, discounts, add-ons, payments, and batch linkage continue to work exactly as before once historical entry is enabled.
- No database migration is required.
- Advanced the PWA cache version to v1.2.69.

## v1.2.68 — Compact Upcoming Dashboard

- Compacted the admin Dashboard **Next 7 Days** panel so busy weeks no longer dominate the page.
- Shows up to 3 reservation previews per day, followed by a `+ N more bookings` link to that date's filtered reservation list.
- Added an **Open Calendar** action in the panel header.
- Added a bounded internal scroll area as a safeguard for unusually busy weeks while keeping the full weekly and per-day booking counts visible.
- Advanced the PWA cache version to v1.2.68.

## v1.2.67 — Financial Consistency & Batch Safeguards

- Synchronized manually entered Final Amount values with Flexible Discount so Reservation Summary and printouts cannot show stale discount math.
- Updated “Keep Current Payable Total” rescheduling to recalculate the new gross price and derive the effective discount/adjustment while preserving the same client payable.
- Centralized reservation payment eligibility across Payments & Collections, Reservation Details, Reservation list, and Calendar quick payment; cancellation settlement remains available only on contextual cancellation-aware screens.
- Locked client identity and core commercial terms when adding missing dates to an existing batch, preventing accidental mixed-client or mixed-package batch records.
- Corrected batch payment actions so “Fully Paid” appears only when the entire batch balance is zero; excluded individual balances now prompt review instead.
- Advanced the PWA cache version to v1.2.67.

## v1.2.66 — Protected Delete Actions

- Added administrator-only permanent Delete actions for individual Reservations and Batch Reservations.
- Delete actions are POST-only, CSRF-protected, and require an explicit browser confirmation.
- Reservations with recorded payments or batch-payment scope history are protected from hard deletion so financial audit history is not lost.
- Batch deletion is blocked when the batch or any connected occurrence has payment history.
- Deleting an eligible reservation from a batch automatically rebuilds the batch date range, totals, count, and occurrence numbering.
- Deleting a whole eligible batch removes all connected reservation dates and their non-financial child history in one transaction.
- The last remaining occurrence in a batch cannot be deleted individually; administrators must delete the batch itself.
- Advanced the PWA cache version to v1.2.66.

## v1.2.81 — Professional Landing Page Refinement

- Refined the reservation-first homepage into a more premium, restrained venue presentation.
- Kept Basketball Court, Volleyball Court, and Events Venue as the three dominant booking paths.
- Reworked typography, spacing, overlays, navigation, card treatment, CTA hierarchy, and mobile behavior.
- Replaced decorative emoji booking icons with clean line icons.
- Added a concise three-step reservation process strip and a professional venue overview using the existing aerial venue image.
- Preserved direct booking links so each large menu opens the correct reservation scheduler.
- No database migration required.
- Advanced the PWA cache version to v1.2.81.

## v1.2.80 — Reservation-First Landing Page

- Rebuilt the public homepage around three large reservation choices: Basketball Court, Volleyball Court, and Events Venue.
- Added a dark sports-focused visual system using the actual Leisure Hub court as the hero background.
- Added large image-driven reservation tiles with direct links into the existing public booking modal.
- Landing-page reservation links now preselect the requested booking type and open directly on the schedule step.
- Added a compact homepage navigation focused on Venue, Sports, Events, Gallery, Contact, and Reserve Now while leaving the full navigation unchanged on other public pages.
- Kept Reservation Calendar and Track Reservation as secondary utilities so they do not compete with the three main booking choices.
- No database migration is required.
- Advanced the PWA cache version to v1.2.80.

## v1.2.82 — Minimal Premium Hero Copy

- Simplified the public homepage hero to the requested brand message: **THE LEISURE HUB. Designed for Premium Experience.**
- Added one clear **Book Now!** call to action that opens the existing public reservation flow.
- Removed the extra hero eyebrow, explanatory paragraph, Reservation Calendar link, and Track Reservation link from the hero so the three reservation cards remain the next visual focus.
- Existing Basketball, Volleyball, Events, booking, pricing, availability, and Admin behavior is unchanged.
- No database migration is required. Advanced the PWA cache version to v1.2.82.


## v1.2.83 — Compact Premium Hero

- Reduced the landing-page hero typography so the reservation cards appear sooner in the first viewport.
- Kept the requested copy: `THE LEISURE HUB.`, `Designed for Premium Experience.`, and `Book Now!`.
- Made the brand name the primary headline and the premium-experience line a smaller supporting headline.
- Reduced hero spacing and CTA height while preserving the professional reservation-first layout.
- No database migration is required. Advanced the PWA cache version to v1.2.83.

### v1.2.126 - Full Admin Portal mobile optimization
- Completed a mobile audit across all Admin Portal screens.
- Converted remaining wide admin tables to labeled mobile cards, including Reports, Users, Inquiries, Announcements, Archives, Backup, payment histories, and batch histories.
- Hardened iPhone/PWA safe-area handling for admin chrome, menu drawer, calendar, notification popovers, dialogs, and bottom navigation.
- Standardized mobile forms, touch targets, panel spacing, operational actions, reports, payments, reservation detail/edit flows, batch workflows, and content-management cards.
- Kept desktop layouts unchanged.

### v1.2.134 - Premium Mobile Hero CTA
- Refined the mobile-only BOOK NOW! action into a premium split-action control.
- Added stronger typography, a dark arrow tile, tactile press feedback, accessible focus styling, and one restrained reflective sweep.
- Desktop/tablet hero layouts remain unchanged.
