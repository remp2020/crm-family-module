<?php

namespace Crm\FamilyModule\Models\Scenarios;

use Crm\ApplicationModule\Criteria\ScenarioParams\BooleanParam;
use Crm\ApplicationModule\Criteria\ScenariosCriteriaInterface;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;

class IsFamilyMasterCriteria implements ScenariosCriteriaInterface
{
    public function params(): array
    {
        return [
            new BooleanParam('is_family_master', $this->label()),
        ];
    }

    public function addConditions(Selection $selection, array $paramValues, ActiveRow $criterionItemRow): bool
    {
        $values = $paramValues['is_family_master'];

        if ($values->selection) {
            $selection->where('subscription_type:family_subscription_types(master_subscription_type_id).id IS NOT NULL');
        } else {
            $selection->where('subscription_type:family_subscription_types(master_subscription_type_id).id IS NULL');
        }

        return true;
    }

    public function label(): string
    {
        return 'Is family (master)';
    }
}
