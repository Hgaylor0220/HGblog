/**
 * @file
 * Implements SearchStax tracking functionality.
 */

/* eslint-disable func-names */

// Drupal-specific tracking code.
(function ($) {
  Drupal.searchstax = Drupal.searchstax || {};

  /**
   * Whether we are allowed to track the user.
   *
   * Will be updated according to user interactions if the EU Cookie Compliance
   * module is installed.
   */
  Drupal.searchstax.trackingAllowed = true;

  /**
   * Loads the SearchStax tracking script, if not already done.
   */
  Drupal.searchstax.loadExternalTrackingScript = function () {
    // Initialize the _msq object right away with a placeholder to not run into
    // a race condition when tracking events.
    window._msq = window._msq || [];

    // Load the external tracking script.
    const ms = document.createElement('script');
    ms.type = 'text/javascript';
    const jsVersion =
      typeof drupalSettings !== 'undefined' &&
      drupalSettings.searchstax &&
      drupalSettings.searchstax.js_version;
    switch (jsVersion) {
      case '2':
        ms.src = 'https://static.searchstax.com/js/ms2.js';
        break;

      case '3':
      default:
        ms.src =
          'https://static.searchstax.com/studio-js/v3/js/studio-analytics.js';
        window.analyticsBaseUrl =
          (typeof drupalSettings !== 'undefined' &&
            drupalSettings.searchstax &&
            drupalSettings.searchstax.analytics_url) ||
          'https://app.searchstax.com';
        break;
    }
    const s = document.getElementsByTagName('script')[0];
    s.parentNode.insertBefore(ms, s);

    // Prevent this code from being re-executed.
    Drupal.searchstax.loadExternalTrackingScript = function () {};
  };

  /**
   * Click handler for search result links.
   */
  Drupal.searchstax.trackSearchResults = function () {
    if (
      !Drupal.searchstax.trackingAllowed ||
      !drupalSettings ||
      !drupalSettings.searchstax ||
      !drupalSettings.searchstax.searches
    ) {
      return;
    }

    const baseData = drupalSettings.searchstax.tracking_base_data;
    if (!baseData.session) {
      // This is an anonymous user, without fixed session. Generate a new random
      // session key for just this page.
      // See https://attacomsian.com/blog/javascript-generate-random-string.

      // Declare all characters.
      const chars =
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

      // Pick characters randomly.
      baseData.session = '';
      for (let i = 0; i < 32; i++) {
        baseData.session += chars.charAt(
          Math.floor(Math.random() * chars.length),
        );
      }
    }

    // Allow other modules or custom code to prevent tracking or modify tracking
    // data.
    const event = $.Event('searchstax:trackSearchResults', {
      searches: $.extend({}, drupalSettings.searchstax.searches),
      base_data: $.extend({}, baseData),
    });
    $(document).trigger(event);
    if (event.isDefaultPrevented() || !Object.keys(event.searches).length) {
      return;
    }

    Drupal.searchstax.loadExternalTrackingScript();

    $.each(event.searches, function () {
      // Don't track the same result set twice.
      if (this.tracked) {
        return;
      }
      this.tracked = true;
      const track = $.extend({}, event.base_data, this);
      window._msq.push(['track', track]);
    });
  };

  /**
   * Click handler for search result links.
   */
  Drupal.searchstax.trackClick = function () {
    if (!Drupal.searchstax.trackingAllowed) {
      return;
    }
    const linkElement = this;
    const $link = $(this);
    const searchId = $link.data('searchstax-search');
    const { searches } = drupalSettings.searchstax;
    if (!searches[searchId] || !searches[searchId].impressions) {
      return;
    }

    // Allow other modules or custom code to prevent tracking or modify tracking
    // data.
    const event = $.Event('searchstax:trackClick');
    event.searchId = searchId;
    event.search = searches[searchId];
    event.position = $link.data('searchstax-position');
    $(document).trigger(event);
    if (event.isDefaultPrevented()) {
      return;
    }

    Drupal.searchstax.loadExternalTrackingScript();

    $.each(searches[searchId].impressions, function () {
      if (this.position !== event.position) {
        return;
      }

      event.base_data = drupalSettings.searchstax.tracking_base_data;
      const track = $.extend({}, event.base_data, this, {
        query: searches[searchId].query,
        language: searches[searchId].language,
        pageNo: searches[searchId].pageNo,
        shownHits: searches[searchId].shownHits,
        totalHits: searches[searchId].totalHits,
        pageUrl: linkElement.href,
      });
      if (searches[searchId].model) {
        track.model = searches[searchId].model;
      }
      window._msq.push(['trackClick', track]);
      return false;
    });
  };

  /**
   * Enables tracking for clicks on all search results links.
   */
  Drupal.behaviors.searchstax = {
    attach(context, settings) {
      if (!settings || !settings.searchstax || !settings.searchstax.searches) {
        return;
      }

      if (
        settings.searchstax.eu_cookie_compliance &&
        settings.searchstax.eu_cookie_compliance.enabled &&
        Drupal.eu_cookie_compliance
      ) {
        const updateCookieStatus = function (event) {
          const cookieMethod =
            settings.eu_cookie_compliance &&
            settings.eu_cookie_compliance.method;
          if (event.currentStatus === null) {
            // User has not made a choice yet so it depends on the default value
            // (i.e., if the mode is "opt-in" or "opt-out").
            Drupal.searchstax.trackingAllowed =
              !cookieMethod || ['opt_out', 'default'].includes(cookieMethod);
          } else if (event.currentStatus === '0') {
            Drupal.searchstax.trackingAllowed = false;
          } else if (cookieMethod === 'categories') {
            // Mode is "Opt-in with categories": We need to figure out which
            // category we belong to and then whether the user has accepted that
            // category.
            const ourCategory =
              settings.searchstax.eu_cookie_compliance.category;
            Drupal.searchstax.trackingAllowed =
              !ourCategory ||
              typeof event.currentCategories === 'undefined' ||
              event.currentCategories.includes(ourCategory);
          } else {
            Drupal.searchstax.trackingAllowed = true;
          }
          if (Drupal.searchstax.trackingAllowed) {
            // This might have been called already, but we are fortunately
            // careful to not track anything twice so just calling it again
            // should be fine.
            Drupal.searchstax.trackSearchResults();
          }
        };
        updateCookieStatus({
          currentStatus: Drupal.eu_cookie_compliance.getCurrentStatus(),
          currentCategories:
            Drupal.eu_cookie_compliance.getAcceptedCategories(),
        });
        Drupal.eu_cookie_compliance('postStatusSave', updateCookieStatus);
        Drupal.eu_cookie_compliance('postPreferencesSave', updateCookieStatus);
      } else {
        Drupal.searchstax.trackSearchResults();
      }

      if (!settings.searchstax.results_urls) {
        return;
      }

      const resultUrls = drupalSettings.searchstax.results_urls;
      let $searchResults = $('[data-searchstax-results]', context);
      // For AJAX responses, "context" itself might be a search results listing.
      const $context = $(context);
      if ($context.data('searchstax-results')) {
        $searchResults = $searchResults.add($context);
      }
      $searchResults.each(function () {
        const $resultsArea = $(this);
        const searchId = $resultsArea.data('searchstax-results');
        if (!resultUrls[searchId]) {
          return;
        }
        $.each(resultUrls[searchId], function () {
          $resultsArea
            .find(`a[href="${this.url}"]`)
            .data('searchstax-position', this.position)
            .data('searchstax-search', searchId)
            .click(Drupal.searchstax.trackClick);
        });
      });
    },
  };
})(jQuery);
