<?php

namespace App\Util;

use ApiPlatform\Validator\Exception\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Period of whole months: "2026-11" (one month) or "2026-10..2026-11" (both months included).
 * Covers [start, end[ : from the 1st of "from" to the 1st of the month after "to".
 */
final readonly class Period
{
    public const string MONTH_PATTERN = '\d{4}-(?:0[1-9]|1[0-2])';
    public const string PATTERN = self::MONTH_PATTERN . '(?:\.\.' . self::MONTH_PATTERN . ')?';

    private function __construct(
        public string $period,
        public string $from,
        public string $to,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public int $months,
    ) {
    }

    /**
     * @throws NotFoundHttpException invalid format (normally rejected by the route requirements)
     * @throws ValidationException last month before the first one
     */
    public static function fromString(string $period): self
    {
        if (1 !== preg_match('/^' . self::PATTERN . '$/', $period)) {
            throw new NotFoundHttpException('Invalid period.');
        }

        [$from, $to] = explode('..', $period . '..' . $period); // a single month is from = to
        if ($to < $from) { // YYYY-MM strings compare chronologically
            $message = 'The last month cannot be before the first one.';
            throw new ValidationException(new ConstraintViolationList([
                new ConstraintViolation($message, $message, [], null, 'period', $period),
            ]));
        }

        $months = ((int) substr($to, 0, 4) - (int) substr($from, 0, 4)) * 12
            + (int) substr($to, 5, 2) - (int) substr($from, 5, 2) + 1;

        return new self(
            $from === $to ? $from : $from . '..' . $to,
            $from,
            $to,
            new \DateTimeImmutable($from . '-01 00:00:00'),
            new \DateTimeImmutable($to . '-01 00:00:00')->modify('first day of next month'),
            $months,
        );
    }

    /**
     * Months of the period, in order: ["2026-10", "2026-11"].
     *
     * @return list<string>
     */
    public function monthKeys(): array
    {
        $keys = [];
        for ($month = $this->start; $month < $this->end; $month = $month->modify('first day of next month')) {
            $keys[] = $month->format('Y-m');
        }

        return $keys;
    }
}
