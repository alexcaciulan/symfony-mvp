<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Sets a new password from a reset link. Being a FormType gives automatic CSRF protection
 * (closing the account-takeover vector) and applies the shared password policy.
 */
final class ResetPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'reset_password.flash.passwords_mismatch',
            'first_options' => [
                'label' => 'reset_password.reset.password_label',
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => PasswordConstraints::forNewPassword(),
            ],
            'second_options' => [
                'label' => 'reset_password.reset.password_confirm_label',
                'attr' => ['autocomplete' => 'new-password'],
            ],
        ]);
    }
}
