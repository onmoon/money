<?php

declare(strict_types=1);

namespace OnMoon\Money;

use Money\Converter;
use Money\Currencies;
use Money\Currency as LibCurrency;
use Money\Money as LibMoney;
use OnMoon\Money\Exception\CannotCreateMoney;
use OnMoon\Money\Exception\CannotWorkWithMoney;
use RuntimeException;

use function array_map;
use function bcdiv;
use function bcmul;
use function Safe\preg_match;
use function str_pad;
use function strpos;
use function substr;

use const STR_PAD_RIGHT;

abstract class BaseMoney
{
    private const HUMAN_READABLE_NAME = 'Money';

    /** @psalm-var numeric-string */
    protected string $amount;

    /**
     * @psalm-param  numeric-string $amount
     * @psalm-param  non-empty-string $currency
     */
    final private function __construct(string $amount, protected string $currency)
    {
        $libCurrency = new LibCurrency($currency);

        if (! static::getAllowedCurrencies()->contains($libCurrency)) {
            throw CannotCreateMoney::becauseCurrencyNotAllowed(
                static::humanReadableName(),
                $amount,
                $currency,
            );
        }

        $currencySubunits = static::getAllowedCurrencies()->subunitFor($libCurrency);

        if ($currencySubunits > static::classSubunits()) {
            throw CannotWorkWithMoney::becauseCurrencyExceedsSubunitLimit(
                static::class,
                $amount,
                $currency,
                $currencySubunits,
                static::classSubunits(),
            );
        }

        if (! preg_match($this->getAmountFormatRegexp($currencySubunits), $amount)) {
            throw CannotCreateMoney::becauseAmountFormatIsInvalid(
                static::humanReadableName(),
                $amount,
                $currency,
                $this->getAmountFormatRegexp($currencySubunits),
            );
        }

        $this->amount   = self::toSubunits($amount);
        $this->currency = $currency;
    }

    /**
     * @psalm-param numeric-string $amount
     *
     * @return static
     */
    final public static function create(string $amount, Currency $currency): self
    {
        $money = new static($amount, $currency->getCode());

        $money::validate($money);

        if ($money::amountMustBeGreaterThanZero() && ! $money->isPositive()) {
            throw CannotCreateMoney::becauseAmountMustBeGreaterThanZero(
                static::humanReadableName(),
                $amount,
                $currency->getCode(),
            );
        }

        if ($money::amountMustBeZeroOrGreater() && $money->isNegative()) {
            throw CannotCreateMoney::becauseAmountMustBeZeroOrGreater(
                static::humanReadableName(),
                $amount,
                $currency->getCode(),
            );
        }

        if ($money::amountMustBeZeroOrLess() && $money->isPositive()) {
            throw CannotCreateMoney::becauseAmountMustBeZeroOrLess(
                static::humanReadableName(),
                $amount,
                $currency->getCode(),
            );
        }

        if ($money::amountMustBeLessThanZero() && ! $money->isNegative()) {
            throw CannotCreateMoney::becauseAmountMustBeLessThanZero(
                static::humanReadableName(),
                $amount,
                $currency->getCode(),
            );
        }

        return $money;
    }

    final public static function createFromMoney(self $money): self
    {
        self::assertSameSubUnit($money, __FUNCTION__);

        return static::create($money->getAmount(), $money->getCurrency());
    }

    final public function convert(Converter $converter, Currency $toCurrency): self
    {
        return self::createFromLibMoney(
            $converter->convert($this->getLibMoney(), new LibCurrency($toCurrency->getCode())),
        );
    }

    // phpcs:disable Squiz.Commenting.FunctionComment.WrongStyle
    // Methods from original Money library

    final public function isSameCurrency(self $other): bool
    {
        return $this->getLibMoney()->isSameCurrency($other->getLibMoney());
    }

    final public function equals(self $other): bool
    {
        self::assertSameSubUnit($other, __FUNCTION__);

        return $this->getLibMoney()->equals($other->getLibMoney());
    }

    final public function compare(self $other): int
    {
        self::assertSameSubUnit($other, __FUNCTION__);

        return $this->getLibMoney()->compare($other->getLibMoney());
    }

    final public function greaterThan(self $other): bool
    {
        self::assertSameSubUnit($other, __FUNCTION__);

        return $this->getLibMoney()->greaterThan($other->getLibMoney());
    }

