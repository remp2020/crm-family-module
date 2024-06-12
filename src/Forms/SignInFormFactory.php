<?php

namespace Crm\FamilyModule\Forms;

use Nette\Application\UI\Form;
use Nette\Localization\Translator;
use Tomaj\Form\Renderer\BootstrapRenderer;

class SignInFormFactory
{
    public function __construct(
        private readonly Translator $translator
    ) {
    }

    public function create(string $email = null): Form
    {
        $form = new Form();
        $form->setTranslator($this->translator);
        $form->setRenderer(new BootstrapRenderer());
        $form->addText('username', 'family.frontend.new.form.email')
            ->setHtmlType('email')
            ->setHtmlAttribute('autofocus')
            ->setRequired('family.frontend.new.form.email_required')
            ->setHtmlAttribute('placeholder', 'family.frontend.new.form.email_placeholder');

        $form->addPassword('password', 'family.frontend.signin.form.password')
            ->setRequired('family.frontend.signin.form.password_required')
            ->setHtmlAttribute('placeholder', 'family.frontend.signin.form.password_placeholder');

        $form->addSubmit('send', 'family.frontend.signin.form.submit');

        if ($email) {
            $form->setDefaults(['username' => $email]);
        }

        return $form;
    }
}
