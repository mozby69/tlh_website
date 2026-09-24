/*
 * THE LEISURE HUB - CLIENT-SIDE BEHAVIOR
 *
 * DEBUGGING MAP:
 * - Public/admin navigation and accessibility live near the top of this file.
 * - Reservation schedule, pricing, payment controls, and stepper logic follow.
 * - Shared confirmations/modals and notification refresh logic are later sections.
 * - Live single-reservation availability and flexible Batch Reservation workflows
 *   are near the bottom.
 *
 * Keep authoritative validation on the PHP server. JavaScript previews/checks are
 * for responsiveness and user guidance; they must never be the only protection
 * against double-booking, invalid payments, or incorrect pricing.
 */

document.addEventListener('DOMContentLoaded', () => {
  // PUBLIC HEADER: adds the compact/scrolled state after the page moves.
  const header = document.querySelector('.home-page .site-header');
  const updateHeader = () => header?.classList.toggle('scrolled', window.scrollY > 24);
  updateHeader();
  window.addEventListener('scroll', updateHeader, { passive: true });

  const toggle = document.querySelector('.nav-toggle');
  const nav = document.querySelector('.site-nav');
  toggle?.addEventListener('click', () => {
    const open = nav?.classList.toggle('open') ?? false;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  // ADMIN SIDEBAR: desktop collapse + mobile off-canvas behavior, keyboard
  // focus trapping, Escape handling, and localStorage preference persistence.
  const adminNavigationShell = document.querySelector('[data-admin-shell]');
  if (adminNavigationShell) {
    const adminNavigationSidebar = adminNavigationShell.querySelector('[data-admin-sidebar]');
    const adminNavigationToggles = Array.from(document.querySelectorAll('[data-admin-menu-toggle]'));
    const adminNavigationClosers = Array.from(document.querySelectorAll('[data-admin-menu-close]'));
    const adminNavigationMobile = window.matchMedia('(max-width: 900px)');
    const adminNavigationStorageKey = 'tlhAdminMenuCollapsed';
    let adminNavigationCollapsed = false;
    let adminNavigationLastToggle = adminNavigationToggles[0] || null;

    try {
      adminNavigationCollapsed = localStorage.getItem(adminNavigationStorageKey) === '1';
    } catch (error) {
      adminNavigationCollapsed = document.documentElement.classList.contains('admin-menu-precollapsed');
    }

    const adminNavigationFocusable = () => {
      if (!adminNavigationSidebar) return [];
      return Array.from(adminNavigationSidebar.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'))
        .filter((element) => !element.hidden && element.getAttribute('aria-hidden') !== 'true');
    };

    const syncAdminNavigationAccessibility = () => {
      if (!adminNavigationSidebar) return;
      const isMobile = adminNavigationMobile.matches;
      const isOpen = adminNavigationShell.classList.contains('is-menu-open');
      if (isMobile) {
        adminNavigationSidebar.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
        if ('inert' in adminNavigationSidebar) adminNavigationSidebar.inert = !isOpen;
      } else {
        adminNavigationSidebar.setAttribute('aria-hidden', 'false');
        if ('inert' in adminNavigationSidebar) adminNavigationSidebar.inert = false;
      }
    };

    const syncAdminNavigationToggles = () => {
      const isMobile = adminNavigationMobile.matches;
      const expanded = isMobile
        ? adminNavigationShell.classList.contains('is-menu-open')
        : !adminNavigationShell.classList.contains('is-menu-collapsed');
      const label = isMobile
        ? (expanded ? 'Close menu' : 'Open menu')
        : (expanded ? 'Collapse menu' : 'Expand menu');

      adminNavigationToggles.forEach((button) => {
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        button.setAttribute('aria-label', label);
        button.title = label;
      });
      syncAdminNavigationAccessibility();
    };

    const closeAdminNavigationMobile = (restoreFocus = false) => {
      const wasOpen = adminNavigationShell.classList.contains('is-menu-open');
      adminNavigationShell.classList.remove('is-menu-open');
      document.body.classList.remove('admin-menu-open');
      syncAdminNavigationToggles();
      if (restoreFocus && wasOpen) adminNavigationLastToggle?.focus();
    };

    const applyAdminNavigationViewport = () => {
      document.documentElement.classList.remove('admin-menu-precollapsed');
      closeAdminNavigationMobile(false);
      if (adminNavigationMobile.matches) {
        adminNavigationShell.classList.remove('is-menu-collapsed');
      } else {
        adminNavigationShell.classList.toggle('is-menu-collapsed', adminNavigationCollapsed);
      }
      syncAdminNavigationToggles();
    };

    adminNavigationToggles.forEach((button) => {
      button.addEventListener('click', () => {
        adminNavigationLastToggle = button;
        if (adminNavigationMobile.matches) {
          const willOpen = !adminNavigationShell.classList.contains('is-menu-open');
          adminNavigationShell.classList.toggle('is-menu-open', willOpen);
          document.body.classList.toggle('admin-menu-open', willOpen);
          syncAdminNavigationToggles();
          if (willOpen) {
            window.requestAnimationFrame(() => {
              const closeButton = adminNavigationSidebar?.querySelector('.admin-sidebar-close');
              (closeButton || adminNavigationFocusable()[0])?.focus();
            });
          }
          return;
        }

        adminNavigationCollapsed = !adminNavigationShell.classList.contains('is-menu-collapsed');
        adminNavigationShell.classList.toggle('is-menu-collapsed', adminNavigationCollapsed);
        try {
          localStorage.setItem(adminNavigationStorageKey, adminNavigationCollapsed ? '1' : '0');
        } catch (error) {
          // The menu remains usable when browser storage is unavailable.
        }
        syncAdminNavigationToggles();
      });
    });

    adminNavigationClosers.forEach((button) => {
      button.addEventListener('click', () => closeAdminNavigationMobile(true));
    });

    adminNavigationSidebar?.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        if (adminNavigationMobile.matches) closeAdminNavigationMobile(false);
      });
    });

    document.addEventListener('keydown', (event) => {
      if (!adminNavigationMobile.matches || !adminNavigationShell.classList.contains('is-menu-open')) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeAdminNavigationMobile(true);
        return;
      }
      if (event.key !== 'Tab') return;

      const focusable = adminNavigationFocusable();
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    if (typeof adminNavigationMobile.addEventListener === 'function') {
      adminNavigationMobile.addEventListener('change', applyAdminNavigationViewport);
    } else if (typeof adminNavigationMobile.addListener === 'function') {
      adminNavigationMobile.addListener(applyAdminNavigationViewport);
    }
    applyAdminNavigationViewport();
  }

  // RESERVATION TYPE FIELDS: event-only setup/cleanup fields and sport-purpose
  // fields share this toggle on both public and admin forms.
  const typeSelect = document.querySelector('#reservation_type');
  const eventFields = document.querySelectorAll('[data-event-fields]');
  const sportFields = document.querySelectorAll('[data-sport-fields]');
  const updateTypeFields = () => {
    const isEvent = typeSelect?.value === 'event';
    eventFields.forEach((field) => field.classList.toggle('hidden', !isEvent));
    sportFields.forEach((field) => field.classList.toggle('hidden', isEvent));
  };
  typeSelect?.addEventListener('change', updateTypeFields);
  updateTypeFields();


  // BOOKING TYPE PICKER: large, simple cards on the first step keep the native
  // select as the source of truth so all existing PHP/JS logic remains intact.
  document.querySelectorAll('[data-booking-type-picker]').forEach((picker) => {
    const form = picker.closest('form');
    const select = form?.querySelector('#reservation_type');
    const cards = Array.from(picker.querySelectorAll('[data-booking-type-option]'));
    if (!select || !cards.length) return;

    const syncCards = () => {
      cards.forEach((card) => {
        const active = card.dataset.bookingTypeOption === select.value;
        card.classList.toggle('is-selected', active);
        card.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    };

    const syncSchedulerSummary = () => {
      const summary = form?.querySelector('[data-scheduler-type-summary]');
      if (!summary) return;
      const icon = summary.querySelector('[data-scheduler-type-icon]');
      const title = summary.querySelector('[data-scheduler-type-title]');
      const note = summary.querySelector('[data-scheduler-type-note]');
      const labels = {
        basketball: ['🏀', 'Basketball Court', 'Reserve the full indoor court for games, practice, training, and tournaments.'],
        volleyball: ['🏐', 'Volleyball Court', 'Indoor volleyball schedules for training sessions, matches, and competitive play.'],
        event: ['★', 'Event / Venue', 'Flexible venue reservations for private functions, sports events, and gatherings.'],
      };
      const current = labels[select.value] || labels.basketball;
      if (icon) icon.textContent = current[0];
      if (title) title.textContent = current[1];
      if (note) note.textContent = current[2];
      const mobileTimeIcon = form?.querySelector('[data-mobile-time-icon]');
      const mobileTimeVenue = form?.querySelector('[data-mobile-time-venue]');
      const mobileTimeVenueNote = form?.querySelector('[data-mobile-time-venue-note]');
      if (mobileTimeIcon) mobileTimeIcon.textContent = current[0];
      if (mobileTimeVenue) mobileTimeVenue.textContent = select.value === 'event' ? 'Event Venue' : 'Whole Court';
      if (mobileTimeVenueNote) mobileTimeVenueNote.textContent = current[1];
    };

    cards.forEach((card) => {
      card.addEventListener('click', () => {
        const value = card.dataset.bookingTypeOption || '';
        if (!value) return;
        if (select.value !== value) {
          select.value = value;
          select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        syncCards();
        syncSchedulerSummary();
        if (picker.hasAttribute('data-auto-advance-booking-type')) {
          window.setTimeout(() => picker.closest('[data-form-step]')?.querySelector('[data-step-next]')?.click(), 0);
        }
      });
    });

    select.addEventListener('change', () => {
      syncCards();
      syncSchedulerSummary();
    });
    syncCards();
    syncSchedulerSummary();
  });

  // REFERENCE-STYLE DURATION PICKER: common durations are quick buttons while
  // the native duration select remains the source of truth for all PHP/JS rules.
  document.querySelectorAll('[data-reference-duration-picker]').forEach((picker) => {
    const select = picker.querySelector('[data-duration-hours]');
    const buttons = Array.from(picker.querySelectorAll('[data-reference-duration]'));
    const more = picker.querySelector('[data-reference-duration-more]');
    if (!select) return;

    const sync = () => {
      const selected = String(select.value || '');
      let matchedCommon = false;
      buttons.forEach((button) => {
        const active = String(button.dataset.referenceDuration || '') === selected;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
        if (active) matchedCommon = true;
      });
      if (more) more.value = matchedCommon ? '' : selected;
    };

    buttons.forEach((button) => button.addEventListener('click', () => {
      const value = String(button.dataset.referenceDuration || '');
      if (!value || select.value === value) {
        sync();
        return;
      }
      select.value = value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      sync();
    }));
    more?.addEventListener('change', () => {
      if (!more.value) return;
      select.value = more.value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      sync();
    });
    select.addEventListener('change', sync);
    sync();
  });

  // SCHEDULE DERIVATION: duration drives the hidden end_time field. The PHP
  // server recalculates/validates this again before saving.
  document.querySelectorAll('[data-reservation-schedule]').forEach((schedule) => {
    const dateInput = schedule.querySelector('[data-reservation-date]') || schedule.closest('form')?.querySelector('[data-reservation-date]');
    const startSelect = schedule.querySelector('[data-start-time]');
    const endInput = schedule.closest('form')?.querySelector('[data-end-time]') || schedule.querySelector('[data-end-time]');
    const durationSelect = schedule.querySelector('[data-duration-hours]');
    if (!dateInput || !startSelect || !endInput) return;

    const minutesFor = (value) => {
      const [hours, minutes] = String(value || '').split(':').map(Number);
      return (hours * 60) + minutes;
    };
    const timeForMinutes = (minutes) => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
    const readableTime = (value) => {
      const [hours, minutes] = String(value || '').split(':').map(Number);
      if (!Number.isFinite(hours) || !Number.isFinite(minutes)) return '';
      const suffix = hours >= 12 ? 'PM' : 'AM';
      const displayHour = hours % 12 || 12;
      return `${displayHour}:${String(minutes).padStart(2, '0')} ${suffix}`;
    };

    const updatePastStartTimes = () => {
      if (!schedule.hasAttribute('data-future-only')) {
        Array.from(startSelect.options).forEach((option) => { option.disabled = false; });
        return;
      }
      const today = new Date();
      const localToday = [
        today.getFullYear(),
        String(today.getMonth() + 1).padStart(2, '0'),
        String(today.getDate()).padStart(2, '0'),
      ].join('-');
      const cutoff = (today.getHours() * 60) + today.getMinutes();
      let firstAvailable = null;

      Array.from(startSelect.options).forEach((option) => {
        const isPast = dateInput.value === localToday && minutesFor(option.value) <= cutoff;
        option.disabled = isPast;
        if (!isPast && firstAvailable === null) firstAvailable = option.value;
      });

      if (startSelect.selectedOptions[0]?.disabled && firstAvailable !== null) {
        startSelect.value = firstAvailable;
      }
    };

    const updateDuration = () => {
      const startMinutes = minutesFor(startSelect.value);
      const closingMinutes = 22 * 60;
      if (!durationSelect) return;
      let firstValid = null;
      Array.from(durationSelect.options).forEach((option) => {
        const duration = Number.parseFloat(option.value);
        const invalid = !Number.isFinite(startMinutes) || startMinutes + (duration * 60) > closingMinutes;
        option.disabled = invalid;
        if (!invalid && firstValid === null) firstValid = option.value;
      });
      if (durationSelect.selectedOptions[0]?.disabled && firstValid !== null) {
        durationSelect.value = firstValid;
      }
      const durationHours = Number.parseFloat(durationSelect.value);
      const endMinutes = startMinutes + (durationHours * 60);
      endInput.value = Number.isFinite(endMinutes) && endMinutes <= closingMinutes ? timeForMinutes(endMinutes) : '';
      const endLabel = schedule.querySelector('[data-schedule-end-label]');
      if (endLabel) {
        endLabel.textContent = endInput.value
          ? `Ends at ${readableTime(endInput.value)}.`
          : 'Choose a duration that ends by 10:00 PM.';
      }
      endInput.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const updateEndTimes = () => {
      if (durationSelect || endInput.tagName !== 'SELECT') {
        updateDuration();
        return;
      }
      const startMinutes = minutesFor(startSelect.value);
      let firstValid = null;
      let preferred = null;

      Array.from(endInput.options).forEach((option) => {
        const optionMinutes = minutesFor(option.value);
        const invalid = optionMinutes <= startMinutes;
        option.disabled = invalid;
        if (!invalid && firstValid === null) firstValid = option.value;
        if (!invalid && optionMinutes === startMinutes + 120) preferred = option.value;
      });

      const selected = endInput.selectedOptions[0];
      if (!selected || selected.disabled) {
        endInput.value = preferred || firstValid || '';
      }
    };

    const refreshSchedule = () => {
      updatePastStartTimes();
      updateEndTimes();
    };

    dateInput.addEventListener('change', refreshSchedule);
    startSelect.addEventListener('change', updateEndTimes);
    durationSelect?.addEventListener('change', updateDuration);
    refreshSchedule();
  });

  document.querySelectorAll('[data-pricing-form]').forEach((form) => {
    const dateInput = form.querySelector('[data-reservation-date]');
    const startSelect = form.querySelector('[data-start-time]');
    const endInput = form.querySelector('[data-end-time]');
    const durationSelect = form.querySelector('[data-duration-hours]');
    const guestInput = form.querySelector('[data-guest-count]');
    const coolingSelect = form.querySelector('[data-cooling-option]');
    const showerInput = form.querySelector('[data-shower-room]');
    const showerComplimentaryInput = form.querySelector('[data-shower-room-complimentary]');
    const equipmentInput = form.querySelector('[data-equipment-bundle]');
    const equipmentComplimentaryInput = form.querySelector('[data-equipment-bundle-complimentary]');
    const equipmentLabel = form.querySelector('[data-equipment-bundle-label]');
    const summary = form.querySelector('[data-pricing-summary]');
    if (!dateInput || !startSelect || !endInput || !guestInput || !coolingSelect) return;

    let config = {};
    try {
      config = JSON.parse(form.dataset.pricingConfig || '{}');
    } catch (error) {
      config = {};
    }

    const packageOutput = form.querySelector('[data-price-package]');
    const hourlyOutput = form.querySelector('[data-price-hourly]');
    const hoursOutput = form.querySelector('[data-price-hours]');
    const addonsOutput = form.querySelector('[data-price-addons]');
    const totalOutput = form.querySelector('[data-price-total]');
    const messageOutput = form.querySelector('[data-price-message]');
    const existingDiscountRow = form.querySelector('[data-price-existing-discount-row]');
    const existingDiscountOutput = form.querySelector('[data-price-existing-discount]');
    const clientPayableRow = form.querySelector('[data-price-client-payable-row]');
    const clientPayableOutput = form.querySelector('[data-price-client-payable]');
    const storedExistingDiscount = Math.max(0, Number(form.dataset.existingDiscount || 0));
    const reviewTotal = form.querySelector('[data-review-total]');
    const reviewSchedule = form.querySelector('[data-review-schedule]');
    const reviewType = form.querySelector('[data-review-type]');
    const reviewPackage = form.querySelector('[data-review-package]');
    const reviewCooling = form.querySelector('[data-review-cooling]');
    const reviewAddons = form.querySelector('[data-review-addons]');
    const reviewBaseChargeLabel = form.querySelector('[data-review-base-charge-label]');
    const reviewBaseChargeDetail = form.querySelector('[data-review-base-charge-detail]');
    const reviewBaseChargeAmount = form.querySelector('[data-review-base-charge-amount]');
    const reviewShowerChargeRow = form.querySelector('[data-review-shower-charge-row]');
    const reviewShowerChargeAmount = form.querySelector('[data-review-shower-charge-amount]');
    const reviewShowerChargeDetail = form.querySelector('[data-review-shower-charge-detail]');
    const reviewEquipmentChargeRow = form.querySelector('[data-review-equipment-charge-row]');
    const reviewEquipmentChargeDetail = form.querySelector('[data-review-equipment-charge-detail]');
    const reviewEquipmentChargeAmount = form.querySelector('[data-review-equipment-charge-amount]');
    const reviewChargeTotal = form.querySelector('[data-review-charge-total]');
    const reviewInclusions = form.querySelector('[data-review-inclusions]');
    const typeSelectForReview = form.querySelector('#reservation_type');
    const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    const minutesFor = (value) => {
      const [hours, minutes] = String(value || '').split(':').map(Number);
      return Number.isFinite(hours) && Number.isFinite(minutes) ? (hours * 60) + minutes : NaN;
    };
    const packageForGuests = (guests) => {
      if (guests >= 1 && guests <= 29) return { key: 'regular', label: 'Regular Booking' };
      if (guests >= 30 && guests <= 200) return { key: 'tournament', label: 'Tournament (30–200 guests)' };
      if (guests >= 201 && guests <= 400) return { key: 'big_event', label: 'Big Event (201–400 guests)' };
      return null;
    };
    const asMoney = (value) => currency.format(Number(value || 0));
    const typeLabel = () => ({ basketball: 'Basketball Court', volleyball: 'Volleyball Court', event: 'Events Reservation' }[typeSelectForReview?.value] || 'Reservation');
    const readableDate = (value) => {
      if (!value) return '';
      const date = new Date(`${value}T00:00:00`);
      return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }).format(date);
    };
    const readableTime = (value) => {
      const [hours, minutes] = String(value || '').split(':').map(Number);
      if (!Number.isFinite(hours) || !Number.isFinite(minutes)) return '';
      const suffix = hours >= 12 ? 'PM' : 'AM';
      return `${hours % 12 || 12}:${String(minutes).padStart(2, '0')} ${suffix}`;
    };
    const setText = (element, value) => { if (element) element.textContent = value; };
    const syncExistingDiscount = (grossTotal) => {
      const gross = Number(grossTotal);
      const canApply = storedExistingDiscount > 0.001 && Number.isFinite(gross) && gross > 0.01;
      if (!canApply) {
        if (existingDiscountRow) existingDiscountRow.hidden = true;
        if (clientPayableRow) clientPayableRow.hidden = true;
        setText(existingDiscountOutput, '—');
        setText(clientPayableOutput, '—');
        return { discount: 0, payable: Number.isFinite(gross) ? Math.max(0, gross) : 0 };
      }
      const maximumDiscount = Math.max(0, gross - 0.01);
      const discount = Math.min(storedExistingDiscount, maximumDiscount);
      const payable = Math.max(0, gross - discount);
      if (existingDiscountRow) existingDiscountRow.hidden = discount <= 0.001;
      if (clientPayableRow) clientPayableRow.hidden = discount <= 0.001;
      setText(existingDiscountOutput, `−${asMoney(discount)}`);
      setText(clientPayableOutput, asMoney(payable));
      return { discount, payable };
    };
    const resetChargeBreakdown = (baseLabel = 'Venue Rental', detail = 'Choose a valid schedule and guest count.') => {
      setText(reviewBaseChargeLabel, baseLabel);
      setText(reviewBaseChargeDetail, detail);
      setText(reviewBaseChargeAmount, '—');
      if (reviewShowerChargeRow) reviewShowerChargeRow.hidden = true;
      if (reviewEquipmentChargeRow) reviewEquipmentChargeRow.hidden = true;
      setText(reviewChargeTotal, '—');
    };
    const updateInclusions = (packageInfo, equipmentIncluded) => {
      if (!reviewInclusions || !packageInfo) return;
      const items = ['Exclusive venue use during the reserved schedule', coolingSelect.selectedOptions[0]?.textContent || 'Selected cooling option'];
      if (equipmentIncluded || equipmentInput?.checked) items.push('Shot clocks, scoreboard, controller, and complete sound system');
      if (packageInfo.key !== 'regular') {
        items.push('Venue and social-media advertisement', 'Allocated parking space');
      }
      if (packageInfo.key === 'big_event') items.push('Carpet installation');
      if (showerInput?.checked) items.push('Shower room access');
      reviewInclusions.replaceChildren(...items.map((item) => {
        const li = document.createElement('li');
        li.textContent = item;
        return li;
      }));
    };

    const updatePricing = () => {
      const guests = Number.parseInt(guestInput.value, 10);
      const packageInfo = packageForGuests(guests);
      const startMinutes = minutesFor(startSelect.value);
      const endMinutes = minutesFor(endInput.value);
      const durationMinutes = endMinutes - startMinutes;
      const cooling = coolingSelect.value;
      setText(reviewType, typeLabel());
      setText(reviewCooling, coolingSelect.selectedOptions[0]?.textContent || '—');
      if (dateInput.value && startSelect.value && endInput.value) {
        setText(reviewSchedule, `${readableDate(dateInput.value)}, ${readableTime(startSelect.value)}–${readableTime(endInput.value)}`);
      } else {
        setText(reviewSchedule, 'Choose a date and time');
      }

      if (!packageInfo) {
        const unavailable = guests > 400;
        const packageText = unavailable ? 'Maximum capacity exceeded' : 'Enter a valid guest count';
        setText(packageOutput, packageText);
        setText(reviewPackage, packageText);
        setText(hourlyOutput, '—');
        setText(hoursOutput, '—');
        setText(addonsOutput, '—');
        setText(totalOutput, '—');
        setText(reviewTotal, '—');
        setText(reviewAddons, 'None');
        syncExistingDiscount(null);
        resetChargeBreakdown('Venue Rental', unavailable ? 'The maximum supported capacity is 400 guests.' : 'Enter 1–400 guests to calculate the rate.');
        setText(messageOutput, unavailable ? 'The maximum supported capacity is 400 guests.' : 'Enter 1–400 guests to calculate the rate.');
        summary?.classList.toggle('pricing-error', unavailable);
        form.querySelector('[data-package-preview]')?.classList.toggle('pricing-error', unavailable);
        delete form.dataset.calculatedTotal;
        delete form.dataset.calculatedPackage;
        form.dispatchEvent(new CustomEvent('pricing-updated'));
        return;
      }

      summary?.classList.remove('pricing-error');
      form.querySelector('[data-package-preview]')?.classList.remove('pricing-error');
      const equipmentIncluded = packageInfo.key !== 'regular';
      if (equipmentInput) {
        if (equipmentIncluded && !equipmentInput.disabled) {
          equipmentInput.dataset.previousChecked = equipmentInput.checked ? 'true' : 'false';
          equipmentInput.checked = true;
          equipmentInput.disabled = true;
        } else if (!equipmentIncluded && equipmentInput.disabled) {
          equipmentInput.disabled = false;
          equipmentInput.checked = equipmentInput.dataset.previousChecked === 'true';
        }
      }
      if (equipmentComplimentaryInput) {
        equipmentComplimentaryInput.disabled = equipmentIncluded || !equipmentInput?.checked;
        if (equipmentIncluded || !equipmentInput?.checked) equipmentComplimentaryInput.checked = false;
      }
      if (equipmentLabel) {
        const equipmentStatus = equipmentIncluded
          ? 'Included in this package'
          : `${asMoney(config.equipment_bundle_fee)} per hour`;
        equipmentLabel.textContent = equipmentLabel.tagName === 'SMALL'
          ? equipmentStatus
          : `Shot clocks, scoreboard, controller, and sound system — ${equipmentStatus.toLowerCase()}`;
      }

      setText(packageOutput, packageInfo.label);
      setText(reviewPackage, packageInfo.label);
      if (!dateInput.value || !Number.isFinite(durationMinutes) || durationMinutes <= 0 || durationMinutes % 30 !== 0) {
        setText(hourlyOutput, '—');
        setText(hoursOutput, 'Choose 30-minute increments');
        setText(addonsOutput, '—');
        setText(totalOutput, '—');
        setText(reviewTotal, '—');
        syncExistingDiscount(null);
        resetChargeBreakdown(`${packageInfo.label} — ${coolingSelect.selectedOptions[0]?.textContent || 'Selected cooling option'}`, 'Choose a valid date and 30-minute duration increment.');
        setText(messageOutput, 'Choose a valid date and 30-minute duration increment.');
        delete form.dataset.calculatedTotal;
        form.dispatchEvent(new CustomEvent('pricing-updated'));
        return;
      }

      const hours = durationMinutes / 60;
      let period = packageInfo.key;
      let rateKey;
      if (packageInfo.key === 'regular') {
        period = dateInput.value >= config.intro_start && dateInput.value <= config.intro_end ? 'introductory' : 'original';
        rateKey = `${period === 'introductory' ? 'intro' : 'regular'}_${cooling}_rate`;
      } else if (packageInfo.key === 'tournament') {
        rateKey = `tournament_${cooling}_rate`;
      } else {
        rateKey = `big_event_${cooling}_rate`;
      }

      const hourlyRate = Number(config[rateKey] || 0);
      const baseAmount = hourlyRate * hours;
      if (showerComplimentaryInput) {
        showerComplimentaryInput.disabled = !showerInput?.checked;
        if (!showerInput?.checked) showerComplimentaryInput.checked = false;
      }
      const showerComplimentary = Boolean(showerInput?.checked && showerComplimentaryInput?.checked);
      const equipmentComplimentary = Boolean(!equipmentIncluded && equipmentInput?.checked && equipmentComplimentaryInput?.checked);
      const showerFee = showerInput?.checked && !showerComplimentary ? Number(config.shower_room_fee || 0) : 0;
      const equipmentHourlyRate = Number(config.equipment_bundle_fee || 0);
      const equipmentFee = (!equipmentIncluded && equipmentInput?.checked && !equipmentComplimentary) ? equipmentHourlyRate * hours : 0;
      const total = baseAmount + showerFee + equipmentFee;
      const addonParts = [];
      if (showerInput?.checked) addonParts.push(showerComplimentary ? 'Shower complimentary' : `Shower ${asMoney(showerFee)}`);
      if (equipmentIncluded) addonParts.push('Equipment included');
      else if (equipmentInput?.checked) addonParts.push(equipmentComplimentary ? 'Equipment complimentary' : `Equipment ${asMoney(equipmentFee)}`);
      const addonsText = addonParts.length ? addonParts.join(' + ') : 'None';

      setText(hourlyOutput, `${asMoney(hourlyRate)} / hour`);
      setText(hoursOutput, hours === 0.5 ? '30 minutes' : `${hours} hour${hours === 1 ? '' : 's'}`);
      setText(addonsOutput, addonsText);
      setText(reviewAddons, addonsText);
      setText(totalOutput, asMoney(total));
      setText(reviewTotal, asMoney(total));
      const preservedDiscount = syncExistingDiscount(total);

      const coolingLabel = coolingSelect.selectedOptions[0]?.textContent || 'Selected cooling option';
      const packageLabel = ({ regular: 'Regular Booking', tournament: 'Tournament', big_event: 'Big Event' })[packageInfo.key] || packageInfo.label;
      const regularRateLabel = packageInfo.key === 'regular'
        ? `${period === 'introductory' ? 'Introductory' : 'Original'} rate · `
        : '';
      setText(reviewBaseChargeLabel, `${packageLabel} — ${coolingLabel}`);
      setText(reviewBaseChargeDetail, `${regularRateLabel}${hours === 0.5 ? '30 minutes' : `${hours} hour${hours === 1 ? '' : 's'}`} × ${asMoney(hourlyRate)} per hour`);
      setText(reviewBaseChargeAmount, asMoney(baseAmount));

      if (reviewShowerChargeRow) reviewShowerChargeRow.hidden = !showerInput?.checked;
      setText(reviewShowerChargeDetail, showerComplimentary ? 'Complimentary — fee waived by admin' : 'One-time fee per reservation');
      setText(reviewShowerChargeAmount, showerComplimentary ? 'Complimentary' : asMoney(showerFee));

      if (reviewEquipmentChargeRow) reviewEquipmentChargeRow.hidden = equipmentIncluded || !equipmentInput?.checked;
      setText(reviewEquipmentChargeDetail, equipmentComplimentary ? 'Complimentary — fee waived by admin' : `${hours === 0.5 ? '30 minutes' : `${hours} hour${hours === 1 ? '' : 's'}`} × ${asMoney(equipmentHourlyRate)} per hour`);
      setText(reviewEquipmentChargeAmount, equipmentComplimentary ? 'Complimentary' : asMoney(equipmentFee));
      setText(reviewChargeTotal, asMoney(total));

      if (packageInfo.key === 'regular') {
        setText(messageOutput, `${period === 'introductory' ? 'Introductory' : 'Original'} regular rate applies.${preservedDiscount.discount > 0 ? ` Existing discount of ${asMoney(preservedDiscount.discount)} is preserved; client payable is ${asMoney(preservedDiscount.payable)}.` : ''}`);
      } else if (packageInfo.key === 'tournament') {
        setText(messageOutput, `Tournament package and inclusions applied automatically.${preservedDiscount.discount > 0 ? ` Existing discount of ${asMoney(preservedDiscount.discount)} is preserved; client payable is ${asMoney(preservedDiscount.payable)}.` : ''}`);
      } else {
        setText(messageOutput, `Big Event package and inclusions applied automatically.${preservedDiscount.discount > 0 ? ` Existing discount of ${asMoney(preservedDiscount.discount)} is preserved; client payable is ${asMoney(preservedDiscount.payable)}.` : ''}`);
      }
      form.dataset.calculatedTotal = String(total);
      form.dataset.calculatedPackage = packageInfo.key;
      updateInclusions(packageInfo, equipmentIncluded);
      form.dispatchEvent(new CustomEvent('pricing-updated'));
    };

    [dateInput, startSelect, endInput, durationSelect, guestInput, coolingSelect, showerInput, showerComplimentaryInput, equipmentInput, equipmentComplimentaryInput, typeSelectForReview]
      .filter(Boolean)
      .forEach((element) => {
        element.addEventListener('change', updatePricing);
        element.addEventListener('input', updatePricing);
      });
    updatePricing();
  });


  // MOBILE DURATION PICKER: mirrors the native duration control on the date
  // screen so the phone flow can be Date -> Time without duplicating state.
  document.querySelectorAll('[data-mobile-duration-picker]').forEach((picker) => {
    const form = picker.closest('form');
    const select = form?.querySelector('[data-duration-hours]');
    const buttons = Array.from(picker.querySelectorAll('[data-mobile-duration]'));
    const more = picker.querySelector('[data-mobile-duration-more]');
    if (!select) return;

    const sync = () => {
      const selected = String(select.value || '');
      let matched = false;
      buttons.forEach((button) => {
        const active = String(button.dataset.mobileDuration || '') === selected;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
        if (active) matched = true;
      });
      if (more) more.value = matched ? '' : selected;
    };

    buttons.forEach((button) => button.addEventListener('click', () => {
      select.value = String(button.dataset.mobileDuration || '');
      select.dispatchEvent(new Event('change', { bubbles: true }));
      sync();
    }));
    more?.addEventListener('change', () => {
      if (!more.value) return;
      select.value = more.value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      sync();
    });
    select.addEventListener('change', sync);
    sync();
  });

  document.querySelectorAll('[data-reservation-stepper]').forEach((form) => {
    const panels = Array.from(form.querySelectorAll('[data-form-step]'));
    const indicators = Array.from(form.querySelectorAll('[data-step-indicator]'));
    let currentStep = Math.max(1, Math.min(panels.length, Number(form.dataset.initialStep || 1)));
    const nativeStepCount = form.querySelector('[data-native-step-count]');
    const nativeStepTitle = form.querySelector('[data-native-step-title]');
    const nativeProgressBar = form.querySelector('[data-native-progress-bar]');
    const nativeSummary = form.querySelector('[data-native-booking-selection-summary]');
    const nativeSummaryIcon = form.querySelector('[data-native-summary-icon]');
    const nativeSummaryType = form.querySelector('[data-native-summary-type]');
    const nativeSummarySchedule = form.querySelector('[data-native-summary-schedule]');
    const nativeEditSchedule = form.querySelector('[data-native-edit-schedule]');
    const nativeType = form.querySelector('#reservation_type');
    const nativeDate = form.querySelector('[data-reservation-date]');
    const nativeStart = form.querySelector('[data-start-time]');
    const nativeDuration = form.querySelector('[data-duration-hours]');
    const nativeStepTitles = { 1: 'Choose your booking', 2: 'Choose your schedule', 3: 'Your details', 4: 'Review & submit' };
    const nativeTypeMeta = {
      basketball: ['🏀', 'Basketball Court'],
      volleyball: ['🏐', 'Volleyball Court'],
      event: ['★', 'Event / Venue'],
    };
    const nativeTimeLabel = (value) => {
      const [hourRaw, minuteRaw] = String(value || '').split(':');
      const hour = Number(hourRaw);
      const minute = Number(minuteRaw);
      if (!Number.isFinite(hour) || !Number.isFinite(minute)) return '';
      return `${hour % 12 || 12}:${String(minute).padStart(2, '0')} ${hour >= 12 ? 'PM' : 'AM'}`;
    };
    const syncNativeBookingSummary = () => {
      if (!nativeSummary) return;
      const meta = nativeTypeMeta[nativeType?.value] || nativeTypeMeta.basketball;
      if (nativeSummaryIcon) nativeSummaryIcon.textContent = meta[0];
      if (nativeSummaryType) nativeSummaryType.textContent = meta[1];
      const dateValue = nativeDate?.value || '';
      const startValue = nativeStart?.value || '';
      const durationValue = Number(nativeDuration?.value || 0);
      if (!dateValue || !startValue) {
        if (nativeSummarySchedule) nativeSummarySchedule.textContent = 'Choose your schedule';
        return;
      }
      const date = new Date(`${dateValue}T12:00:00`);
      const dateText = Number.isNaN(date.getTime()) ? dateValue : new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric' }).format(date);
      const durationText = durationValue > 0 ? ` · ${durationValue === 1 ? '1 hr' : `${durationValue} hrs`}` : '';
      if (nativeSummarySchedule) nativeSummarySchedule.textContent = `${dateText} · ${nativeTimeLabel(startValue)}${durationText}`;
    };

    const showStep = (step, shouldScroll = true) => {
      currentStep = Math.max(1, Math.min(panels.length, step));
      form.dataset.currentStep = String(currentStep);
      if (nativeStepCount) nativeStepCount.textContent = `Step ${currentStep} of ${panels.length}`;
      if (nativeStepTitle) nativeStepTitle.textContent = nativeStepTitles[currentStep] || 'Reservation';
      if (nativeProgressBar) nativeProgressBar.style.width = `${(currentStep / panels.length) * 100}%`;
      syncNativeBookingSummary();
      panels.forEach((panel) => {
        const active = Number(panel.dataset.formStep) === currentStep;
        panel.hidden = !active;
        panel.classList.toggle('is-active', active);
      });
      indicators.forEach((indicator) => {
        const number = Number(indicator.dataset.stepIndicator);
        indicator.classList.toggle('is-active', number === currentStep);
        indicator.classList.toggle('is-complete', number < currentStep);
        indicator.setAttribute('aria-current', number === currentStep ? 'step' : 'false');
      });
      if (shouldScroll) {
        const modalScroll = form.closest('[data-public-booking-modal]')?.querySelector('[data-public-booking-scroll]');
        if (modalScroll) modalScroll.scrollTo({ top: 0, behavior: 'smooth' });
        else form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    };

    const validatePanel = (panel) => {
      const fields = Array.from(panel.querySelectorAll('input, select, textarea')).filter((field) => !field.disabled && field.type !== 'hidden');
      for (const field of fields) {
        if (!field.checkValidity()) {
          field.reportValidity();
          field.focus();
          return false;
        }
      }
      return true;
    };

    form.querySelectorAll('[data-step-next]').forEach((button) => button.addEventListener('click', () => {
      const panel = button.closest('[data-form-step]');
      if (panel && validatePanel(panel)) showStep(currentStep + 1);
    }));
    form.querySelectorAll('[data-step-back]').forEach((button) => button.addEventListener('click', () => showStep(currentStep - 1)));
    indicators.forEach((indicator) => indicator.addEventListener('click', () => {
      const target = Number(indicator.dataset.stepIndicator);
      if (target < currentStep) showStep(target);
    }));
    nativeEditSchedule?.addEventListener('click', () => showStep(2));
    [nativeType, nativeDate, nativeStart, nativeDuration].filter(Boolean).forEach((field) => {
      field.addEventListener('change', syncNativeBookingSummary);
    });
    syncNativeBookingSummary();
    showStep(currentStep, false);
  });

  document.querySelectorAll('[data-payment-choice-group]').forEach((group) => {
    const form = group.closest('form');
    const choices = Array.from(group.querySelectorAll('[data-payment-choice]'));
    const fields = form?.querySelector('[data-payment-fields]');
    const method = form?.querySelector('[data-booking-payment-method]');
    const reference = form?.querySelector('[data-booking-payment-reference]');
    const amount = form?.querySelector('[data-payment-amount]');
    const partialField = form?.querySelector('[data-partial-payment-field]');
    const fullNote = form?.querySelector('[data-full-payment-note]');
    const reviewPayment = form?.querySelector('[data-review-payment]');
    const reviewPaid = form?.querySelector('[data-review-paid]');
    const reviewBalance = form?.querySelector('[data-review-balance]');
    const applyDiscount = form?.querySelector('[data-apply-discount]');
    const discountFields = form?.querySelector('[data-discount-fields]');
    const discountInput = form?.querySelector('[data-discount-input]');
    const discountSummary = form?.querySelector('[data-discount-summary]');
    const discountGross = form?.querySelector('[data-discount-gross]');
    const discountAmount = form?.querySelector('[data-discount-amount]');
    const discountNet = form?.querySelector('[data-discount-net]');
    const reviewDiscount = form?.querySelector('[data-review-discount]');
    const reviewNetTotal = form?.querySelector('[data-review-net-total]');
    const reviewHeadlineTotal = form?.querySelector('[data-review-total]');
    const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

    const updatePayment = () => {
      const choice = choices.find((input) => input.checked)?.value || '';
      const hasPayment = choice === 'partial' || choice === 'full';
      if (fields) fields.hidden = !hasPayment;
      fields?.querySelectorAll('input, select').forEach((field) => { field.disabled = !hasPayment; });
      if (method) method.required = hasPayment;
      const isPartial = choice === 'partial';
      if (partialField) partialField.hidden = !isPartial;
      if (amount) {
        amount.disabled = !isPartial;
        amount.required = isPartial;
      }
      if (fullNote) fullNote.hidden = choice !== 'full';
      if (reference && !hasPayment) reference.required = false;

      const grossTotal = Number(form?.dataset.calculatedTotal || 0);
      const discountEnabled = Boolean(applyDiscount?.checked);
      if (discountFields) discountFields.hidden = !discountEnabled;
      discountFields?.querySelectorAll('input').forEach((field) => { field.disabled = !discountEnabled; });
      if (discountInput) {
        discountInput.required = discountEnabled;
        if (grossTotal > 0) discountInput.max = Math.max(0.01, grossTotal - 0.01).toFixed(2);
      }
      const requestedDiscount = Number(discountInput?.value || 0);
      const validDiscount = discountEnabled && grossTotal > 0 && requestedDiscount >= 0.01 && requestedDiscount < grossTotal;
      const discount = validDiscount ? requestedDiscount : 0;
      const total = Math.max(0, grossTotal - discount);
      if (discountSummary) discountSummary.hidden = !discountEnabled || grossTotal <= 0;
      if (discountGross) discountGross.textContent = grossTotal > 0 ? currency.format(grossTotal) : '—';
      if (discountAmount) discountAmount.textContent = grossTotal > 0 ? currency.format(discount) : '—';
      if (discountNet) discountNet.textContent = grossTotal > 0 ? currency.format(total) : '—';
      if (reviewDiscount) reviewDiscount.textContent = currency.format(discount);
      if (reviewNetTotal) reviewNetTotal.textContent = grossTotal > 0 ? currency.format(total) : '—';
      if (reviewHeadlineTotal && grossTotal > 0) reviewHeadlineTotal.textContent = currency.format(total);

      let paid = 0;
      if (choice === 'full') paid = total;
      if (choice === 'partial') paid = Number(amount?.value || 0);
      const balance = Math.max(0, total - paid);
      if (amount && isPartial && total > 0) amount.max = Math.max(0.01, total - 0.01).toFixed(2);
      method?.dispatchEvent(new Event('change', { bubbles: true }));
      if (reviewPayment) reviewPayment.textContent = choice === 'full' ? 'Full payment' : choice === 'partial' ? 'Partial payment' : choice === 'none' ? 'No payment yet' : 'Please select';
      if (reviewPaid) reviewPaid.textContent = currency.format(paid || 0);
      if (reviewBalance) reviewBalance.textContent = total > 0 ? currency.format(balance) : '—';
    };

    choices.forEach((choice) => choice.addEventListener('change', updatePayment));
    amount?.addEventListener('input', updatePayment);
    applyDiscount?.addEventListener('change', updatePayment);
    discountInput?.addEventListener('input', updatePayment);
    form?.addEventListener('pricing-updated', updatePayment);
    updatePayment();
  });

  document.querySelectorAll('[data-batch-payment-form]').forEach((form) => {
    const coverageSelect = form.querySelector('[data-batch-payment-coverage]');
    const scopePanels = Array.from(form.querySelectorAll('[data-batch-payment-scope-panel]'));
    const dateRows = Array.from(form.querySelectorAll('[data-batch-payment-date-row]')).map((element) => ({
      element,
      checkbox: element.querySelector('[data-batch-payment-date-checkbox]'),
      id: String(element.dataset.reservationId || ''),
      date: String(element.dataset.date || ''),
      weekStart: String(element.dataset.weekStart || ''),
      balance: Number(element.dataset.balance || 0),
      dateLabel: String(element.dataset.dateLabel || element.dataset.date || ''),
      timeLabel: String(element.dataset.timeLabel || ''),
      reference: String(element.dataset.reference || ''),
    }));
    const weekSelect = form.querySelector('[data-batch-payment-week]');
    const rangeStart = form.querySelector('[data-batch-payment-range-start]');
    const rangeEnd = form.querySelector('[data-batch-payment-range-end]');
    const singleDate = form.querySelector('[data-batch-payment-single-date]');
    const selectAllButton = form.querySelector('[data-batch-payment-select-all]');
    const amountInput = form.querySelector('[data-batch-payment-amount]');
    const amountHelp = form.querySelector('[data-batch-payment-amount-help]');
    const countOutput = form.querySelector('[data-batch-payment-selected-count]');
    const balanceOutput = form.querySelector('[data-batch-payment-selected-balance]');
    const allocationOutput = form.querySelector('[data-batch-payment-allocation-label]');
    const scopeLabelOutput = form.querySelector('[data-batch-payment-scope-label]');
    const previewList = form.querySelector('[data-batch-payment-scope-preview-list]');
    const submitButton = form.querySelector('[data-batch-payment-submit]');
    if (!coverageSelect || !amountInput || !dateRows.length) return;

    const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    let currentSelection = [];
    let currentBalance = 0;

    const togglePanels = () => {
      const coverage = coverageSelect.value;
      scopePanels.forEach((panel) => {
        const active = panel.dataset.batchPaymentScopePanel === coverage;
        panel.hidden = !active;
        panel.querySelectorAll('input, select, textarea, button').forEach((field) => {
          field.disabled = !active;
        });
      });
    };

    const selectedRows = () => {
      const coverage = coverageSelect.value;
      if (coverage === 'specific_week') {
        return dateRows.filter((row) => row.weekStart === String(weekSelect?.value || ''));
      }
      if (coverage === 'date_range') {
        const start = String(rangeStart?.value || '');
        const end = String(rangeEnd?.value || '');
        if (!start || !end || end < start) return [];
        return dateRows.filter((row) => row.date >= start && row.date <= end);
      }
      if (coverage === 'selected_dates') {
        return dateRows.filter((row) => row.checkbox?.checked);
      }
      if (coverage === 'single_date') {
        return dateRows.filter((row) => row.id === String(singleDate?.value || ''));
      }
      return dateRows;
    };

    const coverageLabel = (rows) => {
      const typeLabel = coverageSelect.selectedOptions[0]?.dataset.shortLabel || coverageSelect.selectedOptions[0]?.textContent || 'Selected coverage';
      if (!rows.length) return typeLabel;
      if (coverageSelect.value === 'specific_week') {
        return weekSelect?.selectedOptions[0]?.textContent?.split(' · ')[0] || typeLabel;
      }
      if (coverageSelect.value === 'single_date') {
        return `${typeLabel}: ${rows[0].dateLabel}`;
      }
      if (coverageSelect.value === 'date_range') {
        return `${typeLabel}: ${rows[0].dateLabel} to ${rows[rows.length - 1].dateLabel}`;
      }
      if (coverageSelect.value === 'selected_dates') {
        return `${rows.length} selected date${rows.length === 1 ? '' : 's'}`;
      }
      return typeLabel;
    };

    const renderPreview = (rows) => {
      if (!previewList) return;
      previewList.replaceChildren();
      if (!rows.length) {
        const item = document.createElement('li');
        item.textContent = 'No outstanding reservation dates match this coverage.';
        previewList.appendChild(item);
        return;
      }
      rows.slice(0, 6).forEach((row) => {
        const item = document.createElement('li');
        item.textContent = `${row.dateLabel} · ${row.reference} · ${currency.format(row.balance)}`;
        previewList.appendChild(item);
      });
      if (rows.length > 6) {
        const item = document.createElement('li');
        item.textContent = `and ${rows.length - 6} more date${rows.length - 6 === 1 ? '' : 's'}`;
        previewList.appendChild(item);
      }
    };

    const updateAllocationLabel = () => {
      const amount = Number(amountInput.value || 0);
      if (!currentSelection.length || currentBalance <= 0) {
        if (allocationOutput) allocationOutput.textContent = 'No dates selected';
        return;
      }
      if (amount >= currentBalance - 0.001) {
        if (allocationOutput) allocationOutput.textContent = 'Pays all selected dates';
      } else {
        if (allocationOutput) allocationOutput.textContent = 'Earliest selected date first';
      }
    };

    const updateSelection = (resetAmount = true) => {
      togglePanels();
      currentSelection = selectedRows();
      currentBalance = currentSelection.reduce((sum, row) => sum + row.balance, 0);
      dateRows.forEach((row) => row.element.classList.toggle('is-in-scope', currentSelection.includes(row)));

      if (countOutput) countOutput.textContent = String(currentSelection.length);
      if (balanceOutput) balanceOutput.textContent = currency.format(currentBalance);
      if (scopeLabelOutput) scopeLabelOutput.textContent = coverageLabel(currentSelection);
      renderPreview(currentSelection);

      amountInput.max = currentBalance > 0 ? currentBalance.toFixed(2) : '0';
      const currentAmount = Number(amountInput.value || 0);
      if (resetAmount || !Number.isFinite(currentAmount) || currentAmount <= 0 || currentAmount > currentBalance) {
        amountInput.value = currentBalance > 0 ? currentBalance.toFixed(2) : '';
      }
      amountInput.disabled = currentBalance <= 0;
      if (amountHelp) {
        amountHelp.textContent = currentBalance > 0
          ? `Maximum for this coverage: ${currency.format(currentBalance)}. Enter a smaller amount for a partial payment.`
          : 'Choose a coverage that contains an outstanding eligible date.';
      }
      if (submitButton) submitButton.disabled = currentSelection.length === 0 || currentBalance <= 0;
      amountInput.setCustomValidity('');
      coverageSelect.setCustomValidity('');
      updateAllocationLabel();
    };

    coverageSelect.addEventListener('change', () => updateSelection(true));
    weekSelect?.addEventListener('change', () => updateSelection(true));
    rangeStart?.addEventListener('change', () => updateSelection(true));
    rangeEnd?.addEventListener('change', () => updateSelection(true));
    singleDate?.addEventListener('change', () => updateSelection(true));
    dateRows.forEach((row) => row.checkbox?.addEventListener('change', () => updateSelection(true)));
    amountInput.addEventListener('input', () => {
      const amount = Number(amountInput.value || 0);
      amountInput.setCustomValidity(amount > currentBalance + 0.001 ? 'The payment cannot exceed the selected coverage balance.' : '');
      updateAllocationLabel();
    });
    selectAllButton?.addEventListener('click', () => {
      const shouldSelectAll = dateRows.some((row) => !row.checkbox?.checked);
      dateRows.forEach((row) => {
        if (row.checkbox) row.checkbox.checked = shouldSelectAll;
      });
      selectAllButton.textContent = shouldSelectAll ? 'Clear selected dates' : 'Select all outstanding dates';
      updateSelection(true);
    });

    form.addEventListener('submit', (event) => {
      updateSelection(false);
      if (!currentSelection.length) {
        event.preventDefault();
        coverageSelect.setCustomValidity('Choose at least one outstanding reservation date.');
        coverageSelect.reportValidity();
        return;
      }
      const amount = Number(amountInput.value || 0);
      if (!Number.isFinite(amount) || amount <= 0 || amount > currentBalance + 0.001) {
        event.preventDefault();
        amountInput.setCustomValidity(amount > currentBalance ? 'The payment cannot exceed the selected coverage balance.' : 'Enter a valid payment amount.');
        amountInput.reportValidity();
      }
    });

    updateSelection(true);
  });

  document.querySelectorAll('[data-booking-payment-method]').forEach((methodSelect) => {
    const form = methodSelect.closest('form');
    const referenceInput = form?.querySelector('[data-booking-payment-reference]');
    const referenceHelp = form?.querySelector('[data-booking-payment-reference-help]');
    const updatePaymentReference = () => {
      const isCash = methodSelect.value === 'Cash';
      const methodDisabled = methodSelect.disabled;
      const allowCashReference = referenceInput?.hasAttribute('data-allow-cash-reference') ?? false;
      const needsReference = methodSelect.value !== '' && !isCash && !methodDisabled;
      if (referenceInput) {
        if (isCash && !allowCashReference) referenceInput.value = '';
        referenceInput.disabled = methodDisabled || (isCash && !allowCashReference);
        referenceInput.required = needsReference;
        referenceInput.setAttribute('aria-required', needsReference ? 'true' : 'false');
        referenceInput.setAttribute('aria-disabled', referenceInput.disabled ? 'true' : 'false');
      }
      if (referenceHelp) {
        referenceHelp.textContent = isCash
          ? (allowCashReference ? 'Optional: enter the OR / official receipt number for cash payments.' : 'Not needed for Cash / Pay at Venue.')
          : needsReference
            ? 'Required: enter the transaction reference or OR number.'
            : 'Select a payment method first.';
      }
    };
    methodSelect.addEventListener('change', updatePaymentReference);
    updatePaymentReference();
  });

  document.querySelectorAll('[data-confirm]').forEach((button) => {
    button.addEventListener('click', (event) => {
      if (!window.confirm(button.dataset.confirm || 'Are you sure?')) event.preventDefault();
    });
  });

  const filters = document.querySelectorAll('[data-gallery-filter]');
  const cards = document.querySelectorAll('[data-gallery-category]');
  filters.forEach((filter) => filter.addEventListener('click', () => {
    filters.forEach((item) => item.classList.remove('active'));
    filter.classList.add('active');
    const value = filter.dataset.galleryFilter;
    cards.forEach((card) => card.classList.toggle('hidden', value !== 'all' && card.dataset.galleryCategory !== value));
  }));

  const storeFilters = document.querySelectorAll('[data-store-filter]');
  const storeCards = document.querySelectorAll('[data-store-card]');
  storeFilters.forEach((filter) => filter.addEventListener('click', () => {
    storeFilters.forEach((item) => item.classList.remove('is-active'));
    filter.classList.add('is-active');
    const value = (filter.dataset.storeFilter || 'all').toLowerCase();
    storeCards.forEach((card) => {
      const category = (card.dataset.storeCategory || '').toLowerCase();
      card.classList.toggle('hidden', value !== 'all' && category !== value);
    });
  }));

  const carousel = document.querySelector('[data-hero-carousel]');
  if (carousel) {
    const slides = Array.from(carousel.querySelectorAll('[data-hero-slide]'));
    const dots = Array.from(carousel.querySelectorAll('[data-hero-dot]'));
    const prev = carousel.querySelector('[data-hero-prev]');
    const next = carousel.querySelector('[data-hero-next]');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let current = 0;
    let timer = null;
    let touchStartX = 0;

    const showSlide = (index, restart = true) => {
      if (!slides.length) return;
      current = (index + slides.length) % slides.length;
      slides.forEach((slide, slideIndex) => {
        const active = slideIndex === current;
        slide.classList.toggle('is-active', active);
        slide.setAttribute('aria-hidden', active ? 'false' : 'true');
      });
      dots.forEach((dot, dotIndex) => {
        const active = dotIndex === current;
        dot.classList.toggle('is-active', active);
        dot.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      if (restart) startAutoPlay();
    };

    const stopAutoPlay = () => {
      if (timer !== null) {
        window.clearInterval(timer);
        timer = null;
      }
    };

    const startAutoPlay = () => {
      stopAutoPlay();
      if (!reduceMotion && slides.length > 1 && !document.hidden) {
        timer = window.setInterval(() => showSlide(current + 1, false), 6500);
      }
    };

    prev?.addEventListener('click', () => showSlide(current - 1));
    next?.addEventListener('click', () => showSlide(current + 1));
    dots.forEach((dot, index) => dot.addEventListener('click', () => showSlide(index)));

    carousel.addEventListener('mouseenter', stopAutoPlay);
    carousel.addEventListener('mouseleave', startAutoPlay);
    carousel.addEventListener('focusin', stopAutoPlay);
    carousel.addEventListener('focusout', startAutoPlay);
    carousel.addEventListener('touchstart', (event) => {
      touchStartX = event.changedTouches[0]?.clientX || 0;
    }, { passive: true });
    carousel.addEventListener('touchend', (event) => {
      const endX = event.changedTouches[0]?.clientX || 0;
      const distance = endX - touchStartX;
      if (Math.abs(distance) > 55) showSlide(distance > 0 ? current - 1 : current + 1);
    }, { passive: true });

    document.addEventListener('visibilitychange', () => document.hidden ? stopAutoPlay() : startAutoPlay());
    startAutoPlay();
  }
});

// ---------------------------------------------------------------------------
// PUBLIC BOOKING FORM MODAL
// The booking wizard stays on reserve.php but is presented as a centered modal.
// Closing it returns the visitor to the simple reservation landing panel.
// ---------------------------------------------------------------------------
(() => {
  const modal = document.querySelector('[data-public-booking-modal]');
  if (!modal) return;

  const dialog = modal.querySelector('.public-booking-dialog');
  const openButtons = document.querySelectorAll('[data-public-booking-open]');
  const closeButtons = modal.querySelectorAll('[data-public-booking-close]');
  const scrollArea = modal.querySelector('[data-public-booking-scroll]');
  let opener = null;

  const focusableElements = () => Array.from(modal.querySelectorAll(
    'button:not([disabled]), a[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled])'
  )).filter((element) => !element.closest('[hidden]'));

  const openModal = (button = null) => {
    opener = button || document.activeElement;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('public-booking-modal-open');
    if (scrollArea) scrollArea.scrollTop = 0;
    window.setTimeout(() => {
      const activePanel = modal.querySelector('[data-form-step].is-active') || modal.querySelector('[data-form-step]:not([hidden])');
      const preferred = activePanel?.querySelector('[data-booking-type-option].is-active, [data-reservation-date], input:not([type="hidden"]), select, button:not([data-public-booking-close])');
      (preferred || modal.querySelector('[data-public-booking-close]'))?.focus();
    }, 0);
  };

  const closeModal = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('public-booking-modal-open');
    if (!openButtons.length) {
      let sameOriginReferrer = false;
      try {
        sameOriginReferrer = Boolean(document.referrer) && new URL(document.referrer).origin === window.location.origin;
      } catch (error) {
        sameOriginReferrer = false;
      }
      if (sameOriginReferrer && window.history.length > 1) window.history.back();
      else window.location.href = 'index.php';
      return;
    }
    opener?.focus?.();
  };

  openButtons.forEach((button) => button.addEventListener('click', () => openModal(button)));
  closeButtons.forEach((button) => button.addEventListener('click', closeModal));

  document.addEventListener('keydown', (event) => {
    if (!modal.classList.contains('is-open')) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeModal();
      return;
    }
    if (event.key !== 'Tab') return;
    const focusable = focusableElements();
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  if (modal.classList.contains('is-open')) {
    document.body.classList.add('public-booking-modal-open');
  }
})();

// ---------------------------------------------------------------------------
// PUBLIC RESERVATION ENTRY NOTICE
// ---------------------------------------------------------------------------
// Public-site notice shown before entering the online reservation form.
(() => {
  const modal = document.querySelector('[data-reserve-notice-modal]');
  if (!modal) return;

  const continueButton = modal.querySelector('[data-reserve-notice-continue]');
  const calendarButton = modal.querySelector('[data-reserve-calendar-cta]');
  const closeButtons = modal.querySelectorAll('[data-reserve-notice-close]');
  let targetUrl = 'reserve.php';
  let opener = null;

  const closeModal = () => {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('reserve-notice-open');
    opener?.focus();
  };

  const openModal = (link) => {
    opener = link;
    targetUrl = link.getAttribute('href') || 'reserve.php';

    // Preserve the chosen booking type when the user checks the calendar first.
    if (calendarButton) {
      const target = new URL(targetUrl, window.location.href);
      const calendarUrl = new URL('availability.php', window.location.href);
      const reservationType = target.searchParams.get('type');
      if (['basketball', 'volleyball', 'event'].includes(reservationType)) {
        calendarUrl.searchParams.set('type', reservationType);
      }
      calendarButton.href = calendarUrl.href;
    }

    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('reserve-notice-open');
    window.setTimeout(() => (calendarButton || continueButton)?.focus(), 0);
  };

  document.querySelectorAll('a[href^="reserve.php"]').forEach((link) => {
    link.addEventListener('click', (event) => {
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

      // A dated reserve link means the visitor already selected a date from the
      // Reservation Calendar, so do not make them read the notice again.
      const destination = new URL(link.href, window.location.href);
      if (destination.searchParams.has('date')) return;

      event.preventDefault();
      openModal(link);
    });
  });

  closeButtons.forEach((button) => button.addEventListener('click', closeModal));
  continueButton?.addEventListener('click', () => {
    window.location.href = targetUrl;
  });

  document.addEventListener('keydown', (event) => {
    if (modal.hidden) return;
    if (event.key === 'Escape') closeModal();
    if (event.key === 'Tab') {
      const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), a[href]'));
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  });
})();

// ---------------------------------------------------------------------------
// ADMIN NOTIFICATION BELL / POLLING
// ---------------------------------------------------------------------------
// Admin website reservation notifications - v1.0.59
(function () {
  const widgets = Array.from(document.querySelectorAll('[data-admin-notifications]'));
  if (!widgets.length) return;

  const escapeHtml = (value) => String(value == null ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');

  const closeAll = (except) => {
    widgets.forEach((widget) => {
      if (widget === except) return;
      const button = widget.querySelector('[data-notification-toggle]');
      const popover = widget.querySelector('[data-notification-popover]');
      if (button) button.setAttribute('aria-expanded', 'false');
      if (popover) popover.hidden = true;
    });
  };

  const render = (data) => {
    widgets.forEach((widget) => {
      const count = widget.querySelector('[data-notification-count]');
      const summary = widget.querySelector('[data-notification-summary]');
      const list = widget.querySelector('[data-notification-list]');
      const button = widget.querySelector('[data-notification-toggle]');
      const unread = Number(data.unread || 0);
      if (count) {
        count.textContent = unread > 99 ? '99+' : String(unread);
        count.classList.toggle('is-empty', unread < 1);
      }
      if (summary) summary.textContent = unread ? `${unread} unread` : 'No unread alerts';
      if (button) button.setAttribute('aria-label', unread ? `Notifications, ${unread} unread` : 'Notifications');
      if (list && Array.isArray(data.items)) {
        list.innerHTML = data.items.length ? data.items.map((item) => `
          <a class="admin-notification-item${item.is_read ? '' : ' is-unread'}" href="${escapeHtml(item.url)}">
            <span class="admin-notification-dot" aria-hidden="true"></span>
            <span><strong>${escapeHtml(item.title)}</strong><small>${escapeHtml(item.message)}</small><time>${escapeHtml(item.created_at)}</time></span>
          </a>`).join('') : '<div class="admin-notification-empty">No website reservation alerts yet.</div>';
      }
    });
  };

  const setNotificationLoading = (loading) => {
    widgets.forEach((widget) => {
      const list = widget.querySelector('[data-notification-list]');
      if (!list) return;
      list.classList.toggle('is-loading', loading);
      list.setAttribute('aria-busy', loading ? 'true' : 'false');
    });
  };

  const refresh = async () => {
    setNotificationLoading(true);
    try {
      const response = await fetch('notifications-feed.php', { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (!response.ok) return;
      render(await response.json());
    } catch (error) {
      // Keep the server-rendered notification state if refresh is unavailable.
    } finally {
      setNotificationLoading(false);
    }
  };

  widgets.forEach((widget) => {
    const button = widget.querySelector('[data-notification-toggle]');
    const popover = widget.querySelector('[data-notification-popover]');
    if (!button || !popover) return;
    button.addEventListener('click', (event) => {
      event.stopPropagation();
      const opening = popover.hidden;
      closeAll(opening ? widget : null);
      popover.hidden = !opening;
      button.setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening) refresh();
    });
    popover.addEventListener('click', (event) => event.stopPropagation());
  });

  document.addEventListener('click', () => closeAll(null));
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeAll(null); });
  window.setInterval(refresh, 45000);
}());

// ---------------------------------------------------------------------------
// SHARED SUCCESS CONFIRMATION MODAL
// One handler powers both public/admin success dialogs, including login/logout.
// ---------------------------------------------------------------------------
// Global success confirmation modal - v1.0.67
(function () {
  const modal = document.querySelector('[data-action-success-modal]');
  if (!modal) return;

  const dialog = modal.querySelector('.action-success-dialog');
  const closeButtons = Array.from(modal.querySelectorAll('[data-action-success-close]'));
  const okButton = modal.querySelector('.action-success-ok');
  const previousFocus = document.activeElement;

  document.body.classList.add('action-success-modal-open');
  window.setTimeout(() => (okButton || dialog)?.focus(), 0);

  const closeModal = () => {
    if (modal.classList.contains('is-closing')) return;
    modal.classList.add('is-closing');
    const finish = () => {
      modal.remove();
      document.body.classList.remove('action-success-modal-open');
      if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    };
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      finish();
    } else {
      window.setTimeout(finish, 190);
    }
  };

  closeButtons.forEach((button) => button.addEventListener('click', closeModal));
  document.addEventListener('keydown', (event) => {
    if (!document.body.contains(modal)) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeModal();
      return;
    }
    if (event.key !== 'Tab' || !dialog) return;
    const focusable = Array.from(dialog.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled])'));
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
}());


// Polished interface motion - v1.0.68
(function () {
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduceMotion) {
    document.documentElement.classList.add('reduce-motion');
    return;
  }

  const revealSelectors = [
    '.home-section-heading', '.home-package-card', '.home-upcoming-card',
    '.home-process-grid article', '.home-highlight-grid article', '.home-track-card',
    '.home-announcement-card', '.home-final-cta', '.form-card', '.card',
    '.tracking-status-hero', '.public-calendar-shell', '.tenant-card',
    '.tenant-directory-card', '.gallery-card', '.cta-band',
    '.reservation-gateway-process-intro', '.reservation-gateway-process li',
    '.reservation-gateway-venue-image', '.reservation-gateway-venue-copy',
    '.reservation-gateway-updates-title', '.reservation-gateway-update-list article'
  ];

  const candidates = Array.from(document.querySelectorAll(revealSelectors.join(',')))
    .filter((element) => !element.closest('[data-action-success-modal]'));

  candidates.forEach((element, index) => {
    element.classList.add('tlh-reveal');
    element.style.setProperty('--tlh-reveal-delay', `${Math.min(index % 4, 3) * 55}ms`);
  });

  if (!('IntersectionObserver' in window)) {
    candidates.forEach((element) => element.classList.add('is-visible'));
    return;
  }

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-visible');
      observer.unobserve(entry.target);
    });
  }, { threshold: 0.08, rootMargin: '0px 0px -24px 0px' });

  candidates.forEach((element) => observer.observe(element));
}());


