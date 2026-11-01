<?php

namespace App\Form;

use App\Entity\User;
use App\Service\RegistrationBotGuard;
use App\Validator\DeliverableEmail;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class RegistrationFormType extends AbstractType
{
    public function __construct(
        private readonly RegistrationBotGuard $botGuard,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username')
            ->add('email', EmailType::class, [
                'constraints' => [
                    new NotBlank(message: 'Please enter an e-mail address'),
                    new Email(message: 'Please enter a valid e-mail address'),
                    // Reject domains that cannot receive mail before the verification mail bounces.
                    new DeliverableEmail(),
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'constraints' => [
                    new IsTrue(message: 'You should agree to our terms.'),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                // instead of being set onto the object directly,
                // this is read and encoded in the controller
                'mapped' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new NotBlank(message: 'Please enter a password'),
                    new Length(
                        min: 8,
                        minMessage: 'Your password should be at least {{ limit }} characters',
                        max: 4096,
                    ),
                    // Reject passwords known from public breach corpora (HIBP
                    // k-anonymity range API). Disabled in the test env via
                    // framework.validation.not_compromised_password.
                    new NotCompromisedPassword(message: 'This password has appeared in a data breach; please choose a different one.'),
                ],
            ])
            // Bot traps (see RegistrationBotGuard): the honeypot is checked after
            // validation so a bot gets the normal success response; the stamp is
            // validated here so a too-fast human can simply resubmit.
            ->add(RegistrationBotGuard::HONEYPOT_FIELD, TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Website',
                'translation_domain' => false,
                'attr' => ['tabindex' => '-1', 'autocomplete' => 'off'],
            ])
            ->add(RegistrationBotGuard::STAMP_FIELD, HiddenType::class, [
                'mapped' => false,
                'constraints' => [
                    new Callback(function (mixed $stamp, ExecutionContextInterface $context): void {
                        $error = $this->botGuard->stampError($stamp);
                        if ($error !== null) {
                            $context->buildViolation($error)->addViolation();
                        }
                    }),
                ],
            ])
        ;
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        // Every render (first view or re-render after errors) gets a fresh stamp,
        // so an expired form recovers by simply submitting it again.
        $view[RegistrationBotGuard::STAMP_FIELD]->vars['value'] = $this->botGuard->issueStamp();
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
