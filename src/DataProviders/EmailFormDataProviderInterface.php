<?php

namespace Crm\FamilyModule\DataProviders;

use Crm\ApplicationModule\DataProvider\DataProviderInterface;
use Nette\Application\UI\Form;
use Nette\Database\Table\IRow;

interface EmailFormDataProviderInterface extends DataProviderInterface
{
    public function submit(IRow $User, Form $form): Form;
}
