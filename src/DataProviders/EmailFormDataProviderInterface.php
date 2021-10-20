<?php

namespace Crm\FamilyModule\DataProviders;

use Crm\ApplicationModule\DataProvider\DataProviderInterface;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;

interface EmailFormDataProviderInterface extends DataProviderInterface
{
    public function submit(ActiveRow $user, Form $form): Form;
}