    final public function greaterThanOrEqual(self $other): bool
    {
        self::assertSameSubUnit($other, __FUNCTION__);

        return $this->getLibMoney()->greaterThanOrEqual($other->getLibMoney());
    }

    final public function lessThan(self $other): bool
    {
        self::assertSameSubUnit($other, __FUNCTION__);

        return $this->getLibMoney()->lessThan($other->getLibMoney());
    }

    final public function lessThanOrEqual(self $other): bool
    {
        self::assertSameSubUnit($other, __FUNCTION__);

        return $this->getLibMoney()->lessThanOrEqual($other->getLibMoney());
    }

    /** @psalm-return numeric-string */
    final public function getAmount(): string
    {
        return $this->formatAmount(self::fromSubunits($this->amount));
    }

    /** @psalm-return  numeric-string */
    private function formatAmount(string $amount): string
    {
        $currencySubunits = static::getAllowedCurrencies()->subunitFor(new LibCurrency($this->currency));
        $dotPosition      = strpos($amount, '.');

        if ($dotPosition === false) {
            throw new RuntimeException('Invalid money amount format');
        }

        /** @psalm-var  numeric-string $formattedAmount */
        $formattedAmount = substr(
            $amount,
            0,
            $currencySubunits === 0 ?
                $dotPosition + $currencySubunits :
                $dotPosition + $currencySubunits + 1,
        );

        return $formattedAmount;
    }

    final public function getCurrency(): Currency
    {
        return Currency::create($this->currency);
    }

    final public function add(self ...$addends): self
    {
        return self::createFromLibMoney(
            $this->getLibMoney()->add(
                ...array_map(
                    static function (self $addend): LibMoney {
                        self::assertSameSubUnit($addend, 'add');

                        return $addend->getLibMoney();
                    },
                    $addends,
                ),
            ),
        );
    }

    final public function subtract(self ...$subtrahends): self
    {
        return self::createFromLibMoney(
            $this->getLibMoney()->subtract(
                ...array_map(
                    static function (self $subtrahend): LibMoney {
                        self::assertSameSubUnit($subtrahend, 'subtract');

                        return $subtrahend->getLibMoney();
                    },
                    $subtrahends,
                ),
            ),
        );
    }

    /**
     * @psalm-param int|numeric-string $multiplier
     * @psalm-param LibMoney::ROUND_*  $roundingMode
     */
    final public function multiply(int|string $multiplier, int $roundingMode = LibMoney::ROUND_UP): self
    {
        return self::createFromLibMoney(
            $this->getLibMoney()->multiply($multiplier, $roundingMode),
        );
    }

    /**
     * @psalm-param int|numeric-string $divisor
     * @psalm-param LibMoney::ROUND_*  $roundingMode
     */
    final public function divide(int|string $divisor, int $roundingMode = LibMoney::ROUND_UP): self
    {
        return self::createFromLibMoney(
            $this->getLibMoney()->divide($divisor, $roundingMode),
        );
    }

    final public function mod(self $divisor): self
    {
        self::assertSameSubUnit($divisor, __FUNCTION__);

        return self::createFromLibMoney(
            $this->getLibMoney()->mod($divisor->getLibMoney()),
        );
    }

    /** @return self[] */
    final public function allocate(string ...$ratios): array
    {
        /** @psalm-var  non-empty-array<array-key, float> $ratios */
        $ratios = array_map('floatval', $ratios);

        return array_map(
            function (LibMoney $money): self {
                return $this->createFromLibMoney($money);
            },
            $this->getLibMoney()->allocate($ratios),
        );
    }

    /**
     * @psalm-param positive-int $n
     *
     * @return self[]
     */
    final public function allocateTo(int $n): array
    {
        return array_map(
            function (LibMoney $money): self {
                return $this->createFromLibMoney($money);
            },
            $this->getLibMoney()->allocateTo($n),
        );
    }

    final public function ratioOf(self $money): string
    {
        self::assertSameSubUnit($money, __FUNCTION__);

        return $this->getLibMoney()->ratioOf($money->getLibMoney());
    }

    final public function absolute(): self
    {
        return self::createFromLibMoney($this->getLibMoney()->absolute());
    }

    final public function negative(): self
    {
        return self::createFromLibMoney($this->getLibMoney()->negative());
    }

    final public function isZero(): bool
    {
        return $this->getLibMoney()->isZero();
    }

    final public function isPositive(): bool
    {
        return $this->getLibMoney()->isPositive();
    }