// Public homepage hero parallax - v1.0.69
(function () {
  const hero = document.querySelector('[data-home-parallax]');
  if (!hero) return;

  const media = hero.querySelector('.home-hero-media');
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const desktop = window.matchMedia('(min-width: 901px)');
  let ticking = false;

  const reset = () => {
    hero.style.setProperty('--home-parallax-y', '0px');
  };

  const update = () => {
    ticking = false;
    if (reduceMotion.matches || !desktop.matches || !media) {
      reset();
      return;
    }

    const rect = hero.getBoundingClientRect();
    const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
    if (rect.bottom <= 0 || rect.top >= viewportHeight) return;

    const scrolledThroughHero = Math.max(0, Math.min(hero.offsetHeight, -rect.top));
    const offset = Math.min(34, scrolledThroughHero * 0.075);
    hero.style.setProperty('--home-parallax-y', `${offset.toFixed(2)}px`);
  };

  const requestUpdate = () => {
    if (ticking) return;
    ticking = true;
    window.requestAnimationFrame(update);
  };

  window.addEventListener('scroll', requestUpdate, { passive: true });
  window.addEventListener('resize', requestUpdate, { passive: true });
  reduceMotion.addEventListener?.('change', requestUpdate);
  desktop.addEventListener?.('change', requestUpdate);
  requestUpdate();
}());

