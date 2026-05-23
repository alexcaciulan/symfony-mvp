<?php

declare(strict_types=1);

namespace App\Form\Case;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Pas 6.1 — form pentru activarea monitorizării portal.just.ro: numărul de
 * dosar al instanței în format ECRIS (`numar/cod/an`, ex. `4521/302/2026`).
 * Form neasociat unei entități (data_class null) — controllerul mapează manual
 * `courtCaseNumber` pe LegalCase.
 */
final class PortalActivateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('courtCaseNumber', TextType::class, [
                'label' => 'case_overview.portal.config_title',
                'constraints' => [
                    new Assert\NotBlank(message: 'case_overview.portal.court_case_number_required'),
                    new Assert\Regex(
                        pattern: '#^\d+/\d+/\d{4}$#',
                        message: 'case_overview.portal.court_case_number_invalid_format',
                    ),
                    new Assert\Length(
                        max: 50,
                        maxMessage: 'case_overview.portal.court_case_number_too_long',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'portal_activate',
        ]);
    }
}
