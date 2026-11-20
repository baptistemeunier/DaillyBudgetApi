<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Dto\UpdateTransaction;
use App\Entity\CategorizationRule;
use App\Entity\Transaction;
use App\Entity\TransactionSplit;
use App\Entity\User;
use App\Enum\CategoryType;
use App\Enum\SplitSource;
use App\Enum\TransactionDirection;
use App\Repository\CategorizationRuleRepository;
use App\Repository\TransactionRepository;
use App\Security\Voter\TransactionVoter;
use App\Service\SavingMirrorLinker;
use App\Service\TransactionCategorizer;
use App\Util\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * PATCH /transactions/{id} (UpdateTransaction): replaces the splits of the transaction, then checks them
 * (voter + entity validation), keeps the mirrors of the saving splits (see SavingMirrorLinker) and recomputes
 * the balance of the manual accounts whose mirror transactions changed.
 * With confirm, marks the splits set by a rule as confirmed by the user.
 * With hidden, hides the transaction from the budget (or shows it again).
 * With rememberCategory, saves the rule "merchant key + direction → category" and applies it to the similar transactions (async).
 */
final readonly class TransactionProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private SavingMirrorLinker $savingMirrorLinker,
        private TransactionRepository $transactionRepository,
        private CategorizationRuleRepository $ruleRepository,
        private TransactionCategorizer $categorizer,
        private EntityManagerInterface $entityManager,
        private Security $security,
        private ValidatorInterface $validator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Transaction
    {
        if (!$data instanceof UpdateTransaction) {
            throw new \LogicException('data must be an instance of UpdateTransaction');
        }

        // Already loaded (and checked) by the provider: same managed instance
        $transaction = $this->transactionRepository->find($uriVariables['id'] ?? null)
            ?? throw new NotFoundHttpException('Transaction not found.');

        if ($data->confirm && null !== $data->splits) {
            throw new UnprocessableEntityHttpException('confirm cannot be combined with splits: sent splits are always "manual".');
        }

        if (null !== $data->splits) {
            $this->replaceSplits($transaction, $data);
        }

        if ($data->confirm) {
            $this->confirmRuleSplits($transaction);
        }

        if (null !== $data->hidden) {
            $transaction->setHidden($data->hidden);
        }

        // The new splits can only use categories and accounts visible to the user
        if (!$this->security->isGranted(TransactionVoter::UPDATE, $transaction)) {
            throw new AccessDeniedHttpException('The user cannot use these categories or accounts.');
        }

        $violations = $this->validator->validate($transaction);
        if (count($violations) > 0) {
            throw new ValidationException($violations);
        }

        $rule = $data->rememberCategory ? $this->rememberCategory($transaction) : null;

        $accounts = $this->savingMirrorLinker->link($transaction);

        $result = $this->persistProcessor->process($transaction, $operation, $uriVariables, $context);

        if ($accounts) {
            foreach ($accounts as $account) {
                $account->setBalance(
                    $this->transactionRepository->sumByAccount($account),
                    $account->getBalanceCurrency() ?? $transaction->getCurrency(),
                    'computed',
                    new \DateTimeImmutable(),
                );
            }
            $this->entityManager->flush();
        }

        // Rule flushed: the similar transactions are categorized in the background
        if ($rule) {
            $this->categorizer->requestCategorization();
        }

        return $result;
    }

    /**
     * All the splits are replaced (SavingMirrorLinker reads the previous ones from the collection snapshot);
     * every split sent by the user is "manual".
     */
    private function replaceSplits(Transaction $transaction, UpdateTransaction $data): void
    {
        foreach ($transaction->getSplits()->toArray() as $split) {
            $transaction->removeSplit($split);
        }

        foreach ($data->splits as $input) {
            $transaction->addSplit(new TransactionSplit()
                ->setCategory($input->category)
                ->setAmount($input->amount)
                ->setCounterpartAccount($input->counterpartAccount)
                ->setCounterpartAmount($input->counterpartAmount));
        }
    }

    /**
     * The splits set by a rule become "confirmed". Idempotent: already confirmed splits are accepted.
     */
    private function confirmRuleSplits(Transaction $transaction): void
    {
        $confirmable = $transaction->getSplits()->filter(
            static fn (TransactionSplit $split) => in_array($split->getSource(), [SplitSource::RULE, SplitSource::CONFIRMED], true),
        );

        if ($confirmable->isEmpty()) {
            throw new UnprocessableEntityHttpException('confirm requires a split set by a categorization rule.');
        }

        foreach ($confirmable as $split) {
            $split->setSource(SplitSource::CONFIRMED);
        }
    }

    /**
     * Creates or updates the rule for the merchant key and the direction of the transaction (persisted, not flushed).
     */
    private function rememberCategory(Transaction $transaction): CategorizationRule
    {
        $splits = $transaction->getSplits();
        $split = $splits->first();
        $cents = Money::toCents($transaction->getAmount());

        if (1 !== $splits->count() || null === $split->getCategory() || Money::toCents($split->getAmount()) !== $cents) {
            throw new UnprocessableEntityHttpException('rememberCategory requires a single split of the whole amount, with a category.');
        }

        if (CategoryType::SAVING === $split->getCategory()->getType()) {
            throw new UnprocessableEntityHttpException('A saving category cannot be remembered: its mirror needs a manual account.');
        }

        $account = $transaction->getAccount();
        if (null === $transaction->getMerchantKey() || $account->isManual()) {
            throw new UnprocessableEntityHttpException('This transaction has no merchant to remember (manual account or description without words).');
        }

        $direction = TransactionDirection::fromCents($cents);
        $rule = $this->ruleRepository->findOneSame($transaction->getMerchantKey(), $direction);

        if (null === $rule) {
            // Rules are shared: the owner is the user who created it (only they can delete it)
            $user = $this->security->getUser();
            $rule = new CategorizationRule()
                ->setOwner($user instanceof User ? $user : $account->getOwner())
                ->setPattern($transaction->getMerchantKey())
                ->setDirection($direction);
            $this->entityManager->persist($rule);
        }

        return $rule->setCategory($split->getCategory());
    }
}
