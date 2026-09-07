/* THE LEISURE HUB - MODERN EXPERIENCE ENHANCEMENTS (v1.2.0) */
document.addEventListener('DOMContentLoaded', () => {
  const body = document.body;

  // Premium reservation-card light streaks. The automatic sweep runs once in
  // a staggered sequence; later sweeps are interaction-driven only so the
  // homepage stays lively without becoming distracting.
  const bookingShineCards = Array.from(document.querySelectorAll('.home-reservation-premium .reservation-gateway-card'));
  const reduceBookingMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
  if (bookingShineCards.length && !reduceBookingMotion) {
    bookingShineCards.forEach((card, index) => {
      window.setTimeout(() => {
        card.classList.add('is-shine-intro');
        window.setTimeout(() => card.classList.remove('is-shine-intro'), 980);
      }, 640 + (index * 280));

      card.addEventListener('pointerdown', (event) => {
        if (event.pointerType === 'mouse' && window.matchMedia?.('(hover: hover) and (pointer: fine)').matches) return;
        card.classList.remove('is-shine-tap');
        // Force a fresh animation when the same card is tapped repeatedly.
        void card.offsetWidth;
        card.classList.add('is-shine-tap');
        window.setTimeout(() => card.classList.remove('is-shine-tap'), 620);
      }, { passive: true });
    });
  }

  // Directional bottom-navigation page swipe. The old document records the
  // direction immediately, and launch.js restores it before CSS on the next
  // PHP page so supported browsers can use a cross-document View Transition.
  const pageSwipeKey = 'tlhBottomNavSwipe';
  const swipeItems = Array.from(document.querySelectorAll('[data-page-swipe-index]'));
  const activeSwipeItem = () => swipeItems.find((item) => item.classList.contains('active') || item.getAttribute('aria-current') === 'page');

  // The destination-side marker only needs to survive the transition itself.
  // Clear it shortly after paint so ordinary links continue using the default
  // subtle fade/slide rather than inheriting the last bottom-nav direction.
  if (document.documentElement.dataset.tlhNavSwipe) {
    window.setTimeout(() => { delete document.documentElement.dataset.tlhNavSwipe; }, 700);
  }

  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const item = event.target.closest('a[data-page-swipe-index]');
    if (!item || !window.matchMedia('(max-width: 900px)').matches) return;
    const destination = new URL(item.href, window.location.href);
    if (destination.origin !== window.location.origin) return;
    if (destination.pathname === window.location.pathname && destination.search === window.location.search) return;

    const current = activeSwipeItem();
    const currentIndex = Number(current?.dataset.pageSwipeIndex ?? -1);
    const targetIndex = Number(item.dataset.pageSwipeIndex ?? -1);
    if (currentIndex < 0 || targetIndex < 0 || currentIndex === targetIndex) return;

    const direction = targetIndex > currentIndex ? 'forward' : 'backward';
    document.documentElement.dataset.tlhNavSwipe = direction;
    try {
      sessionStorage.setItem(pageSwipeKey, JSON.stringify({ direction, at: Date.now() }));
    } catch (error) {
      // Navigation proceeds normally when sessionStorage is unavailable.
    }
  }, true);

  // Immediate navigation feedback for server-rendered PHP page changes.
  const markNavigating = () => body.classList.add('is-navigating');
  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('a[href]');
    if (!link || link.target === '_blank' || link.hasAttribute('download')) return;
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin) return;
    if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;
    markNavigating();
  });
  window.addEventListener('pageshow', () => {
    body.classList.remove('is-navigating');
    document.querySelectorAll('[data-modern-original-label]').forEach((submitter) => {
      const original = submitter.dataset.modernOriginalLabel || '';
      if (submitter instanceof HTMLInputElement) submitter.value = original;
      else submitter.textContent = original;
      submitter.classList.remove('is-loading');
      submitter.removeAttribute('aria-busy');
      delete submitter.dataset.modernOriginalLabel;
    });
  });

  // Mobile PWA/public bottom navigation: accessible More sheet with focus return.
  const moreButton = document.querySelector('[data-mobile-more-open]');
  const moreSheet = document.querySelector('[data-mobile-more-sheet]');
  const moreClosers = Array.from(document.querySelectorAll('[data-mobile-more-close]'));
  let lastMoreTrigger = null;

  const setMoreOpen = (open) => {
    if (!moreSheet || !moreButton) return;
    if (open) {
      lastMoreTrigger = document.activeElement;
      moreSheet.hidden = false;
      moreSheet.setAttribute('aria-hidden', 'false');
      moreButton.setAttribute('aria-expanded', 'true');
      body.classList.add('mobile-more-open');
      requestAnimationFrame(() => moreSheet.querySelector('[data-mobile-more-close]')?.focus());
    } else {
      moreSheet.hidden = true;
      moreSheet.setAttribute('aria-hidden', 'true');
      moreButton.setAttribute('aria-expanded', 'false');
      body.classList.remove('mobile-more-open');
      if (lastMoreTrigger instanceof HTMLElement) lastMoreTrigger.focus();
    }
  };
  moreButton?.addEventListener('click', () => setMoreOpen(moreSheet?.hidden === true));
  moreClosers.forEach((element) => element.addEventListener('click', () => setMoreOpen(false)));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && moreSheet && !moreSheet.hidden) {
      setMoreOpen(false);
      return;
    }
    if (event.key === 'Tab' && moreSheet && !moreSheet.hidden) {
      const focusable = Array.from(moreSheet.querySelectorAll('a[href], button:not([disabled])')).filter((element) => !element.hidden);
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });

  // Contextual validation beneath fields, while server-side validation remains authoritative.
  const validationMessageFor = (field) => {
    if (field.validity.valueMissing) return 'Please complete this field.';
    if (field.validity.typeMismatch) return 'Please enter a valid value.';
    if (field.validity.rangeUnderflow) return `Please enter ${field.min} or more.`;
    if (field.validity.rangeOverflow) return `Please enter ${field.max} or less.`;
    if (field.validity.patternMismatch) return 'Please check the format and try again.';
    return field.validationMessage || 'Please check this field.';
  };
  const syncFieldValidation = (field, showMessage = false) => {
    if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement)) return;
    if (!field.willValidate) return;
    const group = field.closest('.form-group');
    if (!group) return;
    let error = group.querySelector('.field-error[data-modern-field-error]');
    const invalid = !field.validity.valid;
    field.setAttribute('aria-invalid', invalid ? 'true' : 'false');
    if (!invalid || !showMessage) {
      error?.remove();
      return;
    }
    if (!error) {
      error = document.createElement('span');
      error.className = 'field-error';
      error.dataset.modernFieldError = '';
      group.appendChild(error);
    }
    error.textContent = validationMessageFor(field);
  };
  document.querySelectorAll('input, select, textarea').forEach((field) => {
    field.addEventListener('invalid', () => syncFieldValidation(field, true));
    field.addEventListener('blur', () => syncFieldValidation(field, field.value !== ''));
    field.addEventListener('input', () => syncFieldValidation(field, false));
    field.addEventListener('change', () => syncFieldValidation(field, false));
  });

  // Deliberate submit feedback after native validation/other handlers allow submission.
  document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      window.setTimeout(() => {
        if (event.defaultPrevented) return;
        markNavigating();
        const submitter = event.submitter;
        if (!(submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement)) return;
        submitter.classList.add('is-loading');
        submitter.setAttribute('aria-busy', 'true');
        const original = submitter instanceof HTMLInputElement ? submitter.value : submitter.textContent;
        submitter.dataset.modernOriginalLabel = original || '';
        if (submitter instanceof HTMLInputElement) {
          submitter.value = 'Working…';
        } else {
          submitter.innerHTML = '<span class="modern-loading-mark" aria-hidden="true">◌</span><span>Working…</span>';
        }
      }, 0);
    });
  });

  // Keep live availability semantics explicit for assistive technology.
  document.querySelectorAll('[data-live-availability-status]').forEach((status) => {
    const observer = new MutationObserver(() => {
      const state = status.getAttribute('data-state');
      status.setAttribute('aria-busy', state === 'checking' ? 'true' : 'false');
    });
    observer.observe(status, { attributes: true, attributeFilter: ['data-state'] });
  });
});
