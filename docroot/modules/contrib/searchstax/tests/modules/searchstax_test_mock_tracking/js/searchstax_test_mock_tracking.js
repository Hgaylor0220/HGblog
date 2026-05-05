/**
 * @file
 * Provides a mock implementation of SearchStax tracking functionality.
 */

(function ($) {
  const $list = $('<ul id="searchstax-test-mock-tracking"></ul>');
  $('h1').after($list);

  function mockPush(event) {
    const item = $('<li></li>').appendTo($list);
    item.get(0).textContent = JSON.stringify(event);
  }

  if (
    typeof window._msq !== 'undefined' &&
    typeof window._msq.reverse === 'function' &&
    window._msq.length > 0
  ) {
    $.each(window._msq, function (i, val) {
      mockPush(val);
    });
  }
  window._msq = $.extend([], { push: mockPush });

  // Optionally prevent tracking by implementing the respective event handlers.
  $(document).on(
    'searchstax:trackSearchResults searchstax:trackClick',
    function (event) {
      if (
        drupalSettings &&
        drupalSettings.searchstax_test_mock_tracking &&
        drupalSettings.searchstax_test_mock_tracking.reject &&
        drupalSettings.searchstax_test_mock_tracking.reject.includes(
          event.type.substring(11),
        )
      ) {
        event.preventDefault();
      }
    },
  );

  // Replace the click handler for all search result links with one that returns
  // false so that no new page will be loaded and we have an easier time
  // checking whether the click was tracked.
  Drupal.behaviors.searchstax_test_mock_tracking = {
    attach() {
      $('.views-element-container a')
        .filter(function () {
          return !!$(this).data('searchstax-search');
        })
        .off('click')
        .on('click', function (...args) {
          Drupal.searchstax.trackClick.apply(this, args);
          return false;
        });
    },
  };
})(jQuery);
