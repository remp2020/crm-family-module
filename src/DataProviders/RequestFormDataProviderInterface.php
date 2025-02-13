<?php

namespace Crm\FamilyModule\DataProviders;

use Crm\ApplicationModule\Models\DataProvider\DataProviderInterface;
use Crm\ApplicationModule\UI\Form;
use Nette\Database\Table\ActiveRow;

interface RequestFormDataProviderInterface extends DataProviderInterface
{
    /**
     * @param array $params {
     *   @type Form $form
     *   @type ActiveRow $user
     * }
     * @return Form
     */
    public function provide(array $params): Form;

    /**
     * This method is used for providing default subscription type item prices for `RequestFormFactory`.
     * (e.g.: u can use it to provide default calculated discount prices)
     *
     * @param ActiveRow $subscriptionTypeItem
     * @return array [
     *     10.00 => '10,00 €',
     *     7.50 => '7,50 € (25% discount)'
     * ]
     */
    public function provideSubscriptionTypeItemPriceOptions(ActiveRow $subscriptionTypeItem): array;
}
