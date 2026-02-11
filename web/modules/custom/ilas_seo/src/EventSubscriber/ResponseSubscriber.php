<?php

namespace Drupal\ilas_seo\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Strips information-disclosure headers and normalizes user profile responses.
 */
class ResponseSubscriber implements EventSubscriberInterface {

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs a ResponseSubscriber.
   */
  public function __construct(AccountProxyInterface $currentUser) {
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after Drupal core's ResponseGeneratorSubscriber (priority 0)
    // so the X-Generator header is already set when we remove it.
    return [
      KernelEvents::RESPONSE => [
        ['onResponse', -10],
      ],
    ];
  }

  /**
   * Removes X-Generator header and normalizes user profile responses.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $response = $event->getResponse();

    // L-1: Strip X-Generator header to reduce CMS fingerprinting.
    $response->headers->remove('X-Generator');

    // M-3: Normalize anonymous user profile responses to prevent enumeration.
    // Without this, /user/1 returns 403 (exists) while /user/99999 returns 404
    // (doesn't exist), allowing attackers to enumerate valid user IDs.
    if ($this->currentUser->isAnonymous()) {
      $request = $event->getRequest();
      $route = $request->attributes->get('_route', '');
      if ($route === 'entity.user.canonical' && $response->getStatusCode() === 404) {
        $response->setStatusCode(403);
      }
    }
  }

}
