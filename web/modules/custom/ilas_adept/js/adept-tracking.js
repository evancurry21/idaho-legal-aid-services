/**
 * @file
 * ADEPT lesson tracking — GA4 events + localStorage progress + embed handling.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  var STORAGE_KEY = 'adept_progress_data';

  /**
   * Reads progress data from localStorage.
   *
   * @return {Object} Progress data with modules map.
   */
  function getProgressData() {
    try {
      var data = localStorage.getItem(STORAGE_KEY);
      return data ? JSON.parse(data) : { modules: {} };
    }
    catch (e) {
      return { modules: {} };
    }
  }

  /**
   * Saves progress data to localStorage.
   *
   * @param {Object} data - Progress data to save.
   */
  function saveProgressData(data) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
    }
    catch (e) {
      // localStorage unavailable or full — fail silently.
    }
  }

  /**
   * Checks if a lesson has been completed.
   *
   * @param {number} moduleNum - Module number.
   * @param {number} lessonNum - Lesson number.
   * @return {boolean} Whether the lesson is completed.
   */
  function isLessonCompleted(moduleNum, lessonNum) {
    var data = getProgressData();
    var mod = data.modules[String(moduleNum)];
    return mod && mod.completed && mod.completed.indexOf(lessonNum) !== -1;
  }

  /**
   * Marks a lesson as completed in localStorage.
   *
   * @param {number} moduleNum - Module number.
   * @param {number} lessonNum - Lesson number.
   */
  function markLessonCompleted(moduleNum, lessonNum) {
    var data = getProgressData();
    var modKey = String(moduleNum);
    if (!data.modules[modKey]) {
      data.modules[modKey] = { completed: [], lastUpdated: '' };
    }
    if (data.modules[modKey].completed.indexOf(lessonNum) === -1) {
      data.modules[modKey].completed.push(lessonNum);
    }
    data.modules[modKey].lastUpdated = new Date().toISOString();
    saveProgressData(data);
  }

  /**
   * Sends a GA4 event via gtag or dataLayer.
   *
   * @param {string} eventName - GA4 event name.
   * @param {Object} params - Event parameters.
   */
  function trackEvent(eventName, params) {
    if (typeof gtag === 'function') {
      gtag('event', eventName, params);
    }
    else if (window.dataLayer && Array.isArray(window.dataLayer)) {
      window.dataLayer.push(Object.assign({ event: eventName }, params));
    }
    // Fails silently if neither gtag nor dataLayer exists.
  }

  /**
   * Behavior for individual ADEPT lesson pages.
   *
   * Reads data attributes from the article element, fires GA4 events,
   * handles iframe injection for embed mode, and manages completion state.
   */
  Drupal.behaviors.adeptLessonTracking = {
    attach: function (context) {
      var articles = once('adept-lesson', '[data-adept-module]', context);
      if (!articles.length) {
        return;
      }

      var article = articles[0];
      var moduleNum = parseInt(article.getAttribute('data-adept-module'), 10);
      var lessonNum = parseInt(article.getAttribute('data-adept-lesson'), 10);
      var mode = article.getAttribute('data-adept-mode');
      var title = article.getAttribute('data-adept-title');

      var eventParams = {
        adept_module: moduleNum,
        adept_lesson: lessonNum,
        adept_lesson_title: title,
        adept_mode: mode
      };

      // Fire lesson view event.
      trackEvent('adept_lesson_view', eventParams);

      var completeBtn = article.querySelector('.adept-mark-complete');
      var statusBadge = article.querySelector('.adept-completion-status');

      // Reflect prior completion state.
      if (isLessonCompleted(moduleNum, lessonNum)) {
        if (completeBtn) {
          completeBtn.disabled = true;
          completeBtn.textContent = Drupal.t('Completed');
          completeBtn.classList.add('btn-success');
          completeBtn.classList.remove('btn-outline-primary');
        }
        if (statusBadge) {
          statusBadge.classList.remove('d-none');
        }
      }

      // Start lesson button.
      var startBtn = article.querySelector('.adept-start-lesson');
      var embedContainer = article.querySelector('.adept-embed-container');

      if (startBtn) {
        startBtn.addEventListener('click', function (e) {
          trackEvent('adept_lesson_start', eventParams);

          if (mode === 'link') {
            // Link mode — open in new tab. The <a> tag handles navigation.
            return;
          }

          // Embed / self_host modes — inject iframe.
          e.preventDefault();
          if (embedContainer && !embedContainer.querySelector('iframe')) {
            var iframeSrc = startBtn.getAttribute('data-adept-url');
            if (iframeSrc) {
              var iframe = document.createElement('iframe');
              iframe.src = iframeSrc;
              iframe.setAttribute('title', Drupal.t('ADEPT Lesson: @title', { '@title': title }));
              iframe.setAttribute('allowfullscreen', '');
              iframe.setAttribute('loading', 'lazy');
              iframe.classList.add('adept-iframe');

              // Sandbox for S3-hosted content (Captivate needs scripts + same-origin + forms).
              if (mode === 'embed') {
                iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups');
              }

              embedContainer.appendChild(iframe);
              embedContainer.classList.remove('d-none');
              startBtn.classList.add('d-none');

              // Scroll to embed with reduced-motion awareness.
              var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
              embedContainer.scrollIntoView({
                behavior: prefersReducedMotion ? 'auto' : 'smooth',
                block: 'start'
              });
            }
          }

          // Show mark complete button.
          if (completeBtn) {
            completeBtn.classList.remove('d-none');
          }
        });
      }

      // Mark complete button.
      if (completeBtn && !isLessonCompleted(moduleNum, lessonNum)) {
        completeBtn.addEventListener('click', function () {
          markLessonCompleted(moduleNum, lessonNum);
          trackEvent('adept_lesson_complete', eventParams);

          completeBtn.disabled = true;
          completeBtn.textContent = Drupal.t('Completed');
          completeBtn.classList.add('btn-success');
          completeBtn.classList.remove('btn-outline-primary');

          if (statusBadge) {
            statusBadge.classList.remove('d-none');
          }
        });
      }

      // Download link tracking.
      var downloadLinks = article.querySelectorAll('.adept-download-link a');
      downloadLinks.forEach(function (link) {
        link.addEventListener('click', function () {
          trackEvent('adept_download_click', {
            adept_module: moduleNum,
            adept_lesson: lessonNum,
            adept_lesson_title: title,
            adept_download_url: link.href
          });
        });
      });
    }
  };

  /**
   * Behavior for ADEPT module landing pages (Views).
   *
   * Updates the progress bar and marks completed lesson cards
   * based on localStorage data.
   */
  Drupal.behaviors.adeptModuleProgress = {
    attach: function (context) {
      var views = once('adept-progress', '.view-adept-lessons', context);
      if (!views.length) {
        return;
      }

      var view = views[0];

      // Determine module number from URL path.
      var pathMatch = window.location.pathname.match(/module-(\d+)/);
      if (!pathMatch) {
        return;
      }

      var moduleNum = parseInt(pathMatch[1], 10);

      trackEvent('adept_module_view', {
        adept_module: moduleNum
      });

      var data = getProgressData();
      var mod = data.modules[String(moduleNum)];
      var completedLessons = (mod && mod.completed) ? mod.completed : [];

      // Update progress bar.
      var progressBar = view.querySelector('.adept-progress-bar');
      if (progressBar) {
        var totalLessons = parseInt(progressBar.getAttribute('data-total-lessons') || '11', 10);
        var completedCount = completedLessons.length;
        var percentage = totalLessons > 0 ? Math.round((completedCount / totalLessons) * 100) : 0;

        var fill = progressBar.querySelector('.adept-progress-fill');
        var label = view.querySelector('.adept-progress-label');

        if (fill) {
          fill.style.width = percentage + '%';
          fill.setAttribute('aria-valuenow', percentage);
        }
        if (label) {
          label.textContent = completedCount + ' of ' + totalLessons + ' lessons completed (' + percentage + '%)';
        }
      }

      // Mark completed lesson cards.
      var lessonCards = view.querySelectorAll('.views-row');
      lessonCards.forEach(function (card) {
        var lessonLink = card.querySelector('a[href*="lesson-"]');
        if (lessonLink) {
          var lessonMatch = lessonLink.getAttribute('href').match(/lesson-(\d+)/);
          if (lessonMatch) {
            var lessonNum = parseInt(lessonMatch[1], 10);
            if (completedLessons.indexOf(lessonNum) !== -1) {
              card.classList.add('is-completed');
            }
          }
        }
      });
    }
  };

})(Drupal, drupalSettings, once);
