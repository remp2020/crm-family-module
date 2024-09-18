<?php

namespace Crm\FamilyModule\DataProviders;

use Crm\ApplicationModule\Models\DataProvider\DataProviderInterface;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;

interface EmailFormDataProviderInterface extends DataProviderInterface
{
    public function provide(array $params);

    public function submit(ActiveRow $user, Form $form): Form;
}
