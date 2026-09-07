/*
 * THE LEISURE HUB - BRANDED LAUNCH EXPERIENCE
 *
 * Plays the TLH splash only once per browser/app installation storage.
 * After the first launch, navigation, reloads, app resumes, and future opens go
 * directly to the interface. Clearing site/app storage allows the splash to show again.
 */
(function () {
  'use strict';

  // Restore the direction chosen by the previous bottom-navigation tap before
  // stylesheets load. This lets cross-document View Transitions animate the
  // incoming PHP page from the correct side without a first-frame flash.
  const pageSwipeKey = 'tlhBottomNavSwipe';
  try {
    const storedSwipe = JSON.parse(sessionStorage.getItem(pageSwipeKey) || 'null');
    if (storedSwipe && (storedSwipe.direction === 'forward' || storedSwipe.direction === 'backward') && (Date.now() - Number(storedSwipe.at || 0)) < 5000) {
      document.documentElement.dataset.tlhNavSwipe = storedSwipe.direction;
    }
    sessionStorage.removeItem(pageSwipeKey);
  } catch (error) {
    // Directional motion is progressive enhancement only.
  }

  const script = document.currentScript;
  const logoSrc = script?.dataset.logo || 'assets/img/tlh-logo.png';
  const tagline = script?.dataset.tagline || 'Designed For Premium Experience';
  const managementCredit = 'Managed by Puer Sanctus Property Management, Inc.';
  const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const phoneViewport = window.matchMedia('(max-width: 760px)').matches;
  const mobileViewport = window.matchMedia('(max-width: 950px)').matches;
  const params = new URLSearchParams(window.location.search);
  const source = (params.get('source') || '').toLowerCase();
  const pwaEntryMarker = source === 'pwa' || source === 'pwa-shortcut';
  const appContextKey = 'tlhPwaAppContext';
  let sessionAppContext = false;

  // Android can occasionally open a home-screen installation/shortcut without
  // reliably exposing display-mode: standalone. The manifest start_url and PWA
  // shortcuts include a source marker, so remember that launch for this tab's
  // session and use it as a safe fallback during subsequent in-app navigation.
  try {
    if (pwaEntryMarker) sessionStorage.setItem(appContextKey, '1');
    sessionAppContext = sessionStorage.getItem(appContextKey) === '1';
  } catch (error) {
    sessionAppContext = pwaEntryMarker;
  }

  const appContext = standalone || pwaEntryMarker || sessionAppContext;
  const tabletOrDesktopViewport = window.matchMedia('(min-width: 761px)').matches;
  const launchContext = phoneViewport || (appContext && mobileViewport) || tabletOrDesktopViewport;

  // v1.2.42: Phone browsers intentionally share the installed-app presentation
  // class so the same headerless shell, hero composition, bottom navigation,
  // safe-area geometry, and Admin mobile chrome are used in both contexts.
  // Actual install detection still relies on display-mode/manifest state above.
  if (appContext || phoneViewport) document.documentElement.classList.add('tlh-standalone-app');
  if (appContext) document.documentElement.classList.add('tlh-installed-app');

  // Browser and installed-app entries may both use the branded launch experience,
  // but each context records it persistently and will not replay it after first use.
  if (!launchContext) return;

  const navigationMarkerKey = 'tlhPwaInternalNavigationAt';
  const markerWindowMs = 12000;
  const splashSeenKey = appContext ? 'tlhSplashSeenApp' : 'tlhSplashSeenBrowser';
  const launchTotalMs = 4000;
  const launchExitMs = 360;
  let activeScreen = null;
  let cleanupTimer = null;
  let navigationMarkerClearTimer = null;

  const now = Date.now();
  let internalNavigationAt = 0;
  try {
    internalNavigationAt = Number(sessionStorage.getItem(navigationMarkerKey) || 0);
    sessionStorage.removeItem(navigationMarkerKey);
  } catch (error) {
    internalNavigationAt = 0;
  }

  // Persist the fact that the branded splash has already been shown. localStorage
  // survives normal app closes/reopens and page reloads. sessionStorage is used as
  // a graceful fallback in privacy modes where persistent storage is unavailable.
  let splashAlreadySeen = false;
  try {
    splashAlreadySeen = localStorage.getItem(splashSeenKey) === '1';
  } catch (error) {
    try { splashAlreadySeen = sessionStorage.getItem(splashSeenKey) === '1'; } catch (fallbackError) {}
  }

  const shouldPlayInitial = !splashAlreadySeen;

  function markSplashSeen() {
    try {
      localStorage.setItem(splashSeenKey, '1');
      return;
    } catch (error) {
      try { sessionStorage.setItem(splashSeenKey, '1'); } catch (fallbackError) {}
    }
  }

  function setHeroLogoReady(ready) {
    document.documentElement.classList.toggle('tlh-hero-logo-ready', ready);
    document.documentElement.classList.toggle('tlh-hero-logo-wait', !ready);
  }

  if (shouldPlayInitial) {
    document.documentElement.classList.add('tlh-launch-preparing');
    setHeroLogoReady(false);
  } else {
    setHeroLogoReady(true);
  }

  // Never leave the interface hidden if a browser interrupts the animation.
  window.setTimeout(() => {
    document.documentElement.classList.remove('tlh-launch-preparing');
    setHeroLogoReady(true);
  }, 5200);

  function markInternalNavigation() {
    try {
      sessionStorage.setItem(navigationMarkerKey, String(Date.now()));
      if (navigationMarkerClearTimer) window.clearTimeout(navigationMarkerClearTimer);
      // If another script cancels this click/submit, clear the marker on the
      // still-open page. A real navigation unloads this timer before it fires.
      navigationMarkerClearTimer = window.setTimeout(() => {
        try { sessionStorage.removeItem(navigationMarkerKey); } catch (error) {}
      }, 1800);
    } catch (error) {
      // Navigation remains fully functional when sessionStorage is unavailable.
    }
  }

  function buildScreen() {
    const screen = document.createElement('div');
    screen.className = 'tlh-launch-screen';
    screen.setAttribute('aria-hidden', 'true');
    screen.innerHTML = `
      <div class="tlh-launch-halo" aria-hidden="true"></div>
      <div class="tlh-launch-lockup">
        <div class="tlh-launch-logo-wrap">
          <img class="tlh-launch-logo" src="${logoSrc}" alt="" decoding="sync">
        </div>
        <span class="tlh-launch-accent" aria-hidden="true"></span>
        <p class="tlh-launch-tagline"></p>
        <p class="tlh-launch-management"></p>
      </div>`;
    screen.querySelector('.tlh-launch-tagline').textContent = tagline;
    screen.querySelector('.tlh-launch-management').textContent = managementCredit;
    return screen;
  }

  function finishLaunch(screen) {
    if (!screen || screen !== activeScreen) return;
    screen.classList.add('is-leaving');
    window.setTimeout(() => {
      screen.remove();
      if (activeScreen === screen) activeScreen = null;
      document.documentElement.classList.remove('tlh-launch-running', 'tlh-launch-preparing');
      setHeroLogoReady(true);
    }, 360);
  }

  function playLaunch() {
    if (activeScreen || !document.body) return;

    markSplashSeen();
    setHeroLogoReady(false);
    const screen = buildScreen();
    activeScreen = screen;
    document.body.appendChild(screen);
    document.documentElement.classList.remove('tlh-launch-preparing');
    document.documentElement.classList.add('tlh-launch-running');

    // Force the initial frame before starting the entrance animation.
    void screen.offsetWidth;
    screen.classList.add('is-playing');

    if (cleanupTimer) window.clearTimeout(cleanupTimer);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    cleanupTimer = window.setTimeout(() => finishLaunch(screen), reducedMotion ? 650 : (launchTotalMs - launchExitMs));
  }

  function bindNavigationMarkers() {
    document.addEventListener('click', (event) => {
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      const link = event.target.closest('a[href]');
      if (!link || link.target === '_blank' || link.hasAttribute('download')) return;
      const href = link.getAttribute('href') || '';
      if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('javascript:')) return;
      try {
        const destination = new URL(link.href, window.location.href);
        if (destination.origin === window.location.origin) markInternalNavigation();
      } catch (error) {
        // Ignore malformed/non-navigation links.
      }
    }, true);

    document.addEventListener('submit', (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) return;
      try {
        const destination = new URL(form.action || window.location.href, window.location.href);
        if (destination.origin === window.location.origin) markInternalNavigation();
      } catch (error) {
        markInternalNavigation();
      }
    }, true);
  }

  document.addEventListener('DOMContentLoaded', () => {
    bindNavigationMarkers();
    if (shouldPlayInitial) playLaunch();
  }, { once: true });

}());
