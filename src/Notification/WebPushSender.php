<?php

namespace App\Notification;

use App\Entity\PushSubscription;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sends one Web Push (encrypted payload, VAPID authentication) with minishlink/web-push.
 * VAPID keys: VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY (the public one is also given to the front),
 * VAPID_SUBJECT: "mailto:..." or the site URL, so the push services can reach the sender.
 */
final class WebPushSender
{
    // A push not delivered within 24 hours (device offline) is dropped by the push service
    private const int TTL = 86400;

    private ?WebPush $webPush = null;

    public function __construct(
        #[Autowire(env: 'VAPID_PUBLIC_KEY')]
        private readonly string $publicKey,
        #[Autowire(env: 'VAPID_PRIVATE_KEY')]
        private readonly string $privateKey,
        #[Autowire(env: 'VAPID_SUBJECT')]
        private readonly string $subject,
    ) {
    }

    public function send(PushSubscription $subscription, string $payload, string $topic): MessageSentReport
    {
        return $this->webPush()->sendOneNotification(
            Subscription::create([
                'endpoint' => $subscription->getEndpoint(),
                'keys' => ['p256dh' => $subscription->getP256dh(), 'auth' => $subscription->getAuth()],
            ]),
            $payload,
            ['TTL' => self::TTL, 'urgency' => 'normal', 'topic' => $topic],
        );
    }

    /**
     * Created on first use: a missing key only fails the push, not the whole container.
     */
    private function webPush(): WebPush
    {
        if ('' === $this->publicKey || '' === $this->privateKey || '' === $this->subject) {
            throw new \LogicException('VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY and VAPID_SUBJECT must be set to send pushes.');
        }

        return $this->webPush ??= new WebPush([
            'VAPID' => [
                'subject' => $this->subject,
                'publicKey' => $this->publicKey,
                'privateKey' => $this->privateKey,
            ],
        ]);
    }
}
