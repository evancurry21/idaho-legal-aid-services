/**
 * @file
 * Lazy loading implementation for images and dynamic content.
 */

(function (Drupal) {
  'use strict';

  /**
   * Initialize lazy loading for images and content.
   */
  Drupal.behaviors.ilasLazyLoading = {
    attach: function (context, settings) {
      // Check if browser supports Intersection Observer
      if (!('IntersectionObserver' in window)) {
        // Fallback: Load all images immediately
        this.loadAllImages(context);
        return;
      }

      // Configure Intersection Observer
      const imageObserverOptions = {
        root: null,
        rootMargin: '50px', // Start loading 50px before entering viewport
        threshold: 0.01
      };

      // Create observer for images
      const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            this.loadImage(entry.target);
            observer.unobserve(entry.target);
          }
        });
      }, imageObserverOptions);

      // Find all images with data-src attribute
      const lazyImages = context.querySelectorAll('img[data-src]:not(.lazy-loaded)');
      lazyImages.forEach(img => {
        imageObserver.observe(img);
      });

      // Configure observer for dynamic content (hotspots, etc.)
      const contentObserverOptions = {
        root: null,
        rootMargin: '100px', // Load content 100px before viewport
        threshold: 0
      };

      // Create observer for dynamic content
      const contentObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            this.loadDynamicContent(entry.target);
            observer.unobserve(entry.target);
          }
        });
      }, contentObserverOptions);

      // Find all lazy-load content containers
      const lazyContent = context.querySelectorAll('[data-lazy-load]:not(.lazy-loaded)');
      lazyContent.forEach(element => {
        contentObserver.observe(element);
      });
    },

    /**
     * Load an image from data-src.
     */
    loadImage: function(img) {
      const src = img.getAttribute('data-src');
      const srcset = img.getAttribute('data-srcset');
      
      if (src) {
        // Create a new image to preload
        const tempImg = new Image();
        
        tempImg.onload = function() {
          // Apply fade-in effect
          img.style.opacity = '0';
          img.src = src;
          
          if (srcset) {
            img.srcset = srcset;
          }
          
          img.classList.add('lazy-loaded');
          
          // Fade in the image
          requestAnimationFrame(() => {
            img.style.transition = 'opacity 0.3s ease-in-out';
            img.style.opacity = '1';
          });
          
          // Remove data attributes
          img.removeAttribute('data-src');
          img.removeAttribute('data-srcset');
        };
        
        tempImg.onerror = function() {
          // Handle error - maybe load a placeholder
          img.classList.add('lazy-error');
        };
        
        tempImg.src = src;
      }
    },

    /**
     * Load dynamic content (like hotspots).
     */
    loadDynamicContent: function(element) {
      const contentType = element.getAttribute('data-lazy-load');
      
      switch (contentType) {
        case 'hotspot':
          this.initializeHotspot(element);
          break;
        
        case 'iframe':
          this.loadIframe(element);
          break;
        
        case 'video':
          this.loadVideo(element);
          break;
        
        default:
          // Generic content loading
          if (element.hasAttribute('data-lazy-src')) {
            this.loadAjaxContent(element);
          }
      }
      
      element.classList.add('lazy-loaded');
    },

    /**
     * Initialize hotspot functionality.
     */
    initializeHotspot: function(element) {
      // Trigger the hotspot initialization
      if (typeof Drupal.behaviors.ilasHotspot !== 'undefined') {
        Drupal.behaviors.ilasHotspot.attach(element);
      }
    },

    /**
     * Load iframe content.
     */
    loadIframe: function(element) {
      const iframe = element.querySelector('iframe[data-src]');
      if (iframe) {
        iframe.src = iframe.getAttribute('data-src');
        iframe.removeAttribute('data-src');
      }
    },

    /**
     * Load video content.
     */
    loadVideo: function(element) {
      const video = element.querySelector('video[data-src]');
      if (video) {
        video.src = video.getAttribute('data-src');
        video.removeAttribute('data-src');
        video.load();
      }
    },

    /**
     * Load content via AJAX.
     */
    loadAjaxContent: function(element) {
      const url = element.getAttribute('data-lazy-src');
      if (!url) return;

      fetch(url)
        .then(response => response.text())
        .then(html => {
          // Parse response as DOM to safely insert HTML and prevent XSS
          const doc = new DOMParser().parseFromString(html, 'text/html');
          element.innerHTML = '';
          while (doc.body.firstChild) {
            element.appendChild(doc.body.firstChild);
          }
          // Re-attach Drupal behaviors to new content
          Drupal.attachBehaviors(element);
        })
        .catch(error => {
          element.classList.add('lazy-error');
        });
    },

    /**
     * Fallback for browsers without Intersection Observer.
     */
    loadAllImages: function(context) {
      const lazyImages = context.querySelectorAll('img[data-src]');
      lazyImages.forEach(img => {
        if (img.getAttribute('data-src')) {
          img.src = img.getAttribute('data-src');
          
          if (img.getAttribute('data-srcset')) {
            img.srcset = img.getAttribute('data-srcset');
          }
          
          img.removeAttribute('data-src');
          img.removeAttribute('data-srcset');
          img.classList.add('lazy-loaded');
        }
      });
    }
  };

})(Drupal);