<?php

namespace App\Command;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Notification\PushMessageFactory;
use App\Notification\WebPushSender;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Sends a test Web Push right now (synchronously, without the queue) and prints the answer of the push service for
 * each device. No Notification is created and nothing is changed in the database.
 *
 * Without --type, the payload has type "TEST" with a ready-made title and body, shown as is by the service worker
 * (checks the delivery only). With --type=<NotificationType>, it is the payload of a real notification of that type
 * with sample data, in the locale of each device (checks the text written by the service worker too).
 *
 *   bin/console app:notifications:test-push                              lists the subscribed devices
 *   bin/console app:notifications:test-push <user>                       pushes to every device of the user (username or uuid)
 *   bin/console app:notifications:test-push --device=<id>                pushes to one device (push subscription uuid)
 *   bin/console app:notifications:test-push <user> --type=MONTH_CLOSING  pushes a sample MONTH_CLOSING notification
 */
#[AsCommand(
    name: 'app:notifications:test-push',
    description: 'Send a test Web Push to the devices of a user (or list the subscribed devices)',
)]
final readonly class TestPushCommand
{
    public function __construct(
        private PushSubscriptionRepository $subscriptionRepository,
        private UserRepository $userRepository,
        private WebPushSender $sender,
        private PushMessageFactory $messageFactory,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Username (OAuth subject) or uuid of the user; omitted: list the devices')]
        ?string $user = null,
        #[Option(description: 'Uuid of a single push subscription to target')]
        ?string $device = null,
        #[Option(description: 'Notification type to simulate (a NotificationType, e.g. MONTH_CLOSING, BALANCE_LOW, BUDGET_EXCEEDED); omitted: a "TEST" push with --title and --body')]
        ?string $type = null,
        #[Option(description: 'With --type: ask the service worker to leave the amounts out')]
        bool $hideAmounts = false,
        #[Option(description: 'Title of the push (TEST only)')]
        string $title = 'Notification de test',
        #[Option(description: 'Body of the push (TEST only)')]
        string $body = 'Si tu lis ceci, les notifications fonctionnent sur cet appareil.',
        #[Option(description: 'Page opened on tap (TEST only; with --type, the page of the type)')]
        string $url = '/',
    ): int {
        if (null === $user && null === $device) {
            $this->listDevices($io);

            return Command::SUCCESS;
        }

        $notificationType = null;
        if (null !== $type) {
            $notificationType = NotificationType::tryFrom(strtoupper($type));
            if (null === $notificationType) {
                $io->error(sprintf('Unknown type "%s". Available: %s.', $type, implode(', ', array_map(static fn (NotificationType $case) => $case->value, NotificationType::cases()))));

                return Command::FAILURE;
            }
        }

        $subscriptions = $this->findSubscriptions($io, $user, $device);
        if (!$subscriptions) {
            return Command::FAILURE;
        }

        // Type "TEST": the service worker shows title and body as they are, without the i18n
        $testPayload = json_encode([
            'type' => 'TEST',
            'data' => (object) [],
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'tag' => 'TEST',
            'notification' => null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (null !== $notificationType) {
            $io->writeln(sprintf('Sample data: <comment>%s</comment>', json_encode($this->sampleData($notificationType), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }

        $rows = [];
        $failed = false;
        foreach ($subscriptions as $subscription) {
            try {
                // Same payload as a real notification, in the locale of the device, without a stored notification
                $payload = null === $notificationType ? $testPayload : $this->messageFactory->payload(
                    $notificationType,
                    $this->sampleData($notificationType),
                    (string) $subscription->getLocale(),
                    $hideAmounts,
                    PushMessageFactory::path($notificationType, $this->sampleData($notificationType)['accountId'] ?? null),
                    sprintf('%s:test', $notificationType->value),
                    null,
                );
                $report = $this->sender->send($subscription, $payload, 'test');
                $status = $report->getResponse()?->getStatusCode();
                $result = match (true) {
                    $report->isSuccess() => sprintf('<info>OK</info> (%s)', $status),
                    $report->isSubscriptionExpired() => sprintf('<error>EXPIRED</error> (%s): subscription dead, the device must subscribe again', $status),
                    default => sprintf('<error>FAILED</error> (%s): %s', $status ?? 'network', $report->getReason()),
                };
                $failed = $failed || !$report->isSuccess();
            } catch (\Throwable $exception) {
                $result = sprintf('<error>ERROR</error>: %s', $exception->getMessage());
                $failed = true;
            }

            $rows[] = [$subscription->getId()->toRfc4122(), $this->device($subscription), parse_url((string) $subscription->getEndpoint(), PHP_URL_HOST), $result];
        }

        $io->table(['Subscription', 'Device', 'Push service', 'Result'], $rows);

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Data of a notification of this type, like the ones created by the daily task.
     *
     * @return array<string, mixed>
     */
    private function sampleData(NotificationType $type): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $bank = ['bankId' => 'SANDBOXFINANCE_SFIN0000', 'bankName' => 'Sandbox Finance'];
        $account = ['accountId' => '00000000-0000-0000-0000-000000000000', 'accountName' => 'Compte courant'];
        $transaction = ['currency' => 'EUR', 'bookingAt' => $now->format('Y-m-d')];
        $budget = ['month' => $now->format('Y-m'), 'budgeted' => '400.00', 'currency' => 'EUR', 'categoryCode' => 'food', 'categoryName' => null];

        return match ($type) {
            NotificationType::CONNECTION_EXPIRING => $bank + ['expiresAt' => $now->modify('+3 days')->format(\DateTimeInterface::ATOM), 'daysLeft' => 3],
            NotificationType::CONNECTION_EXPIRED => $bank + ['expiresAt' => $now->modify('-1 day')->format(\DateTimeInterface::ATOM)],
            NotificationType::CONNECTION_TO_CONFIGURE => $bank,
            NotificationType::MONTH_CLOSING => ['count' => 8, 'month' => $now->format('Y-m'), 'daysLeft' => 5],
            NotificationType::BALANCE_LOW => $account + ['balance' => '87.40', 'threshold' => '150.00', 'currency' => 'EUR'],
            NotificationType::LARGE_TRANSACTION => $account + $transaction + ['amount' => '-249.99', 'description' => 'FNAC PARIS'],
            NotificationType::INCOME_RECEIVED => $account + $transaction + ['amount' => '2150.00', 'description' => 'VIR SALAIRE', 'categoryCode' => 'income.salary', 'categoryName' => null],
            NotificationType::BUDGET_ALMOST_EXCEEDED => $budget + ['spent' => '330.00', 'percent' => 82],
            NotificationType::BUDGET_EXCEEDED => $budget + ['spent' => '432.50', 'percent' => 108],
        };
    }

    /**
     * @return PushSubscription[]
     */
    private function findSubscriptions(SymfonyStyle $io, ?string $user, ?string $device): array
    {
        if (null !== $device) {
            $subscription = Uuid::isValid($device) ? $this->subscriptionRepository->find(Uuid::fromString($device)) : null;
            if (!$subscription) {
                $io->error(sprintf('No push subscription "%s".', $device));

                return [];
            }

            return [$subscription];
        }

        $owner = Uuid::isValid($user) ? $this->userRepository->find(Uuid::fromString($user)) : null;
        $owner ??= $this->userRepository->findOneBy(['username' => $user]);
        if (!$owner instanceof User) {
            $io->error(sprintf('No user "%s" (username or uuid).', $user));

            return [];
        }

        $subscriptions = $this->subscriptionRepository->findBy(['owner' => $owner]);
        if (!$subscriptions) {
            $io->warning(sprintf('User "%s" has no subscribed device: allow the notifications in the app first.', $owner->getUsername()));
        }

        return $subscriptions;
    }

    private function listDevices(SymfonyStyle $io): void
    {
        $rows = array_map(fn (PushSubscription $subscription) => [
            $subscription->getId()->toRfc4122(),
            $subscription->getOwner()?->getUsername(),
            $this->device($subscription),
            $subscription->getLocale(),
            $subscription->getCreatedAt()?->format('Y-m-d H:i'),
            $subscription->getLastSuccessAt()?->format('Y-m-d H:i') ?? '-',
        ], $this->subscriptionRepository->findBy([], ['createdAt' => 'DESC']));

        if (!$rows) {
            $io->warning('No subscribed device.');

            return;
        }

        $io->table(['Subscription', 'User', 'Device', 'Locale', 'Subscribed', 'Last push'], $rows);
    }

    private function device(PushSubscription $subscription): string
    {
        $userAgent = (string) $subscription->getUserAgent();
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'SamsungBrowser/') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => '?',
        };
        $os = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            (bool) preg_match('/iPhone|iPad|iPod/', $userAgent) => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => '?',
        };

        return sprintf('%s · %s', $browser, $os);
    }
}