// ---------------------------------------------------------------------------
// COMPACT VISUAL BOOKING SCHEDULER (public Reserve + admin New Reservation)
// Keeps the calendar visual but uses native Duration and Select Time dropdowns.
// Booked/unavailable times are disabled inside the Select Time dropdown.
// ---------------------------------------------------------------------------
(function initVisualSchedulers() {
  const pad = (value) => String(value).padStart(2, '0');
  const parseDate = (value) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || '').trim());
    if (!match) return null;
    return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12, 0, 0, 0);
  };
  const formatDateKey = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  const monthKey = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;
  const monthLabelFormatter = new Intl.DateTimeFormat('en-PH', { month: 'long', year: 'numeric' });
  const fullDateFormatter = new Intl.DateTimeFormat('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
  const readableTime = (value) => {
    const parts = String(value || '').split(':').map(Number);
    if (parts.length < 2 || !Number.isFinite(parts[0]) || !Number.isFinite(parts[1])) return '';
    const hours = parts[0];
    const minutes = parts[1];
    const suffix = hours >= 12 ? 'PM' : 'AM';
    return `${hours % 12 || 12}:${String(minutes).padStart(2, '0')} ${suffix}`;
  };
  const endTimeFor = (startValue, durationValue) => {
    const parts = String(startValue || '').split(':').map(Number);
    const durationHours = Number(durationValue || 0);
    if (parts.length < 2 || !Number.isFinite(parts[0]) || !Number.isFinite(parts[1]) || !Number.isFinite(durationHours) || durationHours <= 0) return '';
    const totalMinutes = (parts[0] * 60) + parts[1] + Math.round(durationHours * 60);
    return `${pad(Math.floor(totalMinutes / 60))}:${pad(totalMinutes % 60)}`;
  };

  document.querySelectorAll('[data-visual-scheduler]').forEach((scheduler) => {
    const form = scheduler.closest('form');
    const dateInput = form?.querySelector('[data-reservation-date]');
    const startSelect = form?.querySelector('[data-start-time]');
    const durationSelect = form?.querySelector('[data-duration-hours]');
    const typeInput = form?.querySelector('#reservation_type');
    const setupInput = form?.querySelector('[name="setup_minutes"]');
    const cleanupInput = form?.querySelector('[name="cleanup_minutes"]');
    const pastToggle = form?.querySelector('[data-past-date-toggle]');
    const monthLabel = scheduler.querySelector('[data-calendar-month-label]');
    const calendarGrid = scheduler.querySelector('[data-calendar-grid]');
    const calendarHelp = scheduler.querySelector('[data-calendar-help]');
    const timeHelp = scheduler.querySelector('[data-time-help]');
    const referenceTimeList = scheduler.querySelector('[data-reference-time-list]');
    const prevButton = scheduler.querySelector('[data-calendar-prev]');
    const nextButton = scheduler.querySelector('[data-calendar-next]');
    const mobileBackToDates = scheduler.querySelector('[data-mobile-back-to-dates]');
    const mobileTimeTitle = scheduler.querySelector('[data-mobile-time-title]');
    const mobileTimeDate = scheduler.querySelector('[data-mobile-time-date]');
    const mobileBreakpoint = window.matchMedia('(max-width: 840px)');
    const selectionTitle = scheduler.querySelector('[data-selection-summary-title]');
    const selectionDetail = scheduler.querySelector('[data-selection-summary-detail]');
    const schedulePanel = scheduler.closest('[data-form-step]');
    const scheduleNext = schedulePanel?.querySelector('[data-step-next]');
    const endpoint = scheduler.dataset.gridAvailabilityUrl || 'reservation-availability-grid.php';
    const monthEndpoint = scheduler.dataset.monthAvailabilityUrl || '';
    if (!form || !dateInput || !startSelect || !durationSelect || !calendarGrid) return;

    const todayValue = form.dataset.today || dateInput.min || formatDateKey(new Date());
    const todayDate = parseDate(todayValue) || new Date();
    if (!startSelect.dataset.lastChosenValue && startSelect.value) startSelect.dataset.lastChosenValue = startSelect.value;
    let visibleMonth = parseDate(dateInput.value) || new Date(todayDate.getFullYear(), todayDate.getMonth(), 1, 12, 0, 0, 0);
    visibleMonth = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth(), 1, 12, 0, 0, 0);
    let slotRequestController = null;
    let monthRequestController = null;
    let monthDates = null;
    let monthAvailabilityLoaded = !monthEndpoint;
    let lastAvailableSlots = [];

    const setMobileScheduleView = (view, shouldScroll = true) => {
      scheduler.dataset.mobileScheduleView = view === 'time' ? 'time' : 'date';
      if (!mobileBreakpoint.matches) return;
      if (shouldScroll) {
        const modalScroll = scheduler.closest('[data-public-booking-scroll]');
        if (modalScroll) modalScroll.scrollTo({ top: 0, behavior: 'smooth' });
        else scheduler.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    };
    const syncMobileTimeHeading = () => {
      const hours = Number(durationSelect.value || 0);
      const durationLabel = hours > 0
        ? (hours === 1 ? '1 hour' : Number.isInteger(hours) ? `${hours} hours` : `${hours} hour`)
        : '';
      if (mobileTimeTitle) mobileTimeTitle.textContent = 'Choose an available time';
      const selectedDate = parseDate(dateInput.value);
      if (mobileTimeDate) mobileTimeDate.textContent = selectedDate ? fullDateFormatter.format(selectedDate) : '';
    };

    mobileBackToDates?.addEventListener('click', () => setMobileScheduleView('date'));
    mobileBreakpoint.addEventListener?.('change', (event) => {
      if (event.matches && !scheduler.dataset.mobileScheduleView) setMobileScheduleView('date', false);
    });
    if (mobileBreakpoint.matches) setMobileScheduleView('date', false);
    syncMobileTimeHeading();

    const allowPastDates = () => Boolean(pastToggle?.checked) || form.dataset.adminAllowPast === '1';
    const minSelectableDate = () => allowPastDates() ? null : (parseDate(todayValue) || todayDate);
    const isPastDate = (candidate) => {
      const minimum = minSelectableDate();
      return minimum ? candidate.getTime() < minimum.getTime() : false;
    };
    const scheduleParams = () => {
      const params = new URLSearchParams({
        duration_hours: durationSelect.value,
        reservation_type: typeInput?.value || 'basketball',
        setup_minutes: typeInput?.value === 'event' ? (setupInput?.value || '0') : '0',
        cleanup_minutes: typeInput?.value === 'event' ? (cleanupInput?.value || '0') : '0',
      });
      if (allowPastDates()) params.set('admin_allow_past', '1');
      return params;
    };

    const updateSelectionSummary = () => {
      if (!dateInput.value || !startSelect.value || !durationSelect.value) {
        if (selectionTitle) selectionTitle.textContent = 'Choose a date and time';
        if (selectionDetail) selectionDetail.textContent = 'Choose the schedule first. Pricing is shown in the Booking Details step.';
        return;
      }
      const selectedDate = parseDate(dateInput.value);
      const endValue = endTimeFor(startSelect.value, durationSelect.value);
      if (selectionTitle) {
        selectionTitle.textContent = `${selectedDate ? fullDateFormatter.format(selectedDate) : dateInput.value} · ${readableTime(startSelect.value)} – ${readableTime(endValue)}`;
      }
      const total = Number(form.dataset.calculatedTotal || 0);
      if (selectionDetail) {
        selectionDetail.textContent = total > 0
          ? `Estimated amount: ${new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(total)}`
          : 'Continue to Booking Details to calculate the package and amount.';
      }
    };

    const setScheduleNextState = () => {
      if (scheduleNext) scheduleNext.disabled = !(dateInput.value && startSelect.value);
    };

    const renderCalendar = () => {
      if (monthLabel) monthLabel.textContent = monthLabelFormatter.format(visibleMonth);
      calendarGrid.replaceChildren();
      const firstDay = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth(), 1, 12, 0, 0, 0);
      const gridStart = new Date(firstDay);
      gridStart.setDate(firstDay.getDate() - firstDay.getDay());
      const selectedKey = dateInput.value;

      for (let index = 0; index < 42; index += 1) {
        const current = new Date(gridStart);
        current.setDate(gridStart.getDate() + index);
        const key = formatDateKey(current);
        const inMonth = current.getMonth() === visibleMonth.getMonth();
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'visual-calendar-day';
        button.textContent = String(current.getDate());
        button.dataset.date = key;
        if (!inMonth) button.classList.add('is-outside-month');
        if (key === selectedKey) button.classList.add('is-selected');
        if (key === todayValue) button.classList.add('is-today');

        let disabled = isPastDate(current);
        if (monthEndpoint) {
          if (!inMonth) disabled = true;
          if (!monthAvailabilityLoaded) disabled = true;
          if (inMonth && monthDates && monthDates[key] && monthDates[key].available === false) {
            disabled = true;
            button.classList.add('is-unavailable-date');
            button.title = 'No available time for the selected duration.';
          }
        }
        if (disabled) {
          button.disabled = true;
          button.classList.add('is-disabled');
        }

        button.addEventListener('click', () => {
          if (button.disabled) return;
          dateInput.value = key;
          dateInput.dispatchEvent(new Event('change', { bubbles: true }));
        });
        calendarGrid.appendChild(button);
      }

      const minimum = minSelectableDate();
      if (prevButton) {
        prevButton.disabled = Boolean(minimum)
          && visibleMonth.getFullYear() === minimum.getFullYear()
          && visibleMonth.getMonth() <= minimum.getMonth();
      }
    };

    const renderReferenceTimeList = (slots = lastAvailableSlots, message = '') => {
      if (!referenceTimeList) return;
      referenceTimeList.replaceChildren();
      if (message) {
        const empty = document.createElement('div');
        empty.className = 'reference-time-empty';
        empty.textContent = message;
        referenceTimeList.appendChild(empty);
        return;
      }
      if (!slots.length) {
        const empty = document.createElement('div');
        empty.className = 'reference-time-empty';
        empty.textContent = 'No available time.';
        referenceTimeList.appendChild(empty);
        return;
      }
      slots.forEach((slot) => {
        const row = document.createElement('div');
        row.className = 'reference-time-row';
        const startLabel = document.createElement('span');
        startLabel.className = 'reference-time-start';
        startLabel.textContent = readableTime(slot.value);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'reference-time-card';
        const isSelected = slot.value === startSelect.value;
        button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        if (isSelected) button.classList.add('is-selected');
        const main = document.createElement('strong');
        main.textContent = readableTime(slot.value);
        const end = document.createElement('small');
        end.textContent = `until ${readableTime(slot.end_time)}`;
        button.append(main, end);
        if (isSelected) {
          const selectedBadge = document.createElement('span');
          selectedBadge.className = 'reference-time-selected-badge';
          selectedBadge.textContent = '✓ Selected';
          button.appendChild(selectedBadge);
        }
        button.addEventListener('click', () => {
          startSelect.value = slot.value;
          startSelect.dataset.lastChosenValue = slot.value;
          startSelect.dispatchEvent(new Event('change', { bubbles: true }));
          renderReferenceTimeList(slots);
        });
        row.append(startLabel, button);
        referenceTimeList.appendChild(row);
      });
    };

    const setLoadingOption = (text) => {
      setScheduleNextState();
      startSelect.replaceChildren();
      const option = document.createElement('option');
      option.value = '';
      option.textContent = text;
      option.selected = true;
      startSelect.appendChild(option);
      startSelect.disabled = true;
      lastAvailableSlots = [];
      renderReferenceTimeList([], text);
    };

    const populateTimeDropdown = (slots) => {
      const previousValue = startSelect.dataset.lastChosenValue || startSelect.value;
      const availableSlots = (slots || []).filter((slot) => slot.available);
      lastAvailableSlots = availableSlots;
      startSelect.replaceChildren();

      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = availableSlots.length ? 'Select a time' : 'No times available';
      startSelect.appendChild(placeholder);

      let previousStillAvailable = false;
      availableSlots.forEach((slot) => {
        const option = document.createElement('option');
        option.value = slot.value;
        option.textContent = monthEndpoint
          ? `${readableTime(slot.value)} – ${readableTime(slot.end_time)}`
          : `${readableTime(slot.value)} — Available`;
        if (slot.value === previousValue) {
          option.selected = true;
          previousStillAvailable = true;
        }
        startSelect.appendChild(option);
      });

      startSelect.disabled = availableSlots.length === 0;
      if (!previousStillAvailable) startSelect.value = '';
      startSelect.dataset.lastChosenValue = startSelect.value;
      if (timeHelp) {
        timeHelp.textContent = availableSlots.length === 0
          ? 'No available times remain on this date. Please choose another date.'
          : `${availableSlots.length} available ${availableSlots.length === 1 ? 'time' : 'times'} for this date.`;
      }
      setScheduleNextState();
      startSelect.dispatchEvent(new Event('change', { bubbles: true }));
      renderReferenceTimeList(availableSlots);
      updateSelectionSummary();
    };

    const fetchSlots = async () => {
      if (!dateInput.value || !durationSelect.value) {
        setLoadingOption('Choose a date first');
        if (timeHelp) timeHelp.textContent = 'Choose an available date to see the times you can book.';
        updateSelectionSummary();
        return;
      }

      const params = scheduleParams();
      params.set('date', dateInput.value);
      slotRequestController?.abort();
      slotRequestController = new AbortController();
      setLoadingOption('Checking times…');
      scheduler.classList.add('is-loading-slots');
      if (timeHelp) timeHelp.textContent = 'Checking the available times for this date…';

      try {
        const response = await fetch(`${endpoint}?${params.toString()}`, {
          headers: { Accept: 'application/json' },
          cache: 'no-store',
          signal: slotRequestController.signal,
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
          setLoadingOption('Could not load times');
          if (timeHelp) timeHelp.textContent = data.message || 'Available times could not be loaded. Please try again.';
          return;
        }
        populateTimeDropdown(data.slots || []);
      } catch (error) {
        if (error?.name === 'AbortError') return;
        setLoadingOption('Could not load times');
        if (timeHelp) timeHelp.textContent = 'Available times could not be loaded. Please try again.';
      } finally {
        scheduler.classList.remove('is-loading-slots');
      }
    };

    const fetchMonthAvailability = async () => {
      if (!monthEndpoint || !durationSelect.value) {
        monthAvailabilityLoaded = true;
        monthDates = null;
        renderCalendar();
        return true;
      }

      monthRequestController?.abort();
      monthRequestController = new AbortController();
      monthAvailabilityLoaded = false;
      monthDates = null;
      if (calendarHelp) calendarHelp.textContent = 'Checking available dates…';
      renderCalendar();

      const params = scheduleParams();
      params.set('month', monthKey(visibleMonth));
      try {
        const response = await fetch(`${monthEndpoint}?${params.toString()}`, {
          headers: { Accept: 'application/json' },
          cache: 'no-store',
          signal: monthRequestController.signal,
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
          monthAvailabilityLoaded = true;
          monthDates = null;
          if (calendarHelp) calendarHelp.textContent = 'Choose a date to check its available times.';
          renderCalendar();
          return false;
        }

        monthAvailabilityLoaded = true;
        monthDates = data.dates || {};
        if (calendarHelp) calendarHelp.textContent = 'Select any date that is not dimmed.';

        if (dateInput.value && dateInput.value.startsWith(`${monthKey(visibleMonth)}-`) && monthDates[dateInput.value]?.available === false) {
          dateInput.value = '';
          startSelect.dataset.lastChosenValue = '';
          setLoadingOption('Choose a date first');
          if (timeHelp) timeHelp.textContent = 'Choose an available date to see the times you can book.';
        }
        renderCalendar();
        setScheduleNextState();
        return true;
      } catch (error) {
        if (error?.name === 'AbortError') return false;
        monthAvailabilityLoaded = true;
        monthDates = null;
        if (calendarHelp) calendarHelp.textContent = 'Choose a date to check its available times.';
        renderCalendar();
        return false;
      }
    };

    const refreshAvailability = async ({ reloadMonth = true, reloadSlots = true } = {}) => {
      if (reloadMonth) await fetchMonthAvailability();
      if (reloadSlots) await fetchSlots();
    };

    const clearSelectedScheduleForMonthChange = () => {
      if (!monthEndpoint || !dateInput.value) return;
      dateInput.value = '';
      startSelect.dataset.lastChosenValue = '';
      dateInput.dispatchEvent(new Event('change', { bubbles: true }));
    };

    prevButton?.addEventListener('click', async () => {
      const minimum = minSelectableDate();
      const candidate = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth() - 1, 1, 12, 0, 0, 0);
      if (minimum && candidate.getFullYear() === minimum.getFullYear() && candidate.getMonth() < minimum.getMonth()) return;
      visibleMonth = candidate;
      clearSelectedScheduleForMonthChange();
      await fetchMonthAvailability();
    });
    nextButton?.addEventListener('click', async () => {
      visibleMonth = new Date(visibleMonth.getFullYear(), visibleMonth.getMonth() + 1, 1, 12, 0, 0, 0);
      clearSelectedScheduleForMonthChange();
      await fetchMonthAvailability();
    });

    dateInput.addEventListener('change', () => {
      const parsed = parseDate(dateInput.value);
      if (parsed) visibleMonth = new Date(parsed.getFullYear(), parsed.getMonth(), 1, 12, 0, 0, 0);
      renderCalendar();
      syncMobileTimeHeading();
      if (dateInput.value && mobileBreakpoint.matches) setMobileScheduleView('time');
      fetchSlots();
    });
    durationSelect.addEventListener('change', () => {
      syncMobileTimeHeading();
      refreshAvailability();
    });
    startSelect.addEventListener('change', () => {
      startSelect.dataset.lastChosenValue = startSelect.value;
      setScheduleNextState();
      if (referenceTimeList) renderReferenceTimeList(lastAvailableSlots);
      updateSelectionSummary();
    });
    [typeInput, setupInput, cleanupInput].filter(Boolean).forEach((field) => field.addEventListener('change', () => refreshAvailability()));
    pastToggle?.addEventListener('change', () => {
      window.setTimeout(() => refreshAvailability(), 0);
    });
    form.addEventListener('pricing-updated', updateSelectionSummary);

    setScheduleNextState();
    if (monthEndpoint) {
      refreshAvailability({ reloadMonth: true, reloadSlots: Boolean(dateInput.value) });
      if (!dateInput.value) setLoadingOption('Choose a date first');
    } else {
      renderCalendar();
      if (dateInput.value) fetchSlots();
      else setLoadingOption('Select a date and duration first');
    }
    updateSelectionSummary();
  });
}());

