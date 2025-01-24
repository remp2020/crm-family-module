<?php

declare(strict_types=1);

namespace Crm\FamilyModule\Models\ConfigurableFamilySubscription;

use Nette\Database\Table\ActiveRow;

final class PaymentItemConfig
{
    /**
     * @param ActiveRow  $subscriptionTypeItem database reference
     * @param int $count number of purchased items (=number of Family requests),
     * @param float|null $price overrides default price
     * @param float|null $vat overrides default VAT
     * @param bool $noVat removes VAT from prices and resets VAT to 0, by default 'false'
     * @param ?string $name
     * @param array $meta
     */
    public function __construct(
        public readonly ActiveRow $subscriptionTypeItem,
        public readonly int $count,
        public readonly ?float $price = null,
        public readonly ?float $vat = null,
        public readonly bool $noVat = false,
        public readonly ?string $name = null,
        public readonly array $meta = [],
    ) {
    }
}
