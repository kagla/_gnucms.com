(function () {
  'use strict';

  var configNode = document.getElementById('gnucms-privacy-config');
  if (!configNode) return;
  var config = JSON.parse(configNode.textContent);
  var european = config.mode === 'europe' || config.mode === 'europe_us';
  var usStates = config.mode === 'us_states' || config.mode === 'europe_us';
  var external = config.mode === 'external';
  var state = { analytics: false, advertising: false, adUserData: false, adPersonalization: false };
  var loaded = { analytics: false, advertising: false };
  var preferencesHandler = null;
  var visible = { europe: false, us_states: false, external: false };
  var selectingAgain = false;

  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
  window.gtag('consent', 'default', {
    analytics_storage: 'denied', ad_storage: 'denied',
    ad_user_data: 'denied', ad_personalization: 'denied'
  });

  function renderLinks() {
    document.querySelectorAll('[data-privacy-preferences]').forEach(function (button) {
      button.hidden = !visible[button.dataset.privacyPreferences];
    });
  }

  // Inert HTML is kept in JSON until permitted. Recreate scripts in their original
  // order, waiting for external scripts unless the supplied tag explicitly uses async.
  async function insertNode(node, parent, channel) {
    if (!state[channel]) return;
    if (node.nodeType !== 1) {
      parent.appendChild(node.cloneNode(true));
      return;
    }
    var element = node.cloneNode(false);
    if (node.tagName === 'SCRIPT') {
      element = document.createElement('script');
      Array.from(node.attributes).forEach(function (attribute) {
        element.setAttribute(attribute.name, attribute.value);
      });
      element.textContent = node.textContent;
      var executable = !node.type || /^(?:module|(?:text|application)\/(?:java|ecma)script)$/i.test(node.type);
      if (executable && node.hasAttribute('src') && !node.hasAttribute('async')) {
        element.async = false;
        await new Promise(function (resolve, reject) {
          element.onload = resolve;
          element.onerror = reject;
          parent.appendChild(element);
        });
      } else {
        parent.appendChild(element);
      }
      return;
    }
    parent.appendChild(element);
    // Template contents must remain inert, including any scripts inside them.
    if (node.tagName === 'TEMPLATE') {
      element.content.appendChild(node.content.cloneNode(true));
      return;
    }
    for (var child of Array.from(node.childNodes)) {
      await insertNode(child, element, channel);
    }
  }

  async function loadCode(channel) {
    if (loaded[channel] || !state[channel] || !config[channel]) return;
    loaded[channel] = true;
    var template = document.createElement('template');
    template.innerHTML = config[channel];
    try {
      for (var node of Array.from(template.content.childNodes)) {
        await insertNode(node, document.head, channel);
      }
    } catch (error) {
      // Do not log administrator-supplied code or retry partially executed tags.
      console.warn('GNUCMS: 외부 서비스 코드를 불러오지 못했습니다.');
    }
  }

  function update(next) {
    var revoked = Object.keys(loaded).some(function (channel) {
      return loaded[channel] && state[channel] && next[channel] === false;
    });
    Object.keys(state).forEach(function (key) {
      if (typeof next[key] === 'boolean') state[key] = next[key];
    });
    window.gtag('consent', 'update', {
      analytics_storage: state.analytics ? 'granted' : 'denied',
      ad_storage: state.advertising ? 'granted' : 'denied',
      ad_user_data: state.adUserData ? 'granted' : 'denied',
      ad_personalization: state.adPersonalization ? 'granted' : 'denied'
    });
    if (revoked) {
      // Scripts already running cannot be unloaded. The CMP must persist the new
      // decision before calling update; the next page starts with blocked tags.
      window.location.reload();
      return;
    }
    loadCode('analytics');
    loadCode('advertising');
  }

  // External CMP adapters own their region selection and consent records. These
  // hooks must be called from the CMP's documented readiness/change callbacks.
  if (external) {
    window.GnuCmsPrivacy = {
      update: function (next) { if (next && typeof next === 'object') update(next); },
      setPreferencesHandler: function (handler) {
        preferencesHandler = typeof handler === 'function' ? handler : null;
        visible.external = preferencesHandler !== null;
        renderLinks();
      }
    };
  } else {
    var euState = {
      analytics: !european, advertising: !european,
      adUserData: !european, adPersonalization: !european
    };
    var usAllowed = !usStates;
    var fc = window.googlefc = window.googlefc || {};
    fc.callbackQueue = fc.callbackQueue || [];

    function applyGoogleState() {
      var combined = {};
      Object.keys(euState).forEach(function (key) { combined[key] = euState[key] && usAllowed; });
      update(combined);
    }

    if (european) {
      fc.callbackQueue.push({ CONSENT_MODE_DATA_READY: function () {
        if (typeof fc.getGoogleConsentModeValues !== 'function') return;
        var values = fc.getGoogleConsentModeValues();
        // The API enum defines 1 as GRANTED and 3 as NOT_APPLICABLE.
        // UNKNOWN, DENIED, and NOT_CONFIGURED never unblock analytics.
        function permitted(value) { return value === 1 || value === 3; }
        euState = {
          analytics: permitted(values.analyticsStoragePurposeConsentStatus),
          advertising: permitted(values.adStoragePurposeConsentStatus),
          adUserData: permitted(values.adUserDataPurposeConsentStatus),
          adPersonalization: permitted(values.adPersonalizationPurposeConsentStatus)
        };
        applyGoogleState();
      } });
      fc.callbackQueue.push({ CONSENT_API_READY: function () {
        if (typeof window.__tcfapi !== 'function') return;
        window.__tcfapi('addEventListener', 2, function (data, success) {
          visible.europe = !!(success && data && data.gdprApplies && typeof fc.showRevocationMessage === 'function');
          renderLinks();
          if (!success || !data) return;
          if (data.eventStatus === 'cmpuishown' && loaded.analytics) selectingAgain = true;
          if (data.eventStatus === 'useractioncomplete' && selectingAgain) window.location.reload();
        });
      } });
    }
    if (usStates) {
      fc.usstatesoptout = fc.usstatesoptout || {};
      fc.usstatesoptout.overrideDnsLink = true;
      fc.callbackQueue.push({ INITIAL_US_STATES_OPT_OUT_DATA_READY: function () {
        if (typeof fc.usstatesoptout.getInitialUsStatesOptOutStatus !== 'function') return;
        var status = fc.usstatesoptout.getInitialUsStatesOptOutStatus();
        // Unknown or opted-out data stays blocked. Respect GPC as well.
        usAllowed = (status === 1 || status === 2) && navigator.globalPrivacyControl !== true;
        visible.us_states = status === 2 && typeof fc.usstatesoptout.openConfirmationDialog === 'function';
        renderLinks();
        applyGoogleState();
      } });
    }
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-privacy-preferences]');
    if (!button || button.hidden) return;
    var kind = button.dataset.privacyPreferences;
    if (kind === 'external' && preferencesHandler) preferencesHandler();
    if (kind === 'europe') {
      selectingAgain = true;
      window.googlefc.callbackQueue.push({ CONSENT_API_READY: function () {
        window.googlefc.showRevocationMessage();
      } });
    }
    if (kind === 'us_states') {
      window.googlefc.callbackQueue.push({ INITIAL_US_STATES_OPT_OUT_DATA_READY: function () {
        window.googlefc.usstatesoptout.openConfirmationDialog(function (optedOut) {
          if (!optedOut) return;
          usAllowed = false;
          visible.us_states = false;
          renderLinks();
          applyGoogleState();
        });
      } });
    }
  });
  document.addEventListener('DOMContentLoaded', renderLinks);
})();
