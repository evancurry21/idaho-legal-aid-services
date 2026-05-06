/**
 * @file
 * Custom scripts for b5subtheme.
 *
 * Provides:
 * - Help overlay with focus trap and accessibility (ARIA dialog pattern)
 * - Utility bar hover effects
 * - Button text color fixes
 * - Impact card flip functionality (desktop only)
 *
 * All behaviors use Drupal.behaviors + once() for BigPipe/AJAX compatibility.
 */

(function (Drupal, once) {
  'use strict';

  // ==========================================
  // HELP OVERLAY BEHAVIOR
  // Implements ARIA dialog pattern with focus trap
  // ==========================================

  Drupal.behaviors.helpOverlay = {
    attach: function (context, settings) {
      once('help-overlay', '#helpToggle', context).forEach(function (helpToggle) {
        var helpOverlay = document.getElementById('helpOverlay');
        var helpBackdrop = document.getElementById('helpOverlayBackdrop');
        var helpPanel = document.getElementById('helpPanel');

        if (!helpOverlay || !helpBackdrop) {
          return;
        }

        // Get background content elements for inert handling
        var mainContent = document.querySelector('main.main-content');
        var header = document.querySelector('.site-header');
        var footer = document.querySelector('footer');

        // Check if browser supports inert attribute
        var supportsInert = 'inert' in HTMLElement.prototype;

        // Store the element that triggered the overlay
        var triggerElement = null;

        /**
         * Get all focusable elements within the help panel
         */
        function getFocusableElements() {
          if (!helpPanel) {
            return [];
          }
          return Array.prototype.slice.call(
            helpPanel.querySelectorAll(
              'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])'
            )
          );
        }

        /**
         * Set background content as inert (prevents focus and AT interaction)
         */
        function setBackgroundInert(inert) {
          var elements = [mainContent, header, footer];
          elements.forEach(function (el) {
            if (!el) {
              return;
            }
            if (inert) {
              if (supportsInert) {
                el.setAttribute('inert', '');
              } else {
                // Fallback for browsers without inert support
                el.setAttribute('aria-hidden', 'true');
              }
            } else {
              if (supportsInert) {
                el.removeAttribute('inert');
              } else {
                el.removeAttribute('aria-hidden');
              }
            }
          });
        }

        /**
         * Open the help overlay
         */
        function openHelpOverlay() {
          triggerElement = document.activeElement;

          // Add class - CSS handles all visual state
          helpOverlay.classList.add('show-overlay');

          // Update ARIA attributes
          helpOverlay.setAttribute('aria-hidden', 'false');
          helpToggle.setAttribute('aria-expanded', 'true');

          // Prevent body scrolling
          document.body.style.overflow = 'hidden';
          document.body.classList.add('help-overlay-open');

          // Make background content inert
          setBackgroundInert(true);

          // Focus first focusable element in panel
          var focusableElements = getFocusableElements();
          if (focusableElements.length > 0) {
            setTimeout(function () {
              focusableElements[0].focus();
            }, 100);
          }
        }

        /**
         * Close the help overlay
         */
        function closeHelpOverlay() {
          // Remove class - CSS handles all visual state
          helpOverlay.classList.remove('show-overlay');

          // Update ARIA attributes
          helpOverlay.setAttribute('aria-hidden', 'true');
          helpToggle.setAttribute('aria-expanded', 'false');

          // Restore body scrolling
          document.body.style.overflow = '';
          document.body.classList.remove('help-overlay-open');

          // Remove inert from background content
          setBackgroundInert(false);

          // Return focus to trigger element
          if (triggerElement && typeof triggerElement.focus === 'function') {
            triggerElement.focus();
          }
        }

        /**
         * Focus trap handler - keeps focus within overlay when open
         */
        function handleFocusTrap(e) {
          if (!helpOverlay.classList.contains('show-overlay')) {
            return;
          }

          var focusableElements = getFocusableElements();
          if (focusableElements.length === 0) {
            return;
          }

          var firstFocusable = focusableElements[0];
          var lastFocusable = focusableElements[focusableElements.length - 1];

          // Tab key handling
          if (e.key === 'Tab') {
            if (e.shiftKey) {
              // Shift + Tab: if on first element, go to last
              if (document.activeElement === firstFocusable) {
                e.preventDefault();
                lastFocusable.focus();
              }
            } else {
              // Tab: if on last element, go to first
              if (document.activeElement === lastFocusable) {
                e.preventDefault();
                firstFocusable.focus();
              }
            }
          }
        }

        /**
         * Handle Escape key to close overlay
         */
        function handleEscapeKey(e) {
          if (e.key === 'Escape' && helpOverlay.classList.contains('show-overlay')) {
            e.preventDefault();
            closeHelpOverlay();
          }
        }

        // Toggle button click
        helpToggle.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();

          if (helpOverlay.classList.contains('show-overlay')) {
            closeHelpOverlay();
          } else {
            openHelpOverlay();
          }
        });

        // Backdrop click to close
        helpBackdrop.addEventListener('click', function () {
          closeHelpOverlay();
        });

        // Keyboard handlers (attached to document for global coverage)
        document.addEventListener('keydown', handleEscapeKey);
        document.addEventListener('keydown', handleFocusTrap);

        // Initialize ARIA state
        helpOverlay.setAttribute('aria-hidden', 'true');
        helpToggle.setAttribute('aria-expanded', 'false');
      });
    }
  };

  // ==========================================
  // UTILITY BAR HOVER BEHAVIOR
  // Adds state classes for CSS-based hover/focus styling
  // ==========================================

  Drupal.behaviors.utilityBarHover = {
    attach: function (context, settings) {
      var selector = '.utility-bar .utility-link';

      once('utility-bar-hover', selector, context).forEach(function (item) {
        // Mouse enter - add hover class
        item.addEventListener('mouseenter', function () {
          this.classList.add('is-hovered');
        });

        // Mouse leave - remove hover class
        item.addEventListener('mouseleave', function () {
          this.classList.remove('is-hovered');
        });

        // Focus - add focus class
        item.addEventListener('focus', function () {
          this.classList.add('is-focused');
        });

        // Blur - remove focus class
        item.addEventListener('blur', function () {
          this.classList.remove('is-focused');
        });
      });
    }
  };

  // ==========================================
  // BUTTON TEXT COLOR BEHAVIOR
  // Adds state classes for button hover styling
  // ==========================================

  Drupal.behaviors.buttonTextColor = {
    attach: function (context, settings) {
      once('button-text-color', '.btn-primary, .btn-success', context).forEach(function (button) {
        // Mouse enter - add hover class
        button.addEventListener('mouseenter', function () {
          this.classList.add('is-hovered');
        });

        // Mouse leave - remove hover class
        button.addEventListener('mouseleave', function () {
          this.classList.remove('is-hovered');
        });
      });
    }
  };

  // ==========================================
  // IMPACT CARDS FLIP BEHAVIOR
  // Desktop-only card flip with accessibility support
  // ==========================================

  Drupal.behaviors.impactCards = {
    attach: function (context, settings) {

      /**
       * Check if device is mobile/tablet
       */
      function isMobileDevice() {
        return window.innerWidth < 768;
      }

      /**
       * Check if a card has back content
       */
      function hasBackContent(card) {
        var backDetail = card.querySelector('.impact-card__back-detail');
        if (!backDetail) {
          return false;
        }
        var content = backDetail.textContent || backDetail.innerText || '';
        return content.trim().length > 0;
      }

      /**
       * Flip a card to the specified state. The trigger button (front face)
       * owns aria-expanded; the back face toggles aria-hidden + inert. The
       * card root itself is non-interactive — it carries no role/tabindex.
       */
      function flipCard(card, shouldFlip) {
        var front   = card.querySelector('.card-front');
        var back    = card.querySelector('.card-back');
        var trigger = card.querySelector('.impact-card__trigger');

        if (shouldFlip) {
          card.classList.add('is-flipped');
          if (trigger) trigger.setAttribute('aria-expanded', 'true');
          if (front) front.setAttribute('inert', '');
          if (back) {
            back.removeAttribute('inert');
            back.setAttribute('aria-hidden', 'false');
          }

          // Focus the close button after the flip animation completes.
          setTimeout(function () {
            var closeButton = card.querySelector('.impact-card__back-close');
            if (closeButton) closeButton.focus();
          }, 250);
        } else {
          card.classList.remove('is-flipped');
          if (trigger) trigger.setAttribute('aria-expanded', 'false');
          if (back) {
            back.setAttribute('inert', '');
            back.setAttribute('aria-hidden', 'true');
          }
          if (front) front.removeAttribute('inert');

          // Return focus to the trigger (NOT the card root, which is non-interactive).
          setTimeout(function () {
            if (trigger) trigger.focus();
          }, 100);
        }
      }

      /**
       * Click handler bound to the trigger button. Native <button> already
       * handles Enter/Space activation, so no keydown listener is needed
       * (Escape is handled by the global document-level listener below).
       */
      function handleTriggerClick(event) {
        var card = event.currentTarget.closest('.impact-card');
        if (!card) return;
        if (isMobileDevice()) return; // mobile: button click is a no-op
        event.preventDefault();
        flipCard(card, !card.classList.contains('is-flipped'));
      }

      /**
       * Close all flipped cards
       */
      function closeAllFlippedCards() {
        var flippedCards = document.querySelectorAll('.impact-card.is-flipped');
        flippedCards.forEach(function (card) {
          flipCard(card, false);
        });
      }

      // Initialize cards
      once('impact-cards', '.impact-card', context).forEach(function (card) {
        // Skip cards without back content
        if (!hasBackContent(card)) {
          return;
        }

        var trigger  = card.querySelector('.impact-card__trigger');
        var closeBtn = card.querySelector('.impact-card__back-close');

        // On mobile, mark the card and skip flip wiring entirely.
        if (isMobileDevice()) {
          card.classList.add('mobile-card');
          return;
        }

        // Desktop: bind the trigger button. NO role/tabindex/aria-expanded on the card root.
        if (trigger) {
          trigger.addEventListener('click', handleTriggerClick);
        }

        if (closeBtn) {
          closeBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            flipCard(card, false);
          });
        }
      });

      // Global handlers (only attach once per page)
      once('impact-cards-global', 'body', context).forEach(function () {
        // Close cards when clicking outside
        document.addEventListener('click', function (event) {
          var clickedCard = event.target.closest('.impact-card');
          if (!clickedCard && !isMobileDevice()) {
            closeAllFlippedCards();
          }
        });

        // Close on Escape (global)
        document.addEventListener('keydown', function (event) {
          if (event.key === 'Escape' && !isMobileDevice()) {
            closeAllFlippedCards();
          }
        });

        // Handle resize
        var resizeTimeout;
        window.addEventListener('resize', function () {
          clearTimeout(resizeTimeout);
          resizeTimeout = setTimeout(function () {
            closeAllFlippedCards();
          }, 250);
        });
      });
    }
  };

})(Drupal, once);
