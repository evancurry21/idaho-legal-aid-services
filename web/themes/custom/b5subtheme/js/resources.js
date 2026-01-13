/**
 * @file
 * Resources functionality for filtering and navigation.
 * Implements hybrid filter pattern: Pills for ≤6 topics, Dropdown for >6 topics.
 */

(function ($, Drupal, once) {
  'use strict';

  /**
   * Threshold for switching between pills and dropdown.
   * Pills are used when topic count is at or below this number.
   */
  const PILL_THRESHOLD = 6;

  /**
   * Resources filtering behavior with hybrid pill/dropdown pattern.
   */
  Drupal.behaviors.ilasResourcesFilter = {
    attach: function (context, settings) {
      // Initialize topic filter
      once('resources-filter', '.resource-filter-container', context).forEach(function (filterContainer) {

        // Collect all unique topics from the resource cards
        const resourceCards = document.querySelectorAll('.resource-card');
        const topicTextToIds = new Map();

        resourceCards.forEach(function (card) {
          const topicElements = card.querySelectorAll('.resource-topics .topic-item');
          const dataTopics = card.getAttribute('data-topics');

          if (dataTopics) {
            const topicIds = dataTopics.split(' ');

            topicElements.forEach(function (topicEl, index) {
              const topicText = topicEl.textContent.trim();
              if (topicIds[index] && topicText) {
                if (!topicTextToIds.has(topicText)) {
                  topicTextToIds.set(topicText, new Set());
                }
                topicTextToIds.get(topicText).add(topicIds[index]);
              }
            });
          }
        });

        // Sort topics alphabetically
        const sortedTopics = Array.from(topicTextToIds.keys()).sort();
        const topicCount = sortedTopics.length;

        // Determine which pattern to use based on topic count
        const usePills = topicCount <= PILL_THRESHOLD;

        if (usePills) {
          buildPillFilter(filterContainer, topicTextToIds, sortedTopics, resourceCards);
        } else {
          buildDropdownFilter(filterContainer, topicTextToIds, sortedTopics, resourceCards);
        }
      });

      // Initialize resource card interactions
      once('resource-cards', '.resource-card', context).forEach(function (card) {
        // Add hover effect
        card.addEventListener('mouseenter', function () {
          this.style.transform = 'translateY(-2px)';
        });

        card.addEventListener('mouseleave', function () {
          this.style.transform = '';
        });

        // Make entire card clickable
        card.addEventListener('click', function (e) {
          if (e.target.tagName !== 'A' && !e.target.closest('a')) {
            const link = this.querySelector('.resource-actions a');
            if (link) {
              link.click();
            }
          }
        });
      });
    }
  };

  /**
   * Build pill-based filter (for ≤6 topics).
   *
   * @param {Element} filterContainer - The filter container element.
   * @param {Map} topicTextToIds - Map of topic names to their IDs.
   * @param {Array} sortedTopics - Alphabetically sorted topic names.
   * @param {NodeList} resourceCards - All resource card elements.
   */
  function buildPillFilter(filterContainer, topicTextToIds, sortedTopics, resourceCards) {
    const pillsContainer = filterContainer.querySelector('.resource-filters--pills');
    const dropdownContainer = filterContainer.querySelector('.resource-filters--dropdown');

    // Show pills, hide dropdown
    pillsContainer.style.display = '';
    if (dropdownContainer) {
      dropdownContainer.style.display = 'none';
    }

    const pillList = pillsContainer.querySelector('.nav-pills');

    // Build topic pills
    sortedTopics.forEach(function (topicText) {
      const li = document.createElement('li');
      li.className = 'nav-item';
      li.setAttribute('role', 'presentation');

      const button = document.createElement('button');
      button.className = 'nav-link pill-link';
      button.setAttribute('role', 'tab');
      button.setAttribute('aria-selected', 'false');
      button.setAttribute('tabindex', '-1');

      // Store all IDs for this topic text as a space-separated string
      const topicIds = Array.from(topicTextToIds.get(topicText)).join(' ');
      button.setAttribute('data-filter', topicIds);
      button.textContent = topicText;

      li.appendChild(button);
      pillList.appendChild(li);
    });

    // Set up click handlers and keyboard navigation for pills
    const allPills = pillsContainer.querySelectorAll('.pill-link');
    setupPillInteractions(allPills, resourceCards);
  }

  /**
   * Set up pill click handlers and roving tabindex keyboard navigation.
   *
   * @param {NodeList} pills - All pill button elements.
   * @param {NodeList} resourceCards - All resource card elements.
   */
  function setupPillInteractions(pills, resourceCards) {
    const pillsArray = Array.from(pills);
    let activeIndex = 0;

    pillsArray.forEach(function (pill, index) {
      // Click handler
      pill.addEventListener('click', function (e) {
        e.preventDefault();
        activatePill(pillsArray, index, resourceCards);
      });

      // Keyboard handler
      pill.addEventListener('keydown', function (e) {
        let newIndex = activeIndex;

        switch (e.key) {
          case 'ArrowRight':
          case 'ArrowDown':
            e.preventDefault();
            newIndex = (activeIndex + 1) % pillsArray.length;
            break;
          case 'ArrowLeft':
          case 'ArrowUp':
            e.preventDefault();
            newIndex = (activeIndex - 1 + pillsArray.length) % pillsArray.length;
            break;
          case 'Home':
            e.preventDefault();
            newIndex = 0;
            break;
          case 'End':
            e.preventDefault();
            newIndex = pillsArray.length - 1;
            break;
          case 'Enter':
          case ' ':
            e.preventDefault();
            activatePill(pillsArray, activeIndex, resourceCards);
            return;
          default:
            return;
        }

        // Update roving tabindex
        pillsArray[activeIndex].setAttribute('tabindex', '-1');
        pillsArray[newIndex].setAttribute('tabindex', '0');
        pillsArray[newIndex].focus();
        activeIndex = newIndex;
      });
    });

    /**
     * Activate a pill and filter resources.
     *
     * @param {Array} pills - Array of pill elements.
     * @param {number} index - Index of pill to activate.
     * @param {NodeList} cards - Resource card elements.
     */
    function activatePill(pills, index, cards) {
      // Update ARIA and visual states
      pills.forEach(function (p, i) {
        p.classList.remove('active');
        p.setAttribute('aria-selected', 'false');
        p.setAttribute('tabindex', i === index ? '0' : '-1');
      });

      pills[index].classList.add('active');
      pills[index].setAttribute('aria-selected', 'true');
      activeIndex = index;

      // Filter resources
      const filterValue = pills[index].getAttribute('data-filter');
      filterResources(filterValue, cards);
    }
  }

  /**
   * Build dropdown-based filter (for >6 topics).
   *
   * @param {Element} filterContainer - The filter container element.
   * @param {Map} topicTextToIds - Map of topic names to their IDs.
   * @param {Array} sortedTopics - Alphabetically sorted topic names.
   * @param {NodeList} resourceCards - All resource card elements.
   */
  function buildDropdownFilter(filterContainer, topicTextToIds, sortedTopics, resourceCards) {
    const pillsContainer = filterContainer.querySelector('.resource-filters--pills');
    const dropdownContainer = filterContainer.querySelector('.resource-filters--dropdown');

    // Show dropdown, hide pills
    dropdownContainer.style.display = '';
    if (pillsContainer) {
      pillsContainer.style.display = 'none';
    }

    const select = dropdownContainer.querySelector('.resource-filter__dropdown');
    const statusRegion = dropdownContainer.querySelector('#resource-filter-status');

    // Build topic options
    sortedTopics.forEach(function (topicText) {
      const option = document.createElement('option');
      const topicIds = Array.from(topicTextToIds.get(topicText)).join(' ');
      option.value = topicIds;
      option.textContent = topicText;
      select.appendChild(option);
    });

    // Set up change handler
    select.addEventListener('change', function () {
      const filterValue = this.value;
      const visibleCount = filterResources(filterValue, resourceCards);

      // Announce result to screen readers
      if (statusRegion) {
        const totalCount = resourceCards.length;
        if (filterValue === 'all') {
          statusRegion.textContent = 'Showing all ' + totalCount + ' resources';
        } else {
          const selectedText = this.options[this.selectedIndex].text;
          statusRegion.textContent = 'Showing ' + visibleCount + ' of ' + totalCount + ' resources for ' + selectedText;
        }
      }
    });
  }

  /**
   * Filter resource cards based on selected topic.
   *
   * @param {string} filterValue - The filter value ('all' or space-separated topic IDs).
   * @param {NodeList} resourceCards - All resource card elements.
   * @returns {number} - Count of visible cards after filtering.
   */
  function filterResources(filterValue, resourceCards) {
    let visibleCount = 0;

    // Get all resource columns (cards are wrapped in columns)
    const resourceColumns = document.querySelectorAll('.resource-card-grid.row .col, .resource-card-grid .col');

    resourceColumns.forEach(function (column) {
      const card = column.querySelector('.resource-card');
      if (card) {
        const cardTopics = card.getAttribute('data-topics') || '';

        if (filterValue === 'all') {
          // Show all cards
          column.style.display = '';
          column.classList.remove('d-none');
          column.classList.add('d-flex');
          visibleCount++;
        } else {
          // Check if card has any of the filter topic IDs
          const filterTopicIds = filterValue.split(' ');
          const cardTopicIds = cardTopics.split(' ');
          const hasMatchingTopic = filterTopicIds.some(function (filterId) {
            return cardTopicIds.includes(filterId);
          });

          if (hasMatchingTopic) {
            column.style.display = '';
            column.classList.remove('d-none');
            column.classList.add('d-flex');
            visibleCount++;
          } else {
            column.style.display = 'none';
            column.classList.add('d-none');
            column.classList.remove('d-flex');
          }
        }
      }
    });

    return visibleCount;
  }

})(jQuery, Drupal, once);
