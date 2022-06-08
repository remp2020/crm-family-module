<?php

namespace Crm\FamilyModule\Forms;

use Crm\ApplicationModule\ActiveRow;
use Crm\FamilyModule\Repositories\FamilySubscriptionTypesRepository;
use Crm\InvoicesModule\Gateways\ProformaInvoice;
use Crm\PaymentsModule\Gateways\BankTransfer;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionTypeItemMetaRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Nette\Application\UI\Form;
use Nette\Forms\Controls\TextInput;
use Nette\Localization\Translator;
use Nette\Utils\DateTime;

class RequestFormFactory
{
    private const MANUAL_SUBSCRIPTION_START = 'start_at';
    private const MANUAL_SUBSCRIPTION_START_END = 'start_end_at';

    private FamilySubscriptionTypesRepository $familySubscriptionTypesRepository;

    private SubscriptionTypesRepository $subscriptionTypesRepository;

    private SubscriptionTypeItemMetaRepository $subscriptionTypeItemMetaRepository;

    private PaymentGatewaysRepository $paymentGatewaysRepository;

    private PaymentsRepository $paymentsRepository;

    private Translator $translator;

    private $user;

    public $onSave;

    public function __construct(
        FamilySubscriptionTypesRepository $familySubscriptionTypesRepository,
        SubscriptionTypesRepository $subscriptionTypesRepository,
        SubscriptionTypeItemMetaRepository $subscriptionTypeItemMetaRepository,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        PaymentsRepository $paymentsRepository,
        Translator $translator
    ) {
        $this->familySubscriptionTypesRepository = $familySubscriptionTypesRepository;
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
        $this->subscriptionTypeItemMetaRepository = $subscriptionTypeItemMetaRepository;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->translator = $translator;
    }

