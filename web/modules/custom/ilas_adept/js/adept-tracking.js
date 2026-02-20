/**
 * @file
 * ADEPT lesson tracking — GA4 events + localStorage progress + embed handling.
 *
 * localStorage keys: adept:module:{id}:lesson:{id}
 *   → {status: 'not_started'|'in_progress'|'completed', startedAt, completedAt}
 *
 * GA4 events (DL-1 locked params):
 *   adept_module_view   — module_id, total_lessons
 *   adept_lesson_view   — module_id, lesson_id, lesson_title, mode
 *   adept_lesson_start  — module_id, lesson_id, mode, destination
 *   adept_lesson_complete — module_id, lesson_id, time_to_complete_sec, mode
 *   adept_download_click — module_id, lesson_id, file_label, file_url
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  // --------------- localStorage helpers ---------------

  /**
   * Builds the per-lesson localStorage key.
   */
  function storageKey(moduleId, lessonId) {
    return 'adept:module:' + moduleId + ':lesson:' + lessonId;
  }

  /**
   * Reads a lesson record from localStorage.
   *
   * @return {Object} {status, startedAt, completedAt}
   */
  function getLessonRecord(moduleId, lessonId) {
    try {
      var raw = localStorage.getItem(storageKey(moduleId, lessonId));
      return raw ? JSON.parse(raw) : { status: 'not_started', startedAt: null, completedAt: null };
    }
    catch (e) {
      return { status: 'not_started', startedAt: null, completedAt: null };
    }
  }

  /**
   * Writes a lesson record to localStorage.
   */
  function setLessonRecord(moduleId, lessonId, record) {
    try {
      localStorage.setItem(storageKey(moduleId, lessonId), JSON.stringify(record));
    }
    catch (e) {
      // localStorage unavailable or full — fail silently.
    }
  }

  /**
   * One-time migration: reads old adept_progress_data, converts to per-lesson
   * keys, then deletes the old key.
   */
  function migrateOldStorage() {
    try {
      var raw = localStorage.getItem('adept_progress_data');
      if (!raw) {
        return;
      }
      var data = JSON.parse(raw);
      if (!data || !data.modules) {
        localStorage.removeItem('adept_progress_data');
        return;
      }
      var modules = data.modules;
      for (var modKey in modules) {
        if (!modules.hasOwnProperty(modKey)) {
          continue;
        }
        var mod = modules[modKey];
        var completed = (mod && mod.completed) ? mod.completed : [];
        for (var i = 0; i < completed.length; i++) {
          var lessonId = completed[i];
          var existing = getLessonRecord(modKey, lessonId);
          // Don't overwrite if already migrated.
          if (existing.status === 'not_started') {
            setLessonRecord(modKey, lessonId, {
              status: 'completed',
              startedAt: mod.lastUpdated || null,
              completedAt: mod.lastUpdated || null
            });
          }
        }
      }
      localStorage.removeItem('adept_progress_data');
    }
    catch (e) {
      // Migration failed — old data stays, no harm.
    }
  }

  // --------------- GA4 helpers ---------------

  /**
   * Strips query string and hash from a URL for analytics safety.
   *
   * @param {string} url - The URL to sanitize.
   * @return {string} URL with origin + pathname only.
   */
  function sanitizeUrlForAnalytics(url) {
    if (!url) {
      return '';
    }
    try {
      var parsed = new URL(url, window.location.origin);
      return parsed.origin + parsed.pathname;
    }
    catch (e) {
      return url;
    }
  }

  /**
   * Sends a GA4 event via gtag. No-op if gtag is absent.
   * DL-2: gtag-only dispatch, no dataLayer fallback.
   *
   * @param {string} eventName - GA4 event name.
   * @param {Object} params - Event parameters.
   * @param {Function} [callback] - Optional callback for outbound link safety.
   */
  function sendEvent(eventName, params, callback) {
    if (typeof window.gtag === 'function') {
      if (callback) {
        var called = false;
        var safe = function () { if (!called) { called = true; callback(); } };
        params.event_callback = safe;
        window.gtag('event', eventName, params);
        setTimeout(safe, 1000);
      } else {
        window.gtag('event', eventName, params);
      }
    } else if (callback) {
      callback();
    }
  }

  // --------------- Run migration on first load ---------------
  migrateOldStorage();

  // --------------- Behavior: lesson pages ---------------

  /**
   * Behavior for individual ADEPT lesson pages.
   *
   * Reads drupalSettings.ilasAdept.currentLesson for GA4 event params.
   * DOM data attributes kept for CSS hooks only.
   */
  Drupal.behaviors.adeptLessonTracking = {
    attach: function (context) {
      var articles = once('adept-lesson', '[data-adept-lesson-page]', context);
      if (!articles.length) {
        return;
      }

      var settings = (drupalSettings.ilasAdept && drupalSettings.ilasAdept.currentLesson)
        ? drupalSettings.ilasAdept.currentLesson
        : null;

      if (!settings) {
        return;
      }

      var article = articles[0];
      var moduleId = settings.module_id;
      var lessonId = settings.lesson_id;
      var lessonTitle = settings.lesson_title;
      var mode = settings.mode;
      var effectiveUrl = settings.effective_url;

      // Fire lesson view event.
      sendEvent('adept_lesson_view', {
        module_id: moduleId,
        lesson_id: lessonId,
        lesson_title: lessonTitle,
        mode: mode
      });

      var completeBtn = article.querySelector('.adept-mark-complete');
      var statusBadge = article.querySelector('.adept-completion-status');
      var record = getLessonRecord(moduleId, lessonId);

      // Reflect prior completion state.
      if (record.status === 'completed') {
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
      var statusRegion = article.querySelector('#adept-lesson-status');

      if (startBtn) {
        startBtn.addEventListener('click', function (e) {
          var destination = sanitizeUrlForAnalytics(effectiveUrl);

          sendEvent('adept_lesson_start', {
            module_id: moduleId,
            lesson_id: lessonId,
            mode: mode,
            destination: destination
          });

          // Mark as in_progress (unless already completed).
          var current = getLessonRecord(moduleId, lessonId);
          if (current.status === 'not_started') {
            setLessonRecord(moduleId, lessonId, {
              status: 'in_progress',
              startedAt: new Date().toISOString(),
              completedAt: null
            });
          }

          if (statusRegion) {
            statusRegion.textContent = Drupal.t('Lesson started');
          }

          if (mode === 'link') {
            // Link mode — the <a> tag handles navigation to new tab.
            return;
          }

          // Embed / self_host modes — inject iframe.
          e.preventDefault();
          if (embedContainer && !embedContainer.querySelector('iframe')) {
            var iframeSrc = startBtn.getAttribute('data-adept-url');
            if (iframeSrc) {
              var iframe = document.createElement('iframe');
              iframe.src = iframeSrc;
              iframe.setAttribute('title', Drupal.t('ADEPT Lesson: @title', { '@title': lessonTitle }));
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

              // Move focus to the embed container for keyboard users.
              embedContainer.focus({ preventScroll: true });
            }
          }

          // Show mark complete button.
          if (completeBtn) {
            completeBtn.classList.remove('d-none');
          }
        });
      }

      // Mark complete button.
      if (completeBtn && record.status !== 'completed') {
        completeBtn.addEventListener('click', function () {
          var now = new Date().toISOString();
          var current = getLessonRecord(moduleId, lessonId);
          var startedAt = current.startedAt || now;

          // Compute time_to_complete_sec.
          var timeToComplete = null;
          try {
            var startMs = new Date(startedAt).getTime();
            var endMs = new Date(now).getTime();
            if (!isNaN(startMs) && !isNaN(endMs)) {
              timeToComplete = Math.round((endMs - startMs) / 1000);
            }
          }
          catch (e) {
            // Leave as null.
          }

          setLessonRecord(moduleId, lessonId, {
            status: 'completed',
            startedAt: startedAt,
            completedAt: now
          });

          sendEvent('adept_lesson_complete', {
            module_id: moduleId,
            lesson_id: lessonId,
            time_to_complete_sec: timeToComplete,
            mode: mode
          });

          completeBtn.disabled = true;
          completeBtn.textContent = Drupal.t('Completed');
          completeBtn.classList.add('btn-success');
          completeBtn.classList.remove('btn-outline-primary');

          if (statusBadge) {
            statusBadge.classList.remove('d-none');
          }

          if (statusRegion) {
            statusRegion.textContent = Drupal.t('Lesson marked complete');
          }
        });
      }

      // Download link tracking with outbound safety.
      var downloadLinks = article.querySelectorAll('.adept-download-link a');
      downloadLinks.forEach(function (link) {
        link.addEventListener('click', function (e) {
          var fileLabel = (link.textContent || '').trim().substring(0, 100);
          var params = {
            module_id: moduleId,
            lesson_id: lessonId,
            file_label: fileLabel,
            file_url: sanitizeUrlForAnalytics(link.href)
          };

          var isSameTab = (!link.target || link.target === '_self') && !link.hasAttribute('download');
          if (isSameTab) {
            e.preventDefault();
            var href = link.href;
            sendEvent('adept_download_click', params, function () {
              window.location.href = href;
            });
          } else {
            sendEvent('adept_download_click', params);
          }
        });
      });
    }
  };

  // --------------- Behavior: module landing pages ---------------

  /**
   * Behavior for ADEPT module landing pages (Views).
   *
   * Reads drupalSettings.ilasAdept.moduleLanding for module_id and total_lessons.
   * Updates progress bar and marks completed lesson cards from localStorage.
   */
  Drupal.behaviors.adeptModuleProgress = {
    attach: function (context) {
      var views = once('adept-progress', '.view-adept-lessons', context);
      if (!views.length) {
        return;
      }

      var settings = (drupalSettings.ilasAdept && drupalSettings.ilasAdept.moduleLanding)
        ? drupalSettings.ilasAdept.moduleLanding
        : null;

      if (!settings || !settings.module_id) {
        return;
      }

      var view = views[0];
      var moduleId = settings.module_id;
      var totalLessons = settings.total_lessons;

      sendEvent('adept_module_view', {
        module_id: moduleId,
        total_lessons: totalLessons
      });

      // Count completed lessons from DOM lesson cards + localStorage.
      // Uses actual lesson IDs from data attributes instead of assuming sequential numbering.
      var completedCount = 0;
      var lessonCards = view.querySelectorAll('article[data-adept-module][data-adept-lesson]');
      lessonCards.forEach(function (card) {
        var cardLessonId = parseInt(card.getAttribute('data-adept-lesson'), 10);
        if (!isNaN(cardLessonId)) {
          var rec = getLessonRecord(moduleId, cardLessonId);
          if (rec.status === 'completed') {
            completedCount++;
            card.classList.add('is-completed');
          }
        }
      });

      // Update progress bar.
      var progressBar = view.querySelector('.adept-progress-bar');
      if (progressBar && totalLessons > 0) {
        var percentage = Math.round((completedCount / totalLessons) * 100);

        // ARIA on the progressbar element (count-based, not percentage).
        progressBar.setAttribute('aria-valuemax', totalLessons);
        progressBar.setAttribute('aria-valuenow', completedCount);

        var fill = progressBar.querySelector('.adept-progress-fill');
        var label = view.querySelector('.adept-progress-label');

        if (fill) {
          fill.style.width = percentage + '%';
        }
        if (label) {
          label.textContent = completedCount + ' of ' + totalLessons + ' lessons completed (' + percentage + '%)';
        }
      }
    }
  };

})(Drupal, drupalSettings, once);
