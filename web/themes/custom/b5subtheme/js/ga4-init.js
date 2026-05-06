/**
 * @file
 * GA4 / gtag bootstrap.
 *
 * Reads tag id and content classification from drupalSettings.b5subtheme.ga4
 * (set in b5subtheme_preprocess_html()), then mirrors the original inline
 * <script> sequence formerly in templates/page/html.html.twig. Externalised
 * to allow CSP without 'unsafe-inline' on script-src.
 */
(function (drupalSettings) {
  'use strict';

  var settings = drupalSettings && drupalSettings.b5subtheme && drupalSettings.b5subtheme.ga4;
  if (!settings || !settings.id) {
    return;
  }

  window.dataLayer = window.dataLayer || [];
  function gtag() { window.dataLayer.push(arguments); }
  window.gtag = window.gtag || gtag;

  // Consent Mode v2 - Set default consent state (all granted - no GDPR consent manager).
  gtag('consent', 'default', {
    'ad_storage': 'granted',
    'ad_user_data': 'granted',
    'ad_personalization': 'granted',
    'analytics_storage': 'granted',
    'functionality_storage': 'granted',
    'personalization_storage': 'granted',
    'security_storage': 'granted'
  });

  gtag('js', new Date());

  gtag('config', settings.id, {
    'send_page_view': true,
    'allow_google_signals': false,
    'transport_type': 'beacon',
    'content_group': settings.contentGroup || 'general',
    'page_type': settings.pageType || 'page'
  });
})(window.drupalSettings || {});
