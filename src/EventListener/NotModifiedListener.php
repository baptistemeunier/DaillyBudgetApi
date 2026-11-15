<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers 304 Not Modified when the client's If-None-Match matches the ETag
 * set by API Platform (AddHeadersProcessor hashes the response body).
 *
 * The response is still computed: this saves bandwidth and client-side parsing, not server work.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final class NotModifiedListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$request->isMethodCacheable() || !$response->isSuccessful() || null === $response->getEtag()) {
            return;
        }

        $response->isNotModified($request);
    }
}
