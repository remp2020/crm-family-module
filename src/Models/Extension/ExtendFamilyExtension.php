<?php

namespace Crm\FamilyModule\Models\Extension;

use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\SubscriptionsModule\Extension\Extension;
use Crm\SubscriptionsModule\Extension\ExtensionInterface;
use Nette\Database\Context;
use Nette\Database\Table\IRow;
use Nette\Utils\DateTime;

class ExtendFamilyExtension implements ExtensionInterface
{
    public const METHOD_CODE = 'extend_family';
    public const METHOD_NAME = 'Extend family';

    private $database;

    private $familySubscriptionTypesRepository;

    public function __construct(
        Context $database,
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository
    ) {
        $this->database = $database;
        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
    }

    public function getStartTime(IRow $user, IRow $subscriptionType)
    {
        // load IDs of all family subscription types
        $familySubscriptionTypeIds = array_merge(
            $this->familySubscriptionTypesRepository->masterSubscriptionTypes(),
            $this->familySubscriptionTypesRepository->slaveSubscriptionTypes()
        );

        // load user family subscriptions
        // Note: This is plain DB query; we cannot use SubscriptionsRepository because of circular reference:
        //       `Nette\InvalidStateException: Circular reference detected for services: subscriptionsRepository, extensionMethodFactory.`
        $sql = <<<SQL
            SELECT `end_time`, `subscription_type_id`
            FROM `subscriptions`
            WHERE `user_id` = ?
              AND `subscription_type_id` IN (?)
              AND `end_time` > ?
          ORDER BY `end_time` DESC
          LIMIT 1
SQL;

        $userFamilySubscriptions = $this->database->getConnection()->query($sql, $user->id, $familySubscriptionTypeIds, new DateTime())->fetchAll();

        // if user doesn't have family subscription, start now
        if (count($userFamilySubscriptions) === 0) {
            return new Extension(new DateTime());
        }

        return new Extension(reset($userFamilySubscriptions)->end_time, true);
    }
}
