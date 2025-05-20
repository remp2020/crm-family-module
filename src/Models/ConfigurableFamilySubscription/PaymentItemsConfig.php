<?php

declare(strict_types=1);

namespace Crm\FamilyModule\Models\ConfigurableFamilySubscription;

final class PaymentItemsConfig
{
    private array $itemsConfig = [];

    public function addItem(
        PaymentItemConfig $config,
    ): void {
        $this->itemsConfig[] = $config;
    }

    /**
     * @return PaymentItemConfig[]
     */
    public function getItemsConfig(): array
    {
        return $this->itemsConfig;
    }
}
