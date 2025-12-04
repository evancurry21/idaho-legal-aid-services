<?php

namespace Drupal\ilas_events\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for event API endpoints.
 */
class EventApiController extends ControllerBase {

  /**
   * Get upcoming events.
   */
  public function upcoming() {
    try {
      \Drupal::service('civicrm')->initialize();
      
      $limit = \Drupal::request()->query->get('limit', 10);
      $type = \Drupal::request()->query->get('type');
      
      $params = [
        'is_active' => 1,
        'is_public' => 1,
        'start_date' => ['>=' => date('Y-m-d')],
        'options' => [
          'limit' => $limit,
          'sort' => 'start_date ASC',
        ],
        'return' => [
          'id', 'title', 'summary', 'start_date', 'end_date',
          'is_online_registration', 'max_participants', 'is_monetary',
        ],
      ];
      
      if ($type) {
        $params['event_type_id'] = $type;
      }
      
      $events = civicrm_api3('Event', 'get', $params);
      
      // Add additional data
      foreach ($events['values'] as &$event) {
        // Get location
        if (!empty($event['loc_block_id'])) {
          try {
            $location = civicrm_api3('LocBlock', 'getsingle', [
              'id' => $event['loc_block_id'],
            ]);
            
            if (!empty($location['address_id'])) {
              $address = civicrm_api3('Address', 'getsingle', [
                'id' => $location['address_id'],
              ]);
              
              $event['location'] = $this->formatAddress($address);
            }
          }
          catch (\Exception $e) {
            // Ignore
          }
        }
        
        // Get registration count
        $event['registered_count'] = civicrm_api3('Participant', 'getcount', [
          'event_id' => $event['id'],
          'status_id' => ['NOT IN' => ['Cancelled']],
        ]);
        
        // Calculate available spots
        if (!empty($event['max_participants'])) {
          $event['available_spots'] = max(0, $event['max_participants'] - $event['registered_count']);
        }
        else {
          $event['available_spots'] = 'unlimited';
        }
        
        // Registration URL
        $event['registration_url'] = \Drupal::request()->getSchemeAndHttpHost() . 
          '/event/' . $event['id'] . '/register';
        
        // Event URL
        $event['url'] = \Drupal::request()->getSchemeAndHttpHost() . 
          '/event/' . $event['id'];
      }
      
      return new JsonResponse([
        'success' => TRUE,
        'count' => $events['count'],
        'events' => array_values($events['values']),
      ]);
    }
    catch (\Exception $e) {
      // Log the detailed error securely
      \Drupal::logger('ilas_events.api')->error('Events API error: @error', [
        '@error' => $e->getMessage(),
        'ip' => \Drupal::request()->getClientIp(),
        'uri' => \Drupal::request()->getRequestUri(),
      ]);

      // Return generic error to client
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'An error occurred while fetching events. Please try again.',
        'error_code' => 'ERR_' . strtoupper(substr(md5(microtime()), 0, 8)),
      ], 500);
    }
  }

  /**
   * Format address for API.
   */
  protected function formatAddress($address) {
    $parts = [];
    
    if (!empty($address['name'])) {
      $parts[] = $address['name'];
    }
    
    if (!empty($address['street_address'])) {
      $parts[] = $address['street_address'];
    }
    
    $city_state_zip = [];
    if (!empty($address['city'])) {
      $city_state_zip[] = $address['city'];
    }
    if (!empty($address['state_province'])) {
      $city_state_zip[] = $address['state_province'];
    }
    if (!empty($address['postal_code'])) {
      $city_state_zip[] = $address['postal_code'];
    }
    
    if (!empty($city_state_zip)) {
      $parts[] = implode(', ', $city_state_zip);
    }
    
    return implode(', ', $parts);
  }
}