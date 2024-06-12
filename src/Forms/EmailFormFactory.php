<?php

namespace Crm\FamilyModule\Forms;

use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\FamilyModule\DataProviders\EmailFormDataProviderInterface;
use Nette\Application\UI\Form;
use Nette\Localization\Translator;
use Tomaj\Form\Renderer\BootstrapRenderer;

class EmailFormFactory
{
    public function __construct(
        private readonly Translator $translator,
        private readonly DataProviderManager $dataProviderManager,
    ) {
    }

    public function create(): Form
    {
        $form = new Form();
        $form->setTranslator($this->translator);
        $form->setRenderer(new BootstrapRenderer());

        $form->addText('email', 'family.frontend.new.form.email')
            ->setHtmlAttribute('autofocus')
            ->setRequired('family.frontend.new.form.email_required')
            ->setHtmlAttribute('placeholder', 'family.frontend.new.form.email_placeholder');

        /** @var EmailFormDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders('family.dataprovider.email_form', EmailFormDataProviderInterface::class);
        foreach ($providers as $sorting => $provider) {
            $form = $provider->provide(['form' => $form]);
        }

        $form->addSubmit('submit', 'family.frontend.new.form.submit');
        return $form;
    }
}
