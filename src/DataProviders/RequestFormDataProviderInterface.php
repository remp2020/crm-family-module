<?php

namespace Crm\FamilyModule\DataProviders;

use Crm\ApplicationModule\Models\DataProvider\DataProviderInterface;
use Nette\Application\UI\Form;

interface RequestFormDataProviderInterface extends DataProviderInterface
{
    /***
     * @param array $params {
     *   @type Form $form
     *   @type ActiveRow $user
     * }
     * @return Form
     */
    public function provide(array $params): Form;
}