    final public function isNegative(): bool
    {
        return $this->getLibMoney()->isNegative();
    }

    /** @return string[] */
    final public function jsonSerialize(): array
    {
        return [
            'amount' => $this->getAmount(),
            'currency' => $this->getCurrency()->jsonSerialize(),
        ];
    }

    final public static function min(self $first, self ...$collection): self
    {
        $min = $first;

        foreach ($collection as $money) {
            $first::assertSameSubUnit($money, __FUNCTION__);

            if ($money->greaterThanOrEqual($min)) {
                continue;
            }

            $min = $money;
        }

        return $min;
    }

    final public static function max(self $first, self ...$collection): self
    {
        $max = $first;

        foreach ($collection as $money) {
            $first::assertSameSubUnit($money, __FUNCTION__);

            if ($money->lessThanOrEqual($max)) {
                continue;
            }

            $max = $money;
        }

        return $max;
    }

    final public static function sum(self $first, self ...$collection): self
    {
        $method = __FUNCTION__;

        return $first->createFromLibMoney(
            $first->getLibMoney()::sum(
                $first->getLibMoney(),
                ...array_map(
                    static function (self $money) use ($first, $method): LibMoney {
                        $first::assertSameSubUnit($money, $method);

                        return $money->getLibMoney();
                    },
                    $collection,
                ),
            ),
        );
    }

    final public static function avg(self $first, self ...$collection): self
    {
        $method = __FUNCTION__;

        return $first->createFromLibMoney(
            $first->getLibMoney()::avg(
                $first->getLibMoney(),
                ...array_map(
                    static function (self $money) use ($first, $method): LibMoney {
                        $first::assertSameSubUnit($money, $method);

                        return $money->getLibMoney();
                    },
                    $collection,
                ),
            ),
        );
    }

    public function __toString(): string
    {
        return $this->getAmount() . ' ' . (string) $this->getCurrency();
    }

    public static function humanReadableName(): string
    {
        return self::HUMAN_READABLE_NAME;
    }

    abstract protected static function classSubunits(): int;

    abstract protected static function getAllowedCurrencies(): Currencies;

    protected static function amountMustBeZeroOrGreater(): bool
    {
        return false;
    }

    protected static function amountMustBeGreaterThanZero(): bool
    {
        return false;
    }

    protected static function amountMustBeZeroOrLess(): bool
    {
        return false;
    }

    protected static function amountMustBeLessThanZero(): bool
    {
        return false;
    }

    /** @throws CannotCreateMoney */
    protected static function validate(self $money): void
    {
    }

    /**
     * @psalm-param numeric-string $amount
     *
     * @psalm-return numeric-string
     */
    private static function fromSubunits(string $amount): string
    {
        /*  @phpstan-ignore-next-line */

        /**
         * wrong detection return type, conflict with psalm
         *
         * @phpstan-ignore-next-line
         */
        return bcdiv($amount, self::getSubunitMultiplier(), static::classSubunits());
    }

    /**
     * @psalm-param  numeric-string $amount
     *
     * @psalm-return numeric-string
     */
    private static function toSubunits(string $amount): string
    {
        return bcmul($amount, self::getSubunitMultiplier(), 0);
    }

    private function createFromLibMoney(LibMoney $money): self
    {
        return self::create(
            self::fromSubunits($money->getAmount()),
            Currency::create($money->getCurrency()->getCode()),
        );
    }

    private function getLibMoney(): LibMoney
    {
        /** @psalm-suppress TooManyArguments */
        return new LibMoney($this->amount, new LibCurrency($this->currency));
    }

    /** @psalm-return numeric-string */
    private static function getSubunitMultiplier(): string
    {
        /** @psalm-var numeric-string $multiplier */
        $multiplier = static::classSubunits() > 0 ?
            str_pad('1', static::classSubunits() + 1, '0', STR_PAD_RIGHT) :
            '1';

        return $multiplier;
    }

    private static function assertSameSubUnit(self $money, string $methodName): void
    {
        if ($money::classSubunits() !== static::classSubunits()) {
            throw CannotWorkWithMoney::becauseMoneyHasDifferentSubunit(
                $methodName,
                static::class,
                $money::class,
                static::classSubunits(),
                $money::classSubunits(),
            );
        }
    }

    private function getAmountFormatRegexp(int $subunits): string
    {
        return '/^-?\d+' . ($subunits > 0 ? '\.\d{' . $subunits . '}' : '') . '$/';
    }
}
