<?php

declare(strict_types=1);

use Crm\FamilyModule\Models\FamilyRequests;
use Phinx\Migration\AbstractMigration;

final class FixReferenceToSubscriptionTypeInFamilyCustomizablePaymentItems extends AbstractMigration
{
    public function up(): void
    {

        $q = <<<SQL
select sti.subscription_type_id as master_subscription_type_id, payment_items.subscription_type_id as slave_subscription_type_id, payment_items.id 
from payment_items
    join payments on payment_items.payment_id = payments.id 
    join family_subscription_types fst1 on payments.subscription_type_id = fst1.master_subscription_type_id
    join subscription_type_items sti on payment_items.subscription_type_item_id = sti.id
    join family_subscription_types fst2 on sti.subscription_type_id = fst2.master_subscription_type_id
where sti.subscription_type_id != payment_items.subscription_type_id
limit 1
SQL;

        $rows = $this->query($q);
        foreach ($rows as $row) {
            $masterSubscriptionTypeId = $row['master_subscription_type_id'];
            $slaveSubscriptionTypeId = $row['slave_subscription_type_id'];
            $paymentItemId = $row['id'];
            $this->output->writeln("Updating payment_item [{$paymentItemId}] to subscription_type_id [{$masterSubscriptionTypeId}] (from [{$slaveSubscriptionTypeId}])");
            $this->execute("UPDATE payment_items SET subscription_type_id = {$masterSubscriptionTypeId} WHERE payment_items.id = {$paymentItemId}");

            $metaKey = FamilyRequests::PAYMENT_ITEM_META_SLAVE_SUBSCRIPTION_TYPE_ID;

            $insertMetaQuery = <<<SQL
INSERT INTO payment_item_meta (payment_item_id, `key`, `value`, created_at, updated_at)
VALUES ({$paymentItemId}, '{$metaKey}', {$slaveSubscriptionTypeId}, NOW(), NOW())
SQL;
            $this->execute($insertMetaQuery);
        }
    }

    public function down(): void
    {
        $this->output->writeln('This is data migration. Down migration is not available.');
    }
}
