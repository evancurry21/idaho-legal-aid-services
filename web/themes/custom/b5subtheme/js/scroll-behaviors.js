/**
 * @file
 * Scroll behaviors for sticky navbar, back-to-top button, and back button fallback.
 *
 * Uses Drupal.behaviors + once() for BigPipe/AJAX compatibility.
 *
 * CHANGELOG (v2.0 - Jitter Fix):
 * - Fixed header jitter on short pages by adding computed short-page guard
 * - Added hysteresis (shrink at 50px, expand at 20px) to prevent oscillation
 * - Added requestAnimationFrame batching for DOM writes
 * - Added reduced motion support for back-to-top smooth scroll
 *
 * Key fixes:
 * - measureHeaderDelta() uses cloned navbar (no scroll/layout impact)
 * - shouldEnableShrinking() uses stable "expanded-equivalent" maxScroll
 * - Guard formula: disable shrinking when maxScroll <= shrinkThreshold + headerDelta + 5px
 * - isShrunk syncs from DOM at start of each scroll handler to prevent desync
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Set --scrollbar-width CSS custom property on :root
   * Required for full-bleed elements using calc(100vw - var(--scrollbar-width))
   * This prevents horizontal overflow caused by 100vw including scrollbar width
   */
  function setScrollbarWidth() {
    var scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
    document.documentElement.style.setProperty('--scrollbar-width', scrollbarWidth + 'px');
  }

  // Set on load and resize (scrollbar may appear/disappear based on content height)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setScrollbarWidth);
  } else {
    setScrollbarWidth();
  }
  window.addEventListener('resize', setScrollbarWidth);

  /**
   * Scroll behavior for navbar shrinking and back-to-top button visibility
   *
   * Implements hysteresis and short-page detection to prevent jitter:
   * - Different thresholds for shrink (50px) vs expand (20px)
   * - Disabled on pages with insufficient scroll range
   * - Optional cooldown prevents rapid state changes during momentum scroll
   */
  Drupal.behaviors.scrollBehaviors = {
    attach: function (context, settings) {
      // Only attach scroll listener once per page (not per AJAX update)
      once('scroll-behaviors', 'body', context).forEach(function () {
        // Ensure scrollbar width is set (in case of AJAX content changes)
        setScrollbarWidth();

        var navbar = document.querySelector('.centered-logo-navbar');
        var backToTopBtn = document.querySelector('.back-to-top');

        // === Configuration ===
        // Hysteresis thresholds to prevent oscillation
        var shrinkThreshold = 50;   // Scroll down past this to shrink
        var expandThreshold = 20;   // Scroll up past this to expand (must be < shrinkThreshold)
        var safetyMargin = 5;       // Extra margin for computed guard

        // Back-to-top button threshold
        var backToTopThreshold = 100;

        // Optional cooldown to prevent rapid toggling (ms)
        // Set to 0 to disable if hysteresis alone is sufficient
        var cooldownDuration = 50;
        var lastToggleTime = 0;

        // State tracking (synced from DOM at start of each scroll handler)
        var isShrunk = false;
        var rafPending = false;

        // Measured header height delta (set on init)
        var headerHeightDelta = 0;

        if (!navbar && !backToTopBtn) {
          return;
        }

        /**
         * Measure header height delta using a cloned navbar.
         * Uses off-screen clone to avoid scroll/layout impact.
         * Called once on initialization and on resize (breakpoint changes).
         */
        function measureHeaderDelta() {
          if (!navbar) {
            return 0;
          }

          // Clone the navbar so we can measure without affecting layout/scroll
          var clone = navbar.cloneNode(true);

          // Keep it in the same parent so inherited styles still apply
          clone.style.position = 'absolute';
          clone.style.left = '-99999px';
          clone.style.top = '0';
          clone.style.visibility = 'hidden';
          clone.style.pointerEvents = 'none';
          clone.style.height = 'auto';

          // Match width so wrapping/line breaks match the real navbar
          var rect = navbar.getBoundingClientRect();
          clone.style.width = rect.width + 'px';

          navbar.parentNode.appendChild(clone);

          // Measure expanded height
          clone.classList.remove('navbar-shrink');
          var expandedHeight = clone.offsetHeight;

          // Measure shrunk height
          clone.classList.add('navbar-shrink');
          var shrunkHeight = clone.offsetHeight;

          // Clean up
          clone.parentNode.removeChild(clone);

          return Math.max(0, expandedHeight - shrunkHeight);
        }

        // Measure header delta on initialization
        headerHeightDelta = measureHeaderDelta();

        // Sync isShrunk from DOM
        isShrunk = navbar ? navbar.classList.contains('navbar-shrink') : false;

        /**
         * Calculate maximum scroll position
         * Returns the maximum scrollY value possible on the current page
         */
        function getMaxScroll() {
          var documentHeight = Math.max(
            document.body.scrollHeight,
            document.body.offsetHeight,
            document.documentElement.clientHeight,
            document.documentElement.scrollHeight,
            document.documentElement.offsetHeight
          );
          var viewportHeight = window.innerHeight;
          return Math.max(0, documentHeight - viewportHeight);
        }

        /**
         * Check if page has enough scroll range for shrinking behavior.
         * Uses stable "expanded-equivalent" maxScroll to prevent guard oscillation.
         * Formula: maxScrollExpanded > shrinkThreshold + headerDelta + safetyMargin
         */
        function shouldEnableShrinking() {
          var maxScrollNow = getMaxScroll();

          // Estimate what maxScroll would be if we were expanded.
          // If currently shrunk, the expanded page would be taller by headerHeightDelta.
          var maxScrollExpanded = maxScrollNow + (isShrunk ? headerHeightDelta : 0);

          var minRequired = shrinkThreshold + headerHeightDelta + safetyMargin;
          return maxScrollExpanded > minRequired;
        }

        /**
         * Handle scroll events with hysteresis and short-page protection
         */
        function handleScroll() {
          // Batch DOM reads/writes with requestAnimationFrame
          if (rafPending) {
            return;
          }
          rafPending = true;

          requestAnimationFrame(function () {
            rafPending = false;

            var scrolled = window.pageYOffset || document.documentElement.scrollTop;
            var now = Date.now();

            // Handle navbar shrinking with hysteresis
            if (navbar) {
              // SYNC: Always read current state from DOM to prevent desync
              // (other code, breakpoints, or admin toolbar could modify the class)
              isShrunk = navbar.classList.contains('navbar-shrink');

              // Check if page has enough scroll range (stable guard)
              var enableShrinking = shouldEnableShrinking();

              if (!enableShrinking) {
                // Short page: always keep expanded
                if (isShrunk) {
                  navbar.classList.remove('navbar-shrink');
                  isShrunk = false;
                }
              } else {
                // Normal page: apply hysteresis logic

                // Check cooldown before allowing state change (skip if cooldown is 0)
                var cooldownPassed = cooldownDuration === 0 || (now - lastToggleTime >= cooldownDuration);

                if (cooldownPassed) {
                  // Hysteresis: different thresholds for shrink vs expand
                  if (!isShrunk && scrolled > shrinkThreshold) {
                    // Transition to shrunk state
                    navbar.classList.add('navbar-shrink');
                    isShrunk = true;
                    lastToggleTime = now;
                  } else if (isShrunk && scrolled < expandThreshold) {
                    // Transition to expanded state
                    navbar.classList.remove('navbar-shrink');
                    isShrunk = false;
                    lastToggleTime = now;
                  }
                }
              }
            }

            // Handle back-to-top button visibility (no hysteresis needed)
            if (backToTopBtn) {
              if (scrolled > backToTopThreshold) {
                backToTopBtn.classList.add('show');
              } else {
                backToTopBtn.classList.remove('show');
              }
            }
          });
        }

        /**
         * Handle resize events - recalculate if shrinking should be enabled
         */
        var resizeTimeout;
        function handleResize() {
          clearTimeout(resizeTimeout);
          resizeTimeout = setTimeout(function () {
            // Recalculate scrollbar width
            setScrollbarWidth();

            // Re-measure header delta (may change at different breakpoints)
            headerHeightDelta = measureHeaderDelta();

            // Sync isShrunk from DOM
            isShrunk = navbar ? navbar.classList.contains('navbar-shrink') : false;

            // Re-evaluate shrink state after resize
            // If this is now a short page, force expanded
            if (navbar && !shouldEnableShrinking() && isShrunk) {
              navbar.classList.remove('navbar-shrink');
              isShrunk = false;
            }
          }, 100);
        }

        // Force expanded on short pages immediately after initialization
        if (navbar && !shouldEnableShrinking()) {
          navbar.classList.remove('navbar-shrink');
          isShrunk = false;
        }

        // Attach scroll listener with passive option for performance
        window.addEventListener('scroll', handleScroll, { passive: true });

        // Attach resize listener for short-page recalculation
        window.addEventListener('resize', handleResize, { passive: true });

        // Initial check on page load (handles scroll restoration, hash links, etc.)
        handleScroll();
      });
    }
  };

  /**
   * Back-to-top button click behavior
   */
  Drupal.behaviors.backToTop = {
    attach: function (context, settings) {
      once('back-to-top', '.back-to-top', context).forEach(function (backToTopBtn) {
        backToTopBtn.addEventListener('click', function (e) {
          e.preventDefault();

          // Check reduced motion preference
          var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

          window.scrollTo({
            top: 0,
            behavior: prefersReducedMotion ? 'auto' : 'smooth'
          });
        });
      });
    }
  };

  /**
   * Back button progressive enhancement.
   * Uses browser history only when referrer is same-origin and meaningful.
   * Falls back to href attribute for direct page access (bookmarks, Google, etc.).
   */
  Drupal.behaviors.backButtonFallback = {
    attach: function (context, settings) {
      once('back-button-fallback', '[data-back-fallback]', context).forEach(function (button) {
        button.addEventListener('click', function (e) {
          var referrer = document.referrer;
          var currentOrigin = window.location.origin;
          var currentUrl = window.location.href;

          // Only use history.back() if:
          // 1. There is a referrer
          // 2. Referrer is same-origin (not from external site)
          // 3. Referrer is not the current page (avoid loops)
          // 4. Browser has history to go back to
          if (
            referrer &&
            referrer.indexOf(currentOrigin) === 0 &&
            referrer !== currentUrl &&
            window.history.length > 1
          ) {
            e.preventDefault();
            window.history.back();
          }
          // Otherwise, let the default href navigation happen
        });
      });
    }
  };

})(Drupal, once);
