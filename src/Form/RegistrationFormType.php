<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', null, [
                'label' => 'form.registration.email',
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'label' => 'form.registration.agree_terms',
                'constraints' => [
                    new IsTrue(
                        message: 'form.registration.agree_terms_required',
                    ),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'form.registration.passwords_mismatch',
                'first_options' => [
                    'label' => 'form.registration.password',
                    'attr' => ['autocomplete' => 'new-password'],
                    'constraints' => [
                        new NotBlank(
                            message: 'form.registration.password_required',
                        ),
                        new Length(
                            min: 6,
                            max: 4096,
                            minMessage: 'form.registration.password_min_length',
                        ),
                    ],
                ],
                'second_options' => [
                    'label' => 'form.registration.password_confirm',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
