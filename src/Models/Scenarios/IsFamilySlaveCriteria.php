<?php

namespace Crm\FamilyModule\Models\Scenarios;

use Crm\ApplicationModule\Criteria\Params\BooleanParam;
use Crm\ApplicationModule\Criteria\ScenariosCriteriaInterface;
use Nette\Database\Table\Selection;

class IsFamilySlaveCriteria implements ScenariosCriteriaInterface
{
    public function params(): array
    {
        return [
            new BooleanParam('is_family_slave', $this->label()),
        ];
    }

    public function addCondition(Selection $selection, $key, $values)
    {
        if ($values->selection) {
            $selection->where('subscription_type:family_subscription_types(slave_subscription_type_id).id IS NOT NULL');
        } else {
            $selection->where('subscription_type:family_subscription_types(slave_subscription_type_id).id IS NULL');
        }
    }

    public function label(): string
    {
        return 'Is family (slave)';
    }
}