    public function create(ActiveRow $user): Form
    {
        $this->user = $user;

        $form = new Form();
        $form->addProtection();
        $form->setTranslator($this->translator);
        $form->onSuccess[] = [$this, 'formSucceeded'];
        $form->onValidate[] = [$this, 'formValidate'];

        $customSubscriptionTypes = $this->familySubscriptionTypesRepository->getCustomizableSubscriptionTypes();
        $masterSubscriptionTypes = [];
        foreach ($customSubscriptionTypes as $customSubscriptionType) {
            $masterSubscriptionType = $customSubscriptionType->ref('subscription_types', 'master_subscription_type_id');
            $masterSubscriptionTypes[$masterSubscriptionType->id] = $masterSubscriptionType->name;
        }

        $subscriptionTypeId = $form->addSelect('subscription_type_id', 'family.admin.form.request.subscription_type.label', $masterSubscriptionTypes);
        foreach ($masterSubscriptionTypes as $id => $name) {
            $subscriptionTypeId->addCondition(Form::EQUAL, $id)->toggle("container-{$id}");
        }

        $paymentGateways = $this->paymentGatewaysRepository->all()
            ->where(['code ?' => [BankTransfer::GATEWAY_CODE, ProformaInvoice::GATEWAY_CODE]])
            ->fetchPairs('id', 'name');
        $form->addSelect('payment_gateway_id', 'family.admin.form.request.payment_gateway.label', $paymentGateways)
            ->setRequired('family.admin.form.request.payment_gateway.required');

        $manualSubscription = $form->addSelect('manual_subscription', 'payments.form.payment.manual_subscription.label', [
            self::MANUAL_SUBSCRIPTION_START => $this->translator->translate('payments.form.payment.manual_subscription.start'),
            self::MANUAL_SUBSCRIPTION_START_END => $this->translator->translate('payments.form.payment.manual_subscription.start_end'),
        ])->setPrompt('payments.form.payment.manual_subscription.prompt');

        $manualSubscription->addCondition(Form::EQUAL, self::MANUAL_SUBSCRIPTION_START)->toggle('subscription-start-at');
        $manualSubscription->addCondition(Form::EQUAL, self::MANUAL_SUBSCRIPTION_START_END)->toggle('subscription-start-at');
        $manualSubscription->addCondition(Form::EQUAL, self::MANUAL_SUBSCRIPTION_START_END)->toggle('subscription-end-at');

        $subscriptionStartAt = $form->addText('subscription_start_at', 'family.admin.form.request.subscription_start_at.label')
            ->setHtmlAttribute('placeholder', 'family.admin.form.request.subscription_start_at.placeholder')
            ->setHtmlAttribute('class', 'flatpickr')
            ->setHtmlAttribute('flatpickr_datetime', "1")
            ->setRequired(false)
            ->addRule(function (TextInput $field, $user) {
                if (DateTime::from($field->getValue()) < new DateTime('today midnight')) {
                    return false;
                }
                return true;
            }, 'family.admin.form.request.subscription_start_at.not_past', $user);

        $subscriptionStartAt
            ->addConditionOn($manualSubscription, Form::EQUAL, self::MANUAL_SUBSCRIPTION_START)
            ->setRequired('family.admin.form.request.subscription_start_at.required');
        $subscriptionStartAt
            ->addConditionOn($manualSubscription, Form::EQUAL, self::MANUAL_SUBSCRIPTION_START_END)
            ->setRequired('family.admin.form.request.subscription_start_at.required');

        $subscriptionEndAt = $form->addText('subscription_end_at', 'family.admin.form.request.subscription_end_at.label')
            ->setHtmlAttribute('placeholder', 'family.admin.form.request.subscription_end_at.placeholder')
            ->setHtmlAttribute('class', 'flatpickr')
            ->setHtmlAttribute('flatpickr_datetime', "1")
            ->setRequired(false)
            ->addRule(function (TextInput $field, $user) {
                if (DateTime::from($field->getValue()) < new DateTime()) {
                    return false;
                }
                return true;
            }, 'family.admin.form.request.subscription_end_at.not_past', $user);

        $subscriptionEndAt
            ->addConditionOn($manualSubscription, Form::EQUAL, self::MANUAL_SUBSCRIPTION_START_END)
            ->setRequired('family.admin.form.request.subscription_end_at.required');

        $formItems = [];
        foreach ($customSubscriptionTypes as $id => $customSubscriptionType) {
            $masterCustomSubscriptionType = $customSubscriptionType->ref('subscription_types', 'master_subscription_type_id');
            $container = $form->addContainer($masterCustomSubscriptionType->id);

            foreach ($masterCustomSubscriptionType->related('subscription_type_items') as $item) {
                $formItems[$id][$item->id] = $container->addContainer($item->id);

                $formItems[$id][$item->id]->addInteger('count', 'family.admin.form.request.count.label')
                    ->addRule(Form::MIN, 'family.admin.form.request.count.number', 0)
                    ->setHtmlAttribute('placeholder', 'family.admin.form.request.count.label')
                    ->setDefaultValue(0);

                $formItems[$id][$item->id]->addText('price', 'family.admin.form.request.price.label')
                    ->setHtmlAttribute('placeholder', 'family.admin.form.request.price.label')
                    ->addRule(Form::FLOAT, 'family.admin.form.request.price.number')
                    ->addRule(Form::MIN, 'family.admin.form.request.price.number', 0)
                    ->addConditionOn($formItems[$id][$item->id]['count'], Form::NOT_EQUAL, 0)
                    ->setRequired('family.admin.form.request.price.required');
            }
        }

        $form->addSubmit('send', 'family.admin.form.request.send')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-save"></i> ' . $this->translator->translate('family.admin.form.request.send'));

        return $form;
    }