// ---------------------------------------------------------------------------
// LIVE SINGLE-RESERVATION AVAILABILITY
// This is advisory UX. Final PHP submission repeats the conflict check.
// ---------------------------------------------------------------------------
// Live reservation availability check (public + admin) introduced in v1.0.76; admin New Reservation enabled in v1.0.88.
document.querySelectorAll('[data-live-availability]').forEach((form) => {
  const dateInput = form.querySelector('[data-reservation-date]');
  const startInput = form.querySelector('[data-start-time]');
  const durationInput = form.querySelector('[data-duration-hours]');
  const typeInput = form.querySelector('#reservation_type');
  const setupInput = form.querySelector('[name="setup_minutes"]');
  const cleanupInput = form.querySelector('[name="cleanup_minutes"]');
  const statusBox = form.querySelector('[data-live-availability-status]');
  const schedulePanel = statusBox.closest('[data-form-step]');
  const scheduleNext = schedulePanel?.querySelector('[data-step-next]');
  const endpoint = form.dataset.availabilityUrl || 'reservation-availability-check.php';
  const pastDateToggle = form.querySelector('[data-past-date-toggle]');
  const pastDateNote = form.querySelector('[data-past-date-note]');
  const reservationSchedule = form.querySelector('[data-reservation-schedule]');
  const today = form.dataset.today || '';
  if (!dateInput || !startInput || !durationInput || !statusBox) return;

  const syncPastDateAccess = () => {
    if (!pastDateToggle) return;
    const enabled = pastDateToggle.checked;
    form.dataset.adminAllowPast = enabled ? '1' : '0';
    if (enabled) {
      dateInput.removeAttribute('min');
      reservationSchedule?.removeAttribute('data-future-only');
    } else {
      if (today) dateInput.min = today;
      reservationSchedule?.setAttribute('data-future-only', '');
      if (today && dateInput.value && dateInput.value < today) dateInput.value = '';
    }
    if (pastDateNote) {
      pastDateNote.textContent = enabled
        ? 'Historical entry enabled. Past dates can be selected.'
        : 'Historical entry is off. Only the current or future schedule can be selected.';
    }
  };

  const icon = statusBox.querySelector('.schedule-availability-icon');
  const title = statusBox.querySelector('strong');
  const message = statusBox.querySelector('p');
  let requestController = null;
  let debounceTimer = null;
  let lastKey = '';

  const setState = (state, heading, text) => {
    statusBox.dataset.state = state;
    if (title) title.textContent = heading;
    if (message) message.textContent = text;
    if (icon) icon.textContent = state === 'available' ? '✓' : state === 'unavailable' ? '×' : state === 'checking' ? '…' : state === 'warning' ? '!' : state === 'invalid' ? '!' : '○';
    form.dataset.availabilityState = state;
    if (scheduleNext) scheduleNext.disabled = !startInput.value || state === 'checking' || state === 'unavailable' || state === 'invalid';
  };

  const currentParams = () => {
    const params = new URLSearchParams({
      date: dateInput.value,
      start_time: startInput.value,
      duration_hours: durationInput.value,
      reservation_type: typeInput?.value || 'basketball',
      setup_minutes: typeInput?.value === 'event' ? (setupInput?.value || '0') : '0',
      cleanup_minutes: typeInput?.value === 'event' ? (cleanupInput?.value || '0') : '0',
    });
    if (form.dataset.adminAllowPast === '1') params.set('admin_allow_past', '1');
    return params;
  };

  const checkAvailability = async () => {
    if (!dateInput.value || !startInput.value || !durationInput.value) {
      requestController?.abort();
      lastKey = '';
      setState('idle', 'Live availability check', 'Select a date, start time, and duration to check the slot.');
      return;
    }

    const params = currentParams();
    const key = params.toString();
    if (key === lastKey && ['available', 'unavailable'].includes(form.dataset.availabilityState || '')) return;
    lastKey = key;

    requestController?.abort();
    requestController = new AbortController();
    setState('checking', 'Checking availability…', 'Cross-checking this schedule against secured reservations.');

    try {
      const response = await fetch(`${endpoint}?${params.toString()}`, {
        method: 'GET',
        headers: { Accept: 'application/json' },
        cache: 'no-store',
        signal: requestController.signal,
      });
      const data = await response.json();
      if (!response.ok && data.status === 'invalid') {
        setState('invalid', 'Choose another schedule', data.message || 'Please review the selected date and time.');
        return;
      }
      if (!response.ok && data.status !== 'error') {
        setState('warning', 'Check the schedule', data.message || 'Please review the selected date and time.');
        return;
      }
      if (data.status === 'available' && data.available === true) {
        const publicRequest = form.hasAttribute('data-public-request');
        setState('available', publicRequest ? 'Available to request' : 'Available right now', publicRequest ? 'You can continue with this schedule.' : (data.message || 'This schedule is currently available to request.'));
      } else if (data.status === 'unavailable' || data.available === false) {
        setState('unavailable', 'Already reserved', data.message || 'Please choose another available schedule.');
      } else {
        setState('warning', 'Live check unavailable', data.message || 'The schedule will be checked again when you submit.');
      }
    } catch (error) {
      if (error?.name === 'AbortError') return;
      setState('warning', 'Live check unavailable', 'We could not check this slot right now. It will still be checked again when you submit.');
    }
  };

  const scheduleCheck = () => {
    window.clearTimeout(debounceTimer);
    lastKey = '';
    setState('checking', 'Checking availability…', 'Cross-checking this schedule against secured reservations.');
    debounceTimer = window.setTimeout(checkAvailability, 220);
  };

  [dateInput, startInput, durationInput, typeInput, setupInput, cleanupInput]
    .filter(Boolean)
    .forEach((field) => field.addEventListener('change', scheduleCheck));

  pastDateToggle?.addEventListener('change', () => {
    syncPastDateAccess();
    dateInput.dispatchEvent(new Event('change', { bubbles: true }));
  });

  form.addEventListener('submit', (event) => {
    if (form.dataset.availabilityState === 'unavailable') {
      event.preventDefault();
      setState('unavailable', 'Already reserved', 'This schedule is no longer available. Please choose another date or time before submitting.');
      schedulePanel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });

  syncPastDateAccess();
  checkAvailability();
});


// ---------------------------------------------------------------------------
// LIVE RESCHEDULE / EXTENSION CONFLICT CHECKS
// Admin-only advisory checks exclude the reservation being changed. The final
// PHP action still acquires the shared venue lock and repeats the conflict test.
// ---------------------------------------------------------------------------
(function initReservationChangeConflictChecks() {
  const updateStatus = (form, statusBox, state, heading, text) => {
    const icon = statusBox?.querySelector('.schedule-availability-icon');
    const title = statusBox?.querySelector('strong');
    const message = statusBox?.querySelector('p');
    const submit = form?.querySelector('button[type="submit"]');
    if (!form || !statusBox) return;
    statusBox.dataset.state = state;
    form.dataset.conflictState = state;
    if (title) title.textContent = heading;
    if (message) message.textContent = text;
    if (icon) icon.textContent = state === 'available' ? '✓' : state === 'unavailable' ? '×' : state === 'checking' ? '…' : state === 'warning' || state === 'invalid' ? '!' : '○';
    if (submit) submit.disabled = ['checking', 'unavailable', 'invalid'].includes(state);
  };

  document.querySelectorAll('[data-reschedule-live-conflict]').forEach((form) => {
    const date = form.querySelector('[data-reservation-date]');
    const start = form.querySelector('[data-start-time]');
    const end = form.querySelector('[data-end-time]');
    const statusBox = form.querySelector('[data-reschedule-live-status]');
    const endpoint = form.dataset.conflictUrl || 'reservation-conflict-check.php';
    const bookingId = form.dataset.bookingId || '';
    if (!date || !start || !end || !statusBox || !bookingId) return;

    const pricingSelect = form.querySelector('[name="pricing_mode"]');
    const newDateRateOption = pricingSelect?.querySelector('option[value="new_date_rate"]');
    const newDateRatePreview = form.querySelector('[data-new-date-rate-preview]');
    const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    const updateNewDateRate = () => {
      if (!newDateRateOption) return;
      const packageType = form.dataset.pricingPackage || '';
      let rate = 0;
      let label = 'Current Rate';
      if (packageType === 'regular') {
        const introStart = form.dataset.introStart || '';
        const introEnd = form.dataset.introEnd || '';
        const inIntroPeriod = Boolean(date.value && introStart && introEnd && date.value >= introStart && date.value <= introEnd);
        rate = Number(inIntroPeriod ? form.dataset.introRate : form.dataset.regularRate) || 0;
        label = inIntroPeriod ? 'Introductory Rate' : 'Regular Rate';
      } else {
        rate = Number(form.dataset.packageRate) || 0;
        label = packageType === 'tournament' ? 'Tournament Rate' : packageType === 'big_event' ? 'Big Event Rate' : 'Current Rate';
      }
      newDateRateOption.textContent = `Apply Rate for the New Date — ${currency.format(rate)}/hour (${label})`;
      if (newDateRatePreview) {
        newDateRatePreview.textContent = date.value
          ? `New-date venue rate: ${currency.format(rate)}/hour (${label}). Existing add-on rates and complimentary terms remain attached to this reservation.`
          : 'Choose a new reservation date to see the venue rate that would apply.';
      }
    };
    updateNewDateRate();

    let controller = null;
    let timer = null;
    let lastKey = '';

    const check = async () => {
      if (!date.value || !start.value || !end.value) {
        controller?.abort();
        lastKey = '';
        updateStatus(form, statusBox, 'idle', 'Live conflict check', 'Choose a date, start time, and end time to check the schedule.');
        return;
      }

      const params = new URLSearchParams({
        booking_id: bookingId,
        mode: 'reschedule',
        date: date.value,
        start_time: start.value,
        end_time: end.value,
      });
      const key = params.toString();
      if (key === lastKey && ['available', 'unavailable', 'invalid'].includes(form.dataset.conflictState || '')) return;
      lastKey = key;
      controller?.abort();
      controller = new AbortController();
      updateStatus(form, statusBox, 'checking', 'Checking conflicts…', 'Comparing the proposed schedule with secured reservations.');

      try {
        const response = await fetch(`${endpoint}?${params.toString()}`, {
          method: 'GET',
          headers: { Accept: 'application/json' },
          cache: 'no-store',
          signal: controller.signal,
        });
        const data = await response.json();
        if (!response.ok && data.status === 'invalid') {
          updateStatus(form, statusBox, 'invalid', 'Review the schedule', data.message || 'Choose a valid reschedule.');
        } else if (data.status === 'available' && data.available === true) {
          updateStatus(form, statusBox, 'available', 'No conflict found', data.message || 'This schedule is currently available.');
        } else if (data.status === 'unavailable' || data.available === false) {
          updateStatus(form, statusBox, 'unavailable', 'Schedule conflict', data.message || 'Choose another date or time.');
        } else {
          updateStatus(form, statusBox, 'warning', 'Live check unavailable', data.message || 'The schedule will still be checked when you confirm.');
        }
      } catch (error) {
        if (error?.name === 'AbortError') return;
        updateStatus(form, statusBox, 'warning', 'Live check unavailable', 'We could not check this schedule right now. It will still be checked again when you confirm.');
      }
    };

    const schedule = () => {
      window.clearTimeout(timer);
      lastKey = '';
      updateStatus(form, statusBox, 'checking', 'Checking conflicts…', 'Comparing the proposed schedule with secured reservations.');
      timer = window.setTimeout(check, 220);
    };

    [date, start, end].forEach((field) => field.addEventListener('change', schedule));
    date.addEventListener('change', updateNewDateRate);
    form.addEventListener('submit', (event) => {
      if (['checking', 'unavailable', 'invalid'].includes(form.dataset.conflictState || '')) {
        event.preventDefault();
        statusBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
    check();
  });

  document.querySelectorAll('[data-extension-live-conflict]').forEach((form) => {
    const hours = form.querySelector('[name="extension_hours"]');
    const statusBox = form.querySelector('[data-extension-live-status]');
    const endpoint = form.dataset.conflictUrl || 'reservation-conflict-check.php';
    const bookingId = form.dataset.bookingId || '';
    const historicalAckWrap = form.querySelector('[data-historical-overlap-ack-wrap]');
    const historicalAck = form.querySelector('[data-historical-overlap-ack]');
    if (!hours || !statusBox || !bookingId) return;

    const setHistoricalAckRequired = (required) => {
      if (!historicalAckWrap || !historicalAck) return;
      historicalAckWrap.hidden = !required;
      historicalAck.required = required;
      if (!required) historicalAck.checked = false;
    };

    let controller = null;
    let timer = null;
    let lastKey = '';

    const check = async () => {
      if (!hours.value) {
        controller?.abort();
        lastKey = '';
        updateStatus(form, statusBox, 'idle', 'Live conflict check', 'Choose an extension duration to check the calendar.');
        return;
      }

      const params = new URLSearchParams({
        booking_id: bookingId,
        mode: 'extend',
        extension_hours: hours.value,
      });
      const key = params.toString();
      if (key === lastKey && ['available', 'unavailable', 'invalid'].includes(form.dataset.conflictState || '')) return;
      lastKey = key;
      controller?.abort();
      controller = new AbortController();
      setHistoricalAckRequired(false);
      updateStatus(form, statusBox, 'checking', 'Checking conflicts…', 'Checking the extended end time and cleanup period against secured reservations.');

      try {
        const response = await fetch(`${endpoint}?${params.toString()}`, {
          method: 'GET',
          headers: { Accept: 'application/json' },
          cache: 'no-store',
          signal: controller.signal,
        });
        const data = await response.json();
        if (!response.ok && data.status === 'invalid') {
          setHistoricalAckRequired(false);
          updateStatus(form, statusBox, 'invalid', 'Extension unavailable', data.message || 'Choose a valid extension duration.');
        } else if (data.status === 'historical_conflict' && data.requires_ack === true) {
          setHistoricalAckRequired(true);
          updateStatus(form, statusBox, 'warning', 'Historical overlap detected', data.message || 'Confirm the historical-overlap acknowledgement if this reflects what actually happened.');
        } else if (data.status === 'available' && data.available === true) {
          setHistoricalAckRequired(false);
          updateStatus(form, statusBox, 'available', data.late_extension ? 'Late extension can be recorded' : 'No conflict found', data.message || 'The extension is currently available.');
        } else if (data.status === 'unavailable' || data.available === false) {
          setHistoricalAckRequired(false);
          updateStatus(form, statusBox, 'unavailable', data.historical_conflict ? 'Historical overlap requires Administrator' : 'Extension conflict', data.message || 'The added time overlaps another reservation.');
        } else {
          setHistoricalAckRequired(false);
          updateStatus(form, statusBox, 'warning', 'Live check unavailable', data.message || 'The extension will still be checked when you confirm.');
        }
      } catch (error) {
        if (error?.name === 'AbortError') return;
        updateStatus(form, statusBox, 'warning', 'Live check unavailable', 'We could not check this extension right now. It will still be checked again when you confirm.');
      }
    };

    const schedule = () => {
      window.clearTimeout(timer);
      lastKey = '';
      setHistoricalAckRequired(false);
      updateStatus(form, statusBox, 'checking', 'Checking conflicts…', 'Checking the extended end time and cleanup period against secured reservations.');
      timer = window.setTimeout(check, 220);
    };

    hours.addEventListener('change', schedule);
    form.addEventListener('submit', (event) => {
      if (['checking', 'unavailable', 'invalid'].includes(form.dataset.conflictState || '')) {
        event.preventDefault();
        statusBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
    check();
  });
}());


// ---------------------------------------------------------------------------
// BATCH RESERVATION SCHEDULE + LIVE PRICE / CONFLICT PREVIEW
// Server endpoint owns pricing/conflict results; JS only renders and coordinates.
// ---------------------------------------------------------------------------
// Flexible batch reservation workflow with recurring or specific-date schedules - v1.0.83; Step 1 live price preview refined in v1.0.87.
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-batch-live-availability]').forEach((form) => {
    const modeInputs = Array.from(form.querySelectorAll('[data-batch-schedule-mode]'));
    const recurringPanel = form.querySelector('[data-batch-recurring-panel]');
    const specificPanel = form.querySelector('[data-batch-specific-panel]');
    const recurringFields = Array.from(form.querySelectorAll('[data-batch-recurring-field]'));
    const rangeStart = form.querySelector('[data-batch-range-start]');
    const rangeEnd = form.querySelector('[data-batch-range-end]');
    const startTime = form.querySelector('[data-batch-start-time]');
    const duration = form.querySelector('[data-batch-duration]');
    const type = form.querySelector('[data-batch-type]');
    const guests = form.querySelector('[data-batch-guests]');
    const cooling = form.querySelector('[data-batch-cooling]');
    const setup = form.querySelector('[data-batch-setup]');
    const cleanup = form.querySelector('[data-batch-cleanup]');
    const shower = form.querySelector('[data-batch-shower]');
    const showerComplimentary = form.querySelector('[data-batch-shower-complimentary]');
    const equipment = form.querySelector('[data-batch-equipment]');
    const equipmentComplimentary = form.querySelector('[data-batch-equipment-complimentary]');
    const weekdays = Array.from(form.querySelectorAll('input[name="weekdays[]"]'));
    const statusBox = form.querySelector('[data-batch-live-status]');
    const counts = form.querySelector('[data-batch-live-counts]');
    const conflictChips = form.querySelector('[data-batch-conflict-chips]');
    const rowsBody = form.querySelector('[data-batch-live-rows]');
    const reviewStatus = form.querySelector('[data-batch-review-status]');
    const skipWrap = form.querySelector('[data-batch-skip-wrap]');
    const skipInput = form.querySelector('[data-batch-skip-conflicts]');
    const allConflicts = form.querySelector('[data-batch-all-conflicts]');
    const quickPrice = form.querySelector('[data-batch-quick-price]');
    const quickPricePackage = form.querySelector('[data-batch-quick-price-package]');
    const quickPriceTotal = form.querySelector('[data-batch-quick-price-total]');
    const quickPriceMessage = form.querySelector('[data-batch-quick-price-message]');
    const pricePanel = form.querySelector('[data-batch-price-panel]');
    const priceTotal = form.querySelector('[data-batch-price-total]');
    const pricePackage = form.querySelector('[data-batch-price-package]');
    const priceScope = form.querySelector('[data-batch-price-scope]');
    const priceLines = form.querySelector('[data-batch-price-lines]');
    const priceNote = form.querySelector('[data-batch-price-note]');
    const createButton = form.querySelector('[data-batch-create-button]');
    const schedulePanel = form.querySelector('[data-form-step="1"]');
    const scheduleNext = schedulePanel?.querySelector('[data-step-next]');
    const endpoint = form.dataset.batchAvailabilityUrl || 'batch-availability-check.php';

    const specificRowsHost = form.querySelector('[data-specific-rows]');
    const specificRowTemplate = form.querySelector('[data-specific-row-template]');
    const specificDatePicker = form.querySelector('[data-specific-date-picker]');
    const specificAddButton = form.querySelector('[data-specific-add-date]');
    const specificDefaultStart = form.querySelector('[data-specific-default-start]');
    const specificDefaultDuration = form.querySelector('[data-specific-default-duration]');
    const specificApplyAll = form.querySelector('[data-specific-apply-all]');
    const specificEmpty = form.querySelector('[data-specific-empty]');
    const pastDateToggle = form.querySelector('[data-past-date-toggle]');
    const pastDateNote = form.querySelector('[data-past-date-note]');
    const today = form.dataset.today || '';

    if (!statusBox || !modeInputs.length) return;

    const icon = statusBox.querySelector('.batch-live-check-icon');
    const statusTitle = statusBox.querySelector('.batch-live-check-copy strong');
    const statusMessage = statusBox.querySelector('.batch-live-check-copy p');
    const totalCount = form.querySelector('[data-batch-live-total]');
    const availableCount = form.querySelector('[data-batch-live-available]');
    const conflictCount = form.querySelector('[data-batch-live-conflicts]');
    const endLabel = form.querySelector('[data-batch-end-label]');
    const client = form.querySelector('[data-batch-client]');
    const initialStatus = form.querySelector('[data-batch-status]');
    const reviewClient = form.querySelector('[data-batch-review-client]');
    const reviewSchedule = form.querySelector('[data-batch-review-schedule]');
    const reviewMode = form.querySelector('[data-batch-review-mode]');
    const reviewBooking = form.querySelector('[data-batch-review-booking]');
    const reviewAvailable = form.querySelector('[data-batch-review-available]');
    const reviewTotal = form.querySelector('[data-batch-review-total]');
    const applyDiscount = form.querySelector('[data-apply-discount]');
    const discountInput = form.querySelector('[data-discount-input]');
    const discountReason = form.querySelector('input[name="discount_reason"]');
    const eventFields = Array.from(form.querySelectorAll('[data-batch-event-fields]'));
    const sportFields = Array.from(form.querySelectorAll('[data-batch-sport-fields]'));
    const firstWeekday = weekdays[0] || null;
    const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    const dayNames = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri', 6: 'Sat', 7: 'Sun' };
    let requestController = null;
    let debounceTimer = null;
    let lastKey = '';
    let lastResult = null;

    const scheduleMode = () => modeInputs.find((input) => input.checked)?.value || 'recurring';
    const checkedWeekdays = () => weekdays.filter((input) => input.checked).map((input) => Number(input.value));
    const specificRows = () => Array.from(specificRowsHost?.querySelectorAll('[data-specific-row]') || []);

    const formatTime = (value) => {
      const [hourRaw, minute = '00'] = String(value || '').split(':');
      const hour = Number(hourRaw);
      if (!Number.isFinite(hour)) return value || '—';
      const suffix = hour >= 12 ? 'PM' : 'AM';
      const display = hour % 12 || 12;
      return `${display}:${minute} ${suffix}`;
    };

    const formatShortDate = (value) => {
      if (!value) return '—';
      const date = new Date(`${value}T00:00:00`);
      if (Number.isNaN(date.getTime())) return value;
      return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
    };

    const setSpecificRowStatus = (row, state, text) => {
      const holder = row?.querySelector('[data-specific-row-status]');
      if (!holder) return;
      holder.replaceChildren();
      const pill = document.createElement('span');
      let css = 'status-pill';
      if (state === 'available') css += ' status-success';
      else if (state === 'conflict' || state === 'invalid') css += ' status-danger';
      else if (state === 'checking') css += ' status-warning';
      else css += ' status-secondary';
      pill.className = css;
      pill.textContent = text;
      holder.appendChild(pill);
      row.classList.toggle('has-conflict', state === 'conflict');
    };

    const syncSpecificEmpty = () => {
      if (specificEmpty) specificEmpty.hidden = specificRows().length > 0;
    };

    const addSpecificRow = (values = {}, { focus = false } = {}) => {
      if (!specificRowsHost || !specificRowTemplate) return null;
      const fragment = specificRowTemplate.content.cloneNode(true);
      const row = fragment.querySelector('[data-specific-row]');
      if (!row) return null;
      const dateField = row.querySelector('[data-specific-row-date]');
      const startField = row.querySelector('[data-specific-row-start]');
      const durationField = row.querySelector('[data-specific-row-duration]');
      if (dateField) {
        if (!pastDateToggle?.checked && today) dateField.min = today;
        else dateField.removeAttribute('min');
        dateField.value = values.date || '';
      }
      if (startField) startField.value = values.start_time || specificDefaultStart?.value || startTime?.value || '08:00';
      if (durationField) durationField.value = String(values.duration_hours || values.duration || specificDefaultDuration?.value || duration?.value || '2');
      row.querySelector('[data-specific-remove]')?.addEventListener('click', () => {
        row.remove();
        syncSpecificEmpty();
        scheduleCheck();
      });
      [dateField, startField, durationField].filter(Boolean).forEach((field) => field.addEventListener('change', scheduleCheck));
      specificRowsHost.appendChild(row);
      syncSpecificEmpty();
      if (focus) dateField?.focus();
      return row;
    };

    const initialSpecificRows = (() => {
      if (!specificRowsHost?.dataset.specificInitial) return [];
      try {
        const parsed = JSON.parse(specificRowsHost.dataset.specificInitial);
        return Array.isArray(parsed) ? parsed : [];
      } catch (error) {
        return [];
      }
    })();
    initialSpecificRows.forEach((row) => addSpecificRow(row));

    const syncPastDateAccess = ({ clearInvalid = true } = {}) => {
      if (!pastDateToggle) return;
      const enabled = pastDateToggle.checked;
      form.dataset.adminAllowPast = enabled ? '1' : '0';
      const dateFields = [rangeStart, rangeEnd, specificDatePicker, ...specificRows().map((row) => row.querySelector('[data-specific-row-date]'))].filter(Boolean);
      dateFields.forEach((field) => {
        if (enabled) field.removeAttribute('min');
        else if (today) field.min = today;
        if (!enabled && clearInvalid && today && field.value && field.value < today) field.value = '';
      });
      if (pastDateNote) {
        pastDateNote.textContent = enabled
          ? 'Historical entry enabled. Past batch dates can be selected.'
          : 'Historical entry is off. Only current or future batch schedules can be selected.';
      }
    };
    syncPastDateAccess();

    const syncWeekdayValidity = () => {
      if (!firstWeekday) return true;
      if (scheduleMode() !== 'recurring') {
        firstWeekday.setCustomValidity('');
        return true;
      }
      const hasSelected = checkedWeekdays().length > 0;
      firstWeekday.setCustomValidity(hasSelected ? '' : 'Select at least one recurring day.');
      return hasSelected;
    };

    const syncType = () => {
      const isEvent = type?.value === 'event';
      eventFields.forEach((el) => { el.hidden = !isEvent; });
      sportFields.forEach((el) => { el.hidden = isEvent; });
    };

    const syncMode = () => {
      const mode = scheduleMode();
      const recurring = mode === 'recurring';
      if (recurringPanel) recurringPanel.hidden = !recurring;
      if (specificPanel) specificPanel.hidden = recurring;
      recurringFields.forEach((field) => { field.disabled = !recurring; });
      specificRows().forEach((row) => {
        row.querySelectorAll('input, select, textarea').forEach((field) => { field.disabled = recurring; });
      });
      syncWeekdayValidity();
      syncSpecificEmpty();
    };

    const packageLabel = () => {
      const count = Number(guests?.value || 0);
      if (!Number.isFinite(count) || count < 1) return 'Enter guest count';
      if (count <= 29) return 'Regular';
      if (count <= 200) return 'Tournament';
      if (count <= 400) return 'Big Event';
      return 'Invalid guest count';
    };

    const syncQuickPricePending = (checking = false) => {
      if (!quickPrice) return;
      const count = Number(guests?.value || 0);
      const validGuests = Number.isFinite(count) && count >= 1 && count <= 400;
      const label = packageLabel();
      if (quickPricePackage) quickPricePackage.textContent = label;
      quickPrice.classList.toggle('pricing-error', Number.isFinite(count) && count > 400);
      if (!validGuests) {
        if (quickPriceTotal) quickPriceTotal.textContent = '—';
        if (quickPriceMessage) quickPriceMessage.textContent = count > 400
          ? 'Maximum capacity is 400 guests.'
          : 'Enter 1–400 guests to calculate the batch price.';
        return;
      }
      if (!scheduleReady()) {
        if (quickPriceTotal) quickPriceTotal.textContent = '—';
        if (quickPriceMessage) quickPriceMessage.textContent = 'Complete the schedule to calculate the total price.';
        return;
      }
      if (checking) {
        if (quickPriceTotal) quickPriceTotal.textContent = '…';
        if (quickPriceMessage) quickPriceMessage.textContent = 'Calculating the current batch price…';
      }
    };

    const updateEndLabel = () => {
      if (!endLabel || scheduleMode() !== 'recurring') return;
      const [hourRaw, minuteRaw = '0'] = String(startTime?.value || '').split(':');
      const hour = Number(hourRaw);
      const minutes = Number(minuteRaw);
      const hours = Number(duration?.value || 0);
      if (!Number.isFinite(hour) || !Number.isFinite(minutes) || !Number.isFinite(hours) || hours < 0.5) {
        endLabel.textContent = 'Ends automatically based on the start time.';
        return;
      }
      const endMinutes = (hour * 60) + minutes + Math.round(hours * 60);
      if (endMinutes > (24 * 60)) {
        endLabel.textContent = 'This duration continues past the selected date.';
        return;
      }
      const normalized = `${String(Math.floor((endMinutes % (24 * 60)) / 60)).padStart(2, '0')}:${String(endMinutes % 60).padStart(2, '0')}`;
      endLabel.textContent = `Ends at ${formatTime(normalized)}.`;
    };

    const syncReview = () => {
      const mode = scheduleMode();
      if (reviewClient) reviewClient.textContent = client?.value.trim() || 'Not entered yet';
      if (reviewSchedule) {
        if (mode === 'specific') {
          const rows = specificRows();
          if (!rows.length) {
            reviewSchedule.textContent = 'No specific dates selected';
          } else {
            const dates = rows.map((row) => row.querySelector('[data-specific-row-date]')?.value).filter(Boolean);
            const uniqueDates = Array.from(new Set(dates)).sort();
            const first = uniqueDates[0] ? formatShortDate(uniqueDates[0]) : '—';
            const last = uniqueDates.length > 1 ? formatShortDate(uniqueDates[uniqueDates.length - 1]) : '';
            reviewSchedule.textContent = `${rows.length} selected occurrence${rows.length === 1 ? '' : 's'}${first !== '—' ? ` · ${first}${last ? ` to ${last}` : ''}` : ''}`;
          }
        } else {
          const range = rangeStart?.value && rangeEnd?.value ? `${rangeStart.value} to ${rangeEnd.value}` : 'Choose date range';
          reviewSchedule.textContent = `${range} · ${formatTime(startTime?.value)} · ${Number(duration?.value) === 0.5 ? '30 min' : `${duration?.value || '—'} hr${Number(duration?.value) === 1 ? '' : 's'}`}`;
        }
      }
      if (reviewMode) {
        if (mode === 'specific') {
          reviewMode.textContent = 'Specific Dates · individual times';
        } else {
          const labels = checkedWeekdays().map((day) => dayNames[day]);
          reviewMode.textContent = labels.length ? `Recurring · ${labels.join(', ')}` : 'Recurring · no days selected';
        }
      }
      if (reviewBooking) {
        const typeLabel = type?.selectedOptions?.[0]?.textContent?.trim() || 'Reservation';
        const coolingLabel = cooling?.selectedOptions?.[0]?.textContent?.trim() || '';
        reviewBooking.textContent = `${typeLabel} · ${packageLabel()} · ${guests?.value || '—'} guests · ${coolingLabel}`;
      }
      if (lastResult) {
        if (reviewAvailable) {
          const conflictText = lastResult.conflict_count ? ` · ${lastResult.conflict_count} conflict${lastResult.conflict_count === 1 ? '' : 's'}` : '';
          reviewAvailable.textContent = `${lastResult.available_count} of ${lastResult.occurrence_count}${conflictText}`;
        }
        if (reviewTotal) reviewTotal.textContent = lastResult.pricing_known ? currency.format(Number(lastResult.available_total || 0)) : 'Enter valid guest count';
      }
    };

    const setState = (state, heading, text) => {
      statusBox.dataset.state = state;
      form.dataset.batchAvailabilityState = state;
      if (statusTitle) statusTitle.textContent = heading;
      if (statusMessage) statusMessage.textContent = text;
      if (icon) icon.textContent = state === 'available' ? '✓' : state === 'partial' ? '!' : state === 'unavailable' ? '×' : state === 'checking' ? '…' : state === 'invalid' || state === 'warning' ? '!' : '○';
      if (scheduleNext) scheduleNext.disabled = state === 'checking' || state === 'unavailable' || state === 'invalid' || state === 'idle';
      if (createButton) {
        const partialNeedsSkip = state === 'partial' && !skipInput?.checked;
        createButton.disabled = state === 'checking' || state === 'unavailable' || state === 'invalid' || state === 'idle' || partialNeedsSkip;
      }
    };

    const currentParams = () => {
      const mode = scheduleMode();
      const params = new URLSearchParams({
        schedule_mode: mode,
        allow_past_dates: pastDateToggle?.checked ? '1' : '0',
        reservation_type: type?.value || 'basketball',
        guest_count: guests?.value || '',
        cooling_option: cooling?.value || 'fan',
        setup_minutes: type?.value === 'event' ? (setup?.value || '0') : '0',
        cleanup_minutes: type?.value === 'event' ? (cleanup?.value || '0') : '0',
        shower_room_addon: shower?.checked ? '1' : '0',
        shower_room_complimentary: shower?.checked && showerComplimentary?.checked ? '1' : '0',
        equipment_bundle_addon: equipment?.checked ? '1' : '0',
        equipment_bundle_complimentary: equipment?.checked && equipmentComplimentary?.checked ? '1' : '0',
      });
      if (mode === 'specific') {
        specificRows().forEach((row) => {
          params.append('specific_date[]', row.querySelector('[data-specific-row-date]')?.value || '');
          params.append('specific_start_time[]', row.querySelector('[data-specific-row-start]')?.value || '');
          params.append('specific_duration_hours[]', row.querySelector('[data-specific-row-duration]')?.value || '');
        });
      } else {
        params.set('range_start', rangeStart?.value || '');
        params.set('range_end', rangeEnd?.value || '');
        params.set('start_time', startTime?.value || '');
        params.set('duration_hours', duration?.value || '');
        checkedWeekdays().forEach((day) => params.append('weekdays[]', String(day)));
      }
      return params;
    };

    const scheduleReady = () => {
      if (scheduleMode() === 'specific') {
        const rows = specificRows();
        return rows.length > 0 && rows.every((row) => {
          return Boolean(
            row.querySelector('[data-specific-row-date]')?.value
            && row.querySelector('[data-specific-row-start]')?.value
            && row.querySelector('[data-specific-row-duration]')?.value
          );
        });
      }
      return Boolean(rangeStart?.value && rangeEnd?.value && startTime?.value && duration?.value && checkedWeekdays().length);
    };

    const renderSpecificRowStatuses = (data) => {
      if (scheduleMode() !== 'specific') return;
      const rows = specificRows();
      rows.forEach((row) => setSpecificRowStatus(row, 'waiting', 'Waiting'));
      (data.rows || []).forEach((result) => {
        const row = rows[Number(result.source_index)];
        if (!row) return;
        setSpecificRowStatus(row, result.available ? 'available' : 'conflict', result.available ? 'Available' : (result.conflict_source === 'selected' ? 'Overlaps Selected' : 'Conflict'));
      });
    };

    const discountStateForGross = (grossTotal) => {
      const gross = Number(grossTotal || 0);
      const enabled = Boolean(applyDiscount?.checked);
      const requested = Number(discountInput?.value || 0);
      const valid = enabled && gross > 0 && requested >= 0.01 && requested < gross;
      const discount = valid ? requested : 0;
      return {
        enabled,
        valid,
        discount,
        net: Math.max(0, gross - discount),
      };
    };

    const renderPriceEstimate = (data) => {
      if (!pricePanel) return;
      const summary = data?.pricing_summary;
      const pricingKnown = Boolean(data?.pricing_known && summary);

      if (!pricingKnown) {
        delete form.dataset.calculatedTotal;
        form.dispatchEvent(new CustomEvent('pricing-updated'));
        syncQuickPricePending(false);
        if (priceTotal) priceTotal.textContent = '—';
        if (pricePackage) pricePackage.textContent = 'Enter a valid guest count (1–400) to calculate the package and price.';
        if (priceScope) priceScope.textContent = scheduleReady() ? `${data?.available_count || 0} occurrence${Number(data?.available_count) === 1 ? '' : 's'} currently available` : 'Complete the schedule to calculate the batch.';
        if (priceLines) priceLines.innerHTML = '<div class="batch-price-line is-placeholder"><span>Price breakdown</span><strong>Waiting for pricing details</strong></div>';
        if (priceNote) priceNote.textContent = 'Rates are taken from the current Rates Settings. Setup and cleanup blocks are not billed.';
        return;
      }

      const availableOccurrences = Number(summary.available_occurrences || 0);
      const selectedOccurrences = Number(summary.selected_occurrences || 0);
      const conflicts = Number(data.conflict_count || 0);
      const availableTotalValue = Number(summary.available_total || 0);
      const selectedTotalValue = Number(summary.selected_total || 0);
      const discountState = discountStateForGross(availableTotalValue);
      const displayedTotalValue = discountState.valid ? discountState.net : availableTotalValue;
      const discountSuffix = discountState.valid
        ? ` · discount ${currency.format(discountState.discount)} · client payable ${currency.format(discountState.net)}`
        : '';
      form.dataset.calculatedTotal = String(availableTotalValue);
      form.dispatchEvent(new CustomEvent('pricing-updated'));
      if (quickPrice) quickPrice.classList.remove('pricing-error');
      if (quickPricePackage) quickPricePackage.textContent = summary.package_label || packageLabel();
      if (quickPriceTotal) quickPriceTotal.textContent = currency.format(displayedTotalValue);
      if (quickPriceMessage) {
        quickPriceMessage.textContent = conflicts
          ? `${availableOccurrences} of ${selectedOccurrences} occurrence${selectedOccurrences === 1 ? '' : 's'} available now · available calculated total ${currency.format(availableTotalValue)}${discountSuffix} · all selected dates ${currency.format(selectedTotalValue)}`
          : `${availableOccurrences} occurrence${availableOccurrences === 1 ? '' : 's'} · ${Number(summary.available_billable_hours || 0)} billable hour${Number(summary.available_billable_hours || 0) === 1 ? '' : 's'} · ${summary.cooling_label || cooling?.selectedOptions?.[0]?.textContent?.trim() || ''}${discountSuffix}`;
      }
      if (priceTotal) priceTotal.textContent = currency.format(displayedTotalValue);
      if (pricePackage) pricePackage.textContent = `${summary.package_label || packageLabel()} · ${summary.cooling_label || cooling?.selectedOptions?.[0]?.textContent?.trim() || ''}`;
      if (priceScope) {
        priceScope.textContent = conflicts
          ? `${availableOccurrences} of ${selectedOccurrences} occurrence${selectedOccurrences === 1 ? '' : 's'} currently creatable`
          : `${availableOccurrences} occurrence${availableOccurrences === 1 ? '' : 's'} · ${Number(summary.available_billable_hours || 0)} billable hour${Number(summary.available_billable_hours || 0) === 1 ? '' : 's'}`;
      }

      if (priceLines) {
        priceLines.replaceChildren();
        const addLine = (label, detail, amount, extraClass = '') => {
          const line = document.createElement('div');
          line.className = `batch-price-line${extraClass ? ` ${extraClass}` : ''}`;
          const copy = document.createElement('span');
          const title = document.createElement('strong');
          title.textContent = label;
          copy.appendChild(title);
          if (detail) {
            const small = document.createElement('small');
            small.textContent = detail;
            copy.appendChild(small);
          }
          const value = document.createElement('b');
          value.textContent = amount;
          line.append(copy, value);
          priceLines.appendChild(line);
        };

        const groups = Array.isArray(summary.rate_groups) ? summary.rate_groups : [];
        groups.forEach((group) => {
          let rateLabel = group.package_label || 'Venue';
          if (group.package === 'regular' && group.rate_period) {
            rateLabel += ` · ${group.rate_period === 'introductory' ? 'Introductory' : 'Regular'} rate`;
          }
          const hours = Number(group.billable_hours || 0);
          const occurrences = Number(group.occurrence_count || 0);
          addLine(
            rateLabel,
            `${hours} hr${hours === 1 ? '' : 's'} × ${currency.format(Number(group.hourly_rate || 0))}/hr · ${occurrences} occurrence${occurrences === 1 ? '' : 's'}`,
            currency.format(Number(group.subtotal || 0))
          );
        });

        const showerSubtotal = Number(summary.shower_subtotal || 0);
        if (summary.shower_room_selected) {
          addLine(
            'Shower Room',
            summary.shower_room_complimentary ? `Complimentary on ${availableOccurrences} occurrence${availableOccurrences === 1 ? '' : 's'}` : `${availableOccurrences} occurrence${availableOccurrences === 1 ? '' : 's'}`,
            summary.shower_room_complimentary ? 'Complimentary' : currency.format(showerSubtotal)
          );
        }

        const equipmentSubtotal = Number(summary.equipment_subtotal || 0);
        if (summary.equipment_bundle_included) {
          addLine('Equipment Bundle', 'Included with Tournament / Big Event package', 'Included');
        } else if (summary.equipment_bundle_selected) {
          addLine(
            'Equipment Bundle',
            summary.equipment_bundle_complimentary
              ? `Complimentary on ${availableOccurrences} occurrence${availableOccurrences === 1 ? '' : 's'}`
              : `${Number(summary.available_billable_hours || 0)} billable hr${Number(summary.available_billable_hours || 0) === 1 ? '' : 's'} across ${availableOccurrences} occurrence${availableOccurrences === 1 ? '' : 's'}`,
            summary.equipment_bundle_complimentary ? 'Complimentary' : currency.format(equipmentSubtotal)
          );
        }

        if (discountState.valid) {
          addLine(
            'Calculated Batch Total',
            conflicts ? 'Currently available occurrences only' : 'All selected occurrences',
            currency.format(availableTotalValue)
          );
          addLine(
            'Flexible Discount',
            discountReason?.value.trim() || 'Admin-applied discount',
            `−${currency.format(discountState.discount)}`,
            'is-discount'
          );
          addLine('Client Payable', 'Calculated total less flexible discount', currency.format(discountState.net), 'is-total');
        } else {
          addLine('Estimated Batch Total', conflicts ? 'Currently available occurrences only' : 'All selected occurrences', currency.format(availableTotalValue), 'is-total');
        }
      }

      if (priceNote) {
        let note;
        if (conflicts) {
          const selectedTotal = currency.format(Number(summary.selected_total || 0));
          note = `${conflicts} conflicting occurrence${conflicts === 1 ? ' is' : 's are'} excluded from the current creatable total. All selected occurrences would total ${selectedTotal}. Setup and cleanup blocks are not billed.`;
        } else {
          note = 'Calculated from all selected occurrences using the current Rates Settings. Setup and cleanup blocks are not billed.';
        }
        if (discountState.valid) {
          note += ` Flexible discount of ${currency.format(discountState.discount)} reduces the client payable amount to ${currency.format(discountState.net)}.`;
        }
        priceNote.textContent = note;
      }
    };

    const clearPriceEstimate = () => {
      delete form.dataset.calculatedTotal;
      form.dispatchEvent(new CustomEvent('pricing-updated'));
      syncQuickPricePending(false);
      if (!pricePanel) return;
      if (priceTotal) priceTotal.textContent = '—';
      if (pricePackage) pricePackage.textContent = 'Enter the guest count to calculate the package.';
      if (priceScope) priceScope.textContent = 'Complete the schedule to calculate the batch.';
      if (priceLines) priceLines.innerHTML = '<div class="batch-price-line is-placeholder"><span>Price breakdown</span><strong>Waiting for schedule</strong></div>';
      if (priceNote) priceNote.textContent = 'Rates are taken from the current Rates Settings. Setup and cleanup blocks are not billed.';
    };

    const renderRows = (data) => {
      if (counts) counts.hidden = false;
      if (totalCount) totalCount.textContent = String(data.occurrence_count || 0);
      if (availableCount) availableCount.textContent = String(data.available_count || 0);
      if (conflictCount) conflictCount.textContent = String(data.conflict_count || 0);

      const conflicts = Array.isArray(data.rows) ? data.rows.filter((row) => !row.available) : [];
      if (conflictChips) {
        conflictChips.replaceChildren();
        if (conflicts.length) {
          conflictChips.hidden = false;
          conflicts.slice(0, 8).forEach((row) => {
            const chip = document.createElement('span');
            chip.textContent = `${row.date_label} · ${row.start_label}`;
            conflictChips.appendChild(chip);
          });
          if (conflicts.length > 8) {
            const more = document.createElement('span');
            more.textContent = `+${conflicts.length - 8} more`;
            conflictChips.appendChild(more);
          }
        } else {
          conflictChips.hidden = true;
        }
      }

      if (rowsBody) {
        rowsBody.replaceChildren();
        (data.rows || []).forEach((row) => {
          const tr = document.createElement('tr');
          if (!row.available) tr.classList.add('batch-row-conflict');
          const dateCell = document.createElement('td');
          dateCell.dataset.label = 'Date';
          dateCell.dataset.priority = 'primary';
          const dateStrong = document.createElement('strong');
          dateStrong.textContent = row.date_label;
          dateCell.appendChild(dateStrong);
          const timeCell = document.createElement('td');
          timeCell.dataset.label = 'Time';
          timeCell.textContent = `${row.start_label} – ${row.end_label}`;
          const amountCell = document.createElement('td');
          amountCell.dataset.label = 'Amount';
          amountCell.textContent = row.amount === null ? '—' : currency.format(Number(row.amount));
          const availabilityCell = document.createElement('td');
          availabilityCell.dataset.label = 'Availability';
          const pill = document.createElement('span');
          pill.className = `status-pill ${row.available ? 'status-success' : 'status-danger'}`;
          pill.textContent = row.available ? 'Available' : (row.conflict_source === 'selected' ? 'Overlaps Selected' : 'Conflict');
          availabilityCell.appendChild(pill);
          tr.append(dateCell, timeCell, amountCell, availabilityCell);
          rowsBody.appendChild(tr);
        });
      }

      renderSpecificRowStatuses(data);
      renderPriceEstimate(data);
      const hasConflicts = Number(data.conflict_count || 0) > 0;
      const hasAvailable = Number(data.available_count || 0) > 0;
      if (skipWrap) skipWrap.hidden = !(hasConflicts && hasAvailable);
      if (skipInput && !hasConflicts) skipInput.checked = false;
      if (allConflicts) allConflicts.hidden = hasAvailable;
      if (reviewStatus) reviewStatus.textContent = data.message || 'Availability checked.';
      syncReview();
    };

    const clearResults = () => {
      lastResult = null;
      if (counts) counts.hidden = true;
      if (conflictChips) conflictChips.hidden = true;
      if (skipWrap) skipWrap.hidden = true;
      if (allConflicts) allConflicts.hidden = true;
      if (rowsBody) rowsBody.innerHTML = '<tr><td colspan="4" class="muted">Complete the schedule to check dates.</td></tr>';
      if (reviewAvailable) reviewAvailable.textContent = '—';
      if (reviewTotal) reviewTotal.textContent = '—';
      if (reviewStatus) reviewStatus.textContent = 'Complete the schedule to check availability.';
      clearPriceEstimate();
      specificRows().forEach((row) => setSpecificRowStatus(row, 'waiting', 'Waiting'));
    };

    const checkAvailability = async () => {
      syncMode();
      syncType();
      updateEndLabel();
      syncReview();
      if (!scheduleReady()) {
        requestController?.abort();
        lastKey = '';
        clearResults();
        const prompt = scheduleMode() === 'specific'
          ? 'Add at least one date, then choose a start time and duration for every row.'
          : 'Choose the date range, recurring days, start time, and duration to check the batch.';
        setState('idle', 'Live conflict check', prompt);
        return;
      }

      const params = currentParams();
      const key = params.toString();
      if (key === lastKey && lastResult) return;
      lastKey = key;
      requestController?.abort();
      requestController = new AbortController();
      specificRows().forEach((row) => setSpecificRowStatus(row, 'checking', 'Checking…'));
      syncQuickPricePending(true);
      setState('checking', 'Checking selected occurrences…', 'Cross-checking every selected date and time against the booking calendar.');
      if (reviewStatus) reviewStatus.textContent = 'Checking selected dates…';

      try {
        const response = await fetch(endpoint, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          },
          body: params.toString(),
          cache: 'no-store',
          signal: requestController.signal,
        });
        const data = await response.json();
        if (!response.ok && data.status === 'invalid') {
          lastResult = null;
          clearResults();
          setState('invalid', 'Review the selected schedule', data.message || 'Please review the selected batch schedule.');
          return;
        }
        if (!response.ok) {
          lastResult = null;
          syncQuickPricePending(false);
          if (quickPriceMessage && scheduleReady() && Number(guests?.value || 0) >= 1 && Number(guests?.value || 0) <= 400) {
            quickPriceMessage.textContent = 'Live price calculation is temporarily unavailable. Final pricing will still be calculated when the batch is created.';
          }
          setState('warning', 'Live check unavailable', data.message || 'The batch will still be checked before creation.');
          if (reviewStatus) reviewStatus.textContent = 'Live check unavailable; final server check will still run.';
          specificRows().forEach((row) => setSpecificRowStatus(row, 'waiting', 'Recheck later'));
          return;
        }
        lastResult = data;
        renderRows(data);
        if (data.status === 'available') setState('available', 'All selected occurrences are available', data.message);
        else if (data.status === 'partial') setState('partial', 'Some dates or times have conflicts', data.message);
        else if (data.status === 'unavailable') setState('unavailable', 'No selected occurrences are available', data.message);
        else setState('warning', 'Check the selected schedule', data.message || 'Review the selected dates.');
      } catch (error) {
        if (error?.name === 'AbortError') return;
        lastResult = null;
        syncQuickPricePending(false);
        if (quickPriceMessage && scheduleReady() && Number(guests?.value || 0) >= 1 && Number(guests?.value || 0) <= 400) {
          quickPriceMessage.textContent = 'Live price calculation is temporarily unavailable. Final pricing will still be calculated when the batch is created.';
        }
        setState('warning', 'Live check unavailable', 'The live calendar check could not be completed. The server will still check every occurrence before creation.');
        if (reviewStatus) reviewStatus.textContent = 'Live check unavailable; final server check will still run.';
        specificRows().forEach((row) => setSpecificRowStatus(row, 'waiting', 'Recheck later'));
      }
    };

    const scheduleCheck = () => {
      window.clearTimeout(debounceTimer);
      lastKey = '';
      syncMode();
      syncType();
      updateEndLabel();
      syncReview();
      if (!scheduleReady()) {
        clearResults();
        setState('idle', 'Live conflict check', scheduleMode() === 'specific'
          ? 'Add at least one date, then choose a start time and duration for every row.'
          : 'Choose the date range, recurring days, start time, and duration to check the batch.');
        return;
      }
      specificRows().forEach((row) => setSpecificRowStatus(row, 'checking', 'Checking…'));
      syncQuickPricePending(true);
      setState('checking', 'Checking selected occurrences…', 'Cross-checking every selected date and time against the booking calendar.');
      debounceTimer = window.setTimeout(checkAvailability, 220);
    };

    form.querySelectorAll('[data-select-weekdays]').forEach((button) => button.addEventListener('click', () => {
      const mode = button.dataset.selectWeekdays;
      weekdays.forEach((input) => {
        const day = Number(input.value);
        input.checked = mode === 'all' || (mode === 'weekdays' && day >= 1 && day <= 5);
        if (mode === 'none') input.checked = false;
      });
      scheduleCheck();
    }));

    specificAddButton?.addEventListener('click', () => {
      if (!specificDatePicker?.value) {
        specificDatePicker?.setCustomValidity('Choose a date to add.');
        specificDatePicker?.reportValidity();
        specificDatePicker?.setCustomValidity('');
        return;
      }
      addSpecificRow({
        date: specificDatePicker.value,
        start_time: specificDefaultStart?.value || '08:00',
        duration_hours: specificDefaultDuration?.value || '2',
      });
      specificDatePicker.value = '';
      scheduleCheck();
    });

    specificDatePicker?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        specificAddButton?.click();
      }
    });

    specificApplyAll?.addEventListener('click', () => {
      const defaultStart = specificDefaultStart?.value || '08:00';
      const defaultDuration = specificDefaultDuration?.value || '2';
      specificRows().forEach((row) => {
        const startField = row.querySelector('[data-specific-row-start]');
        const durationField = row.querySelector('[data-specific-row-duration]');
        if (startField) startField.value = defaultStart;
        if (durationField) durationField.value = defaultDuration;
      });
      scheduleCheck();
    });

    pastDateToggle?.addEventListener('change', () => {
      syncPastDateAccess();
      scheduleCheck();
    });

    modeInputs.forEach((input) => input.addEventListener('change', () => {
      if (!input.checked) return;
      syncMode();
      scheduleCheck();
    }));

    const syncBatchComplimentaryShower = () => {
      if (!showerComplimentary) return;
      showerComplimentary.disabled = !shower?.checked;
      if (!shower?.checked) showerComplimentary.checked = false;
    };
    shower?.addEventListener('change', syncBatchComplimentaryShower);
    syncBatchComplimentaryShower();

    const syncBatchComplimentaryEquipment = () => {
      if (!equipmentComplimentary) return;
      const guestCount = Number.parseInt(guests?.value || '', 10);
      const includedByPackage = Number.isFinite(guestCount) && guestCount >= 30 && guestCount <= 400;
      equipmentComplimentary.disabled = includedByPackage || !equipment?.checked;
      if (includedByPackage || !equipment?.checked) equipmentComplimentary.checked = false;
    };
    equipment?.addEventListener('change', syncBatchComplimentaryEquipment);
    guests?.addEventListener('input', syncBatchComplimentaryEquipment);
    guests?.addEventListener('change', syncBatchComplimentaryEquipment);
    syncBatchComplimentaryEquipment();

    [rangeStart, rangeEnd, startTime, duration, type, guests, cooling, setup, cleanup, shower, showerComplimentary, equipment, equipmentComplimentary, ...weekdays]
      .filter(Boolean)
      .forEach((field) => {
        field.addEventListener('change', scheduleCheck);
        if (field === guests) field.addEventListener('input', scheduleCheck);
      });

    [client, initialStatus].filter(Boolean).forEach((field) => {
      field.addEventListener('input', syncReview);
      field.addEventListener('change', syncReview);
    });

    skipInput?.addEventListener('change', () => {
      const state = form.dataset.batchAvailabilityState || 'idle';
      setState(state, statusTitle?.textContent || 'Availability checked', statusMessage?.textContent || '');
    });

    const refreshDiscountedBatchPrice = () => {
      if (lastResult) renderPriceEstimate(lastResult);
    };
    applyDiscount?.addEventListener('change', refreshDiscountedBatchPrice);
    discountInput?.addEventListener('input', refreshDiscountedBatchPrice);
    discountInput?.addEventListener('change', refreshDiscountedBatchPrice);
    discountReason?.addEventListener('input', refreshDiscountedBatchPrice);

    form.addEventListener('submit', (event) => {
      const state = form.dataset.batchAvailabilityState || 'idle';
      if (state === 'unavailable' || state === 'invalid' || state === 'idle' || (state === 'partial' && !skipInput?.checked)) {
        event.preventDefault();
        if (state === 'partial' && !skipInput?.checked) {
          skipWrap?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
          schedulePanel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }
    });

    syncMode();
    syncType();
    updateEndLabel();
    syncReview();
    checkAvailability();
  });
});

