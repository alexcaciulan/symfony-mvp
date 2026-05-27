<?php

declare(strict_types=1);

namespace App\Form\Case;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form for the `inregistreaza_dosar` workflow transition: captures the
 * ECRIS-style court case number (`numar/cod/an`, e.g. `4521/302/2026`)
 * received from the court registry. data_class null — the controller
 * maps `courtCaseNumber` onto LegalCase manually.
 */
final class RegisterCaseNumberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('courtCaseNumber', TextType::class, [
                'label' => 'case_overview.modal.register_field_label',
                'constraints' => [
                    new Assert\NotBlank(message: 'case_overview.transition.court_case_number_required'),
                    new Assert\Regex(
                        pattern: '#^\d+/\d+/\d{4}$#',
                        message: 'case_overview.transition.court_case_number_invalid_format',
                    ),
                    new Assert\Length(
                        max: 50,
                        maxMessage: 'case_overview.transition.court_case_number_too_long',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'register_case_number',
        ]);
    }
}
