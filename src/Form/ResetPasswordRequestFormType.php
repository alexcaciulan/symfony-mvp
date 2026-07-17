<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Forgot-password form. Being a FormType gives it automatic CSRF protection, closing the
 * cross-site forced reset-email dispatch.
 */
final class ResetPasswordRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'reset_password.request.email_label',
            'attr' => ['autocomplete' => 'email'],
            'constraints' => [
                new NotBlank(message: 'reset_password.request.email_required'),
                new Email(),
            ],
        ]);
    }
}