// ============================================================================
// PWA FOUNDATION - v1.0.97
// Registers the root service worker, exposes the browser install prompt when
// available, reports connection loss, and offers a controlled refresh when a
// newer service worker has finished installing. Transaction rules remain PHP-side.
// ============================================================================
(() => {
  const appScript = Array.from(document.scripts).find((script) => /\/assets\/js\/app\.js(?:\?|$)/.test(script.src));
  if (!appScript) return;

  const appRoot = new URL('../../', appScript.src);
  const serviceWorkerUrl = new URL('service-worker.js', appRoot);
  const installButton = document.querySelector('[data-pwa-install]');
  const standalone = window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true;
  let deferredInstallPrompt = null;
  let onlineTimer = null;

  // CONNECTION STATUS: offline remains visible; the online recovery notice fades
  // after a short confirmation so it does not become permanent interface clutter.
  const connectionNotice = document.createElement('div');
  connectionNotice.className = 'pwa-connection-notice';
  connectionNotice.setAttribute('role', 'status');
  connectionNotice.setAttribute('aria-live', 'polite');
  connectionNotice.hidden = true;
  document.body.appendChild(connectionNotice);

  const showConnectionState = (online) => {
    window.clearTimeout(onlineTimer);
    connectionNotice.hidden = false;
    connectionNotice.classList.toggle('is-offline', !online);
    connectionNotice.classList.toggle('is-online', online);
    connectionNotice.textContent = online
      ? 'Back online — live reservation services are available.'
      : 'You’re offline — reservations, availability, payments, and admin actions require internet.';
    if (online) {
      onlineTimer = window.setTimeout(() => {
        connectionNotice.hidden = true;
      }, 3600);
    }
  };

  if (!navigator.onLine) showConnectionState(false);
  window.addEventListener('offline', () => showConnectionState(false));
  window.addEventListener('online', () => showConnectionState(true));

  // INSTALL PROMPT: Chromium-based browsers expose beforeinstallprompt. Other
  // platforms continue to use their normal browser/OS Add to Home Screen flow.
  if (installButton && standalone) installButton.hidden = true;
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;
    if (installButton && !standalone) installButton.hidden = false;
  });

  installButton?.addEventListener('click', async () => {
    if (!deferredInstallPrompt) return;
    installButton.disabled = true;
    try {
      await deferredInstallPrompt.prompt();
      await deferredInstallPrompt.userChoice;
    } finally {
      deferredInstallPrompt = null;
      installButton.disabled = false;
      installButton.hidden = true;
    }
  });

  window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    if (installButton) installButton.hidden = true;
  });

  if (!('serviceWorker' in navigator) || !['http:', 'https:'].includes(window.location.protocol)) return;

  const showUpdateAvailable = (registration) => {
    if (!registration.waiting || document.querySelector('[data-pwa-update-notice]')) return;
    const notice = document.createElement('div');
    notice.className = 'pwa-update-notice';
    notice.dataset.pwaUpdateNotice = 'true';
    notice.setAttribute('role', 'status');
    notice.innerHTML = '<span><strong>App update ready.</strong> Refresh when convenient to use the latest version.</span><button type="button">Refresh</button>';
    document.body.appendChild(notice);

    notice.querySelector('button')?.addEventListener('click', () => {
      let reloading = false;
      navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (reloading) return;
        reloading = true;
        window.location.reload();
      });
      registration.waiting?.postMessage({ type: 'SKIP_WAITING' });
    });
  };

  window.addEventListener('load', async () => {
    try {
      const registration = await navigator.serviceWorker.register(serviceWorkerUrl.href, {
        scope: appRoot.pathname,
        updateViaCache: 'none',
      });

      if (registration.waiting && navigator.serviceWorker.controller) {
        showUpdateAvailable(registration);
      }

      registration.addEventListener('updatefound', () => {
        const worker = registration.installing;
        worker?.addEventListener('statechange', () => {
          if (worker.state === 'installed' && navigator.serviceWorker.controller) {
            showUpdateAvailable(registration);
          }
        });
      });

      // Ask the browser to check the small service-worker file on normal visits;
      // the worker itself controls cache version cleanup and never caches admin data.
      registration.update().catch(() => {});
    } catch (error) {
      // PWA enhancement failure must never prevent the website from functioning.
      console.warn('The Leisure Hub PWA registration was unavailable.', error);
    }
  });
})();