    public function formSucceeded($form, $values)
    {
        $subscriptionStartAt = null;
        $subscriptionEndAt = null;

        if (!isset($values['subscription_start_at']) || $values['subscription_start_at'] == '') {
            $values['subscription_start_at'] = null;
        }
        if (!isset($values['subscription_end_at']) || $values['subscription_end_at'] == '') {
            $values['subscription_end_at'] = null;
        }

        if (isset($values['manual_subscription'])) {
            if ($values['manual_subscription'] === self::MANUAL_SUBSCRIPTION_START) {
                if ($values['subscription_start_at'] === null) {
                    throw new \Exception("manual subscription start attempted without providing start date");
                }
                $subscriptionStartAt = DateTime::from($values['subscription_start_at']);
            } elseif ($values['manual_subscription'] === self::MANUAL_SUBSCRIPTION_START_END) {
                if ($values['subscription_start_at'] === null) {
                    throw new \Exception("manual subscription start attempted without providing start date");
                }
                $subscriptionStartAt = DateTime::from($values['subscription_start_at']);
                if ($values['subscription_end_at'] === null) {
                    throw new \Exception("manual subscription end attempted without providing end date");
                }
                $subscriptionEndAt = DateTime::from($values['subscription_end_at']);
            }
        }

        $subscriptionType = $this->subscriptionTypesRepository->find($values['subscription_type_id']);
        if (!$subscriptionType) {
            throw new \Exception("No subscription type found for id: {$values['subscription_type_id']}");
        }

        $paymentGateway = $this->paymentGatewaysRepository->find($values['payment_gateway_id']);
        if (!$paymentGateway) {
            throw new \Exception("No payment gateway found for id: {$values['payment_gateway_id']}");
        }

        $user = $this->user;
        $paymentItemContainer = new PaymentItemContainer();

        foreach ($subscriptionType->related('subscription_type_items') as $id => $subscriptionTypeItem) {
            if (isset($values[$subscriptionType->id][$id]['count']) && $values[$subscriptionType->id][$id]['count'] > 0) {
                $subscriptionTypeItemMeta = $this->subscriptionTypeItemMetaRepository
                    ->findBySubscriptionTypeItemAndKey($subscriptionTypeItem, 'family_slave_subscription_type_id')
                    ->fetch();
                if (!isset($subscriptionTypeItemMeta->value)) {
                    throw new \Exception("No family slave subscription types associated to subscription type item: {$subscriptionTypeItem->id}");
                }

                $slaveSubscriptionType = $this->subscriptionTypesRepository->find($subscriptionTypeItemMeta->value);
                if (!$slaveSubscriptionType) {
                    throw new \Exception("No slave subscription type found with ID: {$subscriptionTypeItemMeta->value}");
                }

                $paymentItemContainer->addItem(new SubscriptionTypePaymentItem(
                    $slaveSubscriptionType->id,
                    $subscriptionTypeItem->name,
                    $values[$subscriptionType->id][$id]['price'],
                    $subscriptionTypeItem->vat,
                    $values[$subscriptionType->id][$id]['count'],
                    ['subscription_type_item_id' => $subscriptionTypeItem->id]
                ));
            }
        }

        if (count($paymentItemContainer->items()) === 0) {
            throw new \Exception("No payment item has been added for subscription type: {$subscriptionType->id}");
        }

        $this->paymentsRepository->add(
            $subscriptionType,
            $paymentGateway,
            $user,
            $paymentItemContainer,
            null,
            null,
            $subscriptionStartAt,
            $subscriptionEndAt
        );

        $this->onSave->__invoke($form, $user);
    }

    public function formValidate(Form $form, $values)
    {
        $subscriptionType = $this->subscriptionTypesRepository->find($values['subscription_type_id']);
        if (!$subscriptionType) {
            throw new \Exception("No subscription type found for id: {$values['subscription_type_id']}");
        }

        $totalCount = 0;
        foreach ($subscriptionType->related('subscription_type_items') as $id => $item) {
            $totalCount += $values[$subscriptionType->id][$id]['count'];
        }

        if ($totalCount === 0) {
            $form->addError('family.admin.form.request.count.total');
        }
    }
}
