<?php

namespace Crm\FamilyModule\DataProviders;

use Crm\ApplicationModule\Models\DataProvider\DataProviderException;
use Crm\ApplicationModule\UI\Form;
use Crm\InvoicesModule\Models\Vat\VatModeDetector;
use Crm\UsersModule\Repositories\AddressesRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Localization\Translator;

class FamilyRequestVatModeFormDataProvider implements RequestFormDataProviderInterface
{
    public function __construct(
        private readonly VatModeDetector $vatModeDetector,
        private readonly Translator $translator,
        private readonly AddressesRepository $addressesRepository,
    ) {
    }

    public function provide(array $params): Form
    {
        if (!isset($params['form'])) {
            throw new DataProviderException('missing [form] within data provider params');
        }

        if (!isset($params['user'])) {
            throw new DataProviderException('missing [user] within data provider params');
        }

        /** @var Form $form */
        $form = $params['form'];

        $user = $params['user'];
        $vatMode = $this->vatModeDetector->userVatMode($user);

        $autoVatModeMessage = $this->translator->translate('invoices.data_provider.family_request.vat_mode_automatic', [
            'vat_mode' => $this->translator->translate('invoices.data_provider.family_request.vat_mode.' . $vatMode->value),
        ]);

        $form->getComponent('no_vat')
            ->setDisabled()
            ->setOption(
                'description',
                $autoVatModeMessage,
            );

        $invoiceAddressCountry = $this->addressesRepository->address($user, 'invoice')?->country;
        if ($invoiceAddressCountry) {
            $form->getComponent('payment_country_id')
                ->setItems([$invoiceAddressCountry->id => $invoiceAddressCountry->name])
                ->setValue($invoiceAddressCountry->id)
                ->setDisabled();
        }

        return $form;
    }

    public function provideSubscriptionTypeItemPriceOptions(ActiveRow $subscriptionTypeItem): array
    {
        return [];
    }
}