// ============================================================================
// RESERVATION LIST ACTION MENUS - v1.0.98
// Keeps only one inline More menu expanded at a time and closes it when the
// admin clicks elsewhere or presses Escape. The links/forms remain server-owned.
// ============================================================================
(() => {
  const menus = Array.from(document.querySelectorAll('.reservation-row-more'));
  if (!menus.length) return;

  menus.forEach((menu) => {
    menu.addEventListener('toggle', () => {
      if (!menu.open) return;
      menus.forEach((other) => {
        if (other !== menu) other.removeAttribute('open');
      });
    });
  });

  document.addEventListener('click', (event) => {
    menus.forEach((menu) => {
      if (menu.open && !menu.contains(event.target)) menu.removeAttribute('open');
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    menus.forEach((menu) => menu.removeAttribute('open'));
  });
})();


// Native booking safeguards and utility actions - v1.2.122.
document.querySelectorAll('[data-copy-reference]').forEach((button) => {
  button.addEventListener('click', async () => {
    const value = button.dataset.copyReference || '';
    if (!value) return;
    const original = button.innerHTML;
    try {
      await navigator.clipboard.writeText(value);
      button.textContent = '✓ Copied';
    } catch (error) {
      const input = document.createElement('textarea');
      input.value = value;
      input.setAttribute('readonly', '');
      input.style.position = 'fixed';
      input.style.opacity = '0';
      document.body.appendChild(input);
      input.select();
      document.execCommand('copy');
      input.remove();
      button.textContent = '✓ Copied';
    }
    window.setTimeout(() => { button.innerHTML = original; }, 1800);
  });
});

document.querySelectorAll('form[data-public-request]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return;
    if (form.dataset.submitLocked === '1') {
      event.preventDefault();
      return;
    }
    form.dataset.submitLocked = '1';
    const submit = form.querySelector('button[type="submit"]');
    if (submit) {
      submit.dataset.originalText = submit.textContent || '';
      submit.textContent = 'Submitting…';
      submit.disabled = true;
      submit.setAttribute('aria-busy', 'true');
    }
  });
});

// ============================================================================
// EXPERIENCE THE HUB - v1.2.132
// Reveals the photo-led homepage stories only as they enter the viewport. The
// content remains fully visible when IntersectionObserver/JS is unavailable.
// ============================================================================
(() => {
  const items = Array.from(document.querySelectorAll('[data-experience-reveal]'));
  if (!items.length) return;

  const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  if (reducedMotion || !('IntersectionObserver' in window)) {
    items.forEach((item) => item.classList.add('is-visible'));
    return;
  }

  document.documentElement.classList.add('tlh-experience-motion');
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-visible');
      observer.unobserve(entry.target);
    });
  }, {
    threshold: 0.14,
    rootMargin: '0px 0px -6% 0px',
  });

  items.forEach((item) => observer.observe(item));
})();
