<?php

namespace App\Notification;

use App\Entity\Notification;
use App\Enum\NotificationType;

/**
 * Builds the Web Push payload of a notification. The text is not written here: the service worker composes it
 * from type + data with the front i18n (same keys as the inbox: notifications.<TYPE>.title|body), in the language
 * of the device (locale). The texts live in one place, the front.
 */
final readonly class PushMessageFactory
{
    private const array LOCALES = ['fr', 'en'];
    private const string DEFAULT_LOCALE = 'fr';

    /**
     * JSON payload: { type, data, locale, hideAmounts, url, tag, notification }.
     *
     * @param bool $hideAmounts pushes are shown on the lock screen: the service worker leaves the amounts out
     */
    public function create(Notification $notification, string $locale, bool $hideAmounts): string
    {
        return $this->payload(
            $notification->getType(),
            $notification->getData(),
            $locale,
            $hideAmounts,
            $this->url($notification),
            $this->tag($notification),
            sprintf('/notifications/%s', $notification->getId()->toRfc4122()),
        );
    }

    /**
     * Payload from its parts, without a stored notification (also used by app:notifications:test-push).
     *
     * @param array<string, mixed> $data
     */
    public function payload(NotificationType $type, array $data, string $locale, bool $hideAmounts, string $url, string $tag, ?string $notificationIri): string
    {
        return json_encode([
            'type' => $type->value,
            'data' => (object) $data,
            'locale' => in_array($locale, self::LOCALES, true) ? $locale : self::DEFAULT_LOCALE,
            'hideAmounts' => $hideAmounts,
            'url' => $url,
            'tag' => $tag,
            'notification' => $notificationIri,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Page of the app opened on tap for a type (CONNECTION_TO_CONFIGURE opens the consent link when known).
     */
    public static function path(NotificationType $type): string
    {
        return match ($type) {
            NotificationType::CONNECTION_EXPIRING,
            NotificationType::CONNECTION_EXPIRED,
            NotificationType::CONNECTION_TO_CONFIGURE => '/profile/connections',
            NotificationType::MONTH_CLOSING => '/transactions/to-categorize',
        };
    }

    /**
     * Web Push "Topic" header: a push not yet delivered (device offline) is replaced by the next one on the same
     * subject instead of piling up. At most 32 characters of the URL-safe base64 alphabet.
     */
    public function topic(Notification $notification): string
    {
        $hash = hash('sha256', $notification->getType()->value . ':' . $notification->getSubjectId()?->toRfc4122(), true);

        return substr(rtrim(strtr(base64_encode($hash), '+/', '-_'), '='), 0, 32);
    }

    /**
     * Notification "tag" for the service worker: a notification still displayed is replaced by the new one
     * of the same type and subject.
     */
    private function tag(Notification $notification): string
    {
        $type = $notification->getType();
        $suffix = NotificationType::MONTH_CLOSING === $type
            ? ($notification->getData()['month'] ?? '')
            : $notification->getSubjectId()?->toRfc4122();

        return sprintf('%s:%s', $type->value, $suffix);
    }

    /**
     * Page opened on tap. ?notification= lets the front mark the notification as read.
     */
    private function url(Notification $notification): string
    {
        $path = NotificationType::CONNECTION_TO_CONFIGURE === $notification->getType()
            // The consent page of the bank (outside the app): the front cannot mark it as read on opening
            ? $notification->getAccountConnection()?->getExternalLink() ?? self::path($notification->getType())
            : self::path($notification->getType());

        if (!str_starts_with($path, '/')) {
            return $path;
        }

        return sprintf('%s%snotification=%s', $path, str_contains($path, '?') ? '&' : '?', $notification->getId()->toRfc4122());
    }
}
