<?php

namespace App\Form\Case;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

class Step6ConfirmationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('agreeDataCorrect', CheckboxType::class, [
                'label' => 'wizard.step6.agree_data_correct',
                'mapped' => false,
                'constraints' => [
                    new IsTrue(message: 'wizard.step6.agree_data_correct_required'),
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'label' => 'wizard.step6.agree_terms',
                'mapped' => false,
                'constraints' => [
                    new IsTrue(message: 'wizard.step6.agree_terms_required'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
