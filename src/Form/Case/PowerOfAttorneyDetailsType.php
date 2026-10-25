<?php

declare(strict_types=1);

namespace App\Form\Case;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Completes or corrects the series, number and date of a power of attorney already
 * on the case. Series and number are free text: the platform cannot check them
 * against the bar's register, so it only trims and caps them.
 */
final class PowerOfAttorneyDetailsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        self::addFields($builder, dateRequired: true);
    }

    public static function addFields(FormBuilderInterface $builder, bool $dateRequired): void
    {
        $dateConstraints = [
            new Assert\LessThanOrEqual(value: 'today', message: 'document.power_of_attorney.date_future'),
        ];
        if ($dateRequired) {
            $dateConstraints[] = new Assert\NotNull(message: 'document.power_of_attorney.date_required');
        }

        $builder
            ->add('powerOfAttorneySeries', TextType::class, [
                'required' => false,
                'label' => 'document.power_of_attorney.series_label',
                'constraints' => [
                    new Assert\Length(max: 20, maxMessage: 'document.power_of_attorney.series_too_long'),
                ],
            ])
            ->add('powerOfAttorneyNumber', TextType::class, [
                'required' => false,
                'label' => 'document.power_of_attorney.number_label',
                'constraints' => [
                    new Assert\Length(max: 30, maxMessage: 'document.power_of_attorney.number_too_long'),
                ],
            ])
            ->add('powerOfAttorneyDate', DateType::class, [
                'required' => $dateRequired,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'label' => 'document.power_of_attorney.date_label',
                'invalid_message' => 'document.power_of_attorney.date_invalid',
                'constraints' => $dateConstraints,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // One shared modal serves every power of attorney on the page, like the
        // deadline edit modal, so the token is per form rather than per document.
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'power_of_attorney_details',
        ]);
    }
}
