<?php

declare(strict_types=1);

namespace App\Form\Case;

use App\Enum\FilingChannel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form for the `depune_cerere` transition: the lawyer declares when and how the
 * petition was filed. data_class null, the controller maps the fields onto
 * LegalCase itself.
 */
final class ConfirmFilingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('filedAt', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'label' => 'case_overview.filing.field_filed_at',
                'constraints' => [
                    new Assert\NotNull(message: 'case_overview.filing.error_date_required'),
                    new Assert\LessThanOrEqual(
                        value: 'today',
                        message: 'case_overview.filing.error_date_future',
                    ),
                ],
            ])
            ->add('filingChannel', EnumType::class, [
                'class' => FilingChannel::class,
                'choice_label' => static fn (FilingChannel $channel): string => $channel->label(),
                'placeholder' => 'case_overview.filing.channel_placeholder',
                'label' => 'case_overview.filing.field_channel',
                'constraints' => [
                    new Assert\NotNull(message: 'case_overview.filing.error_channel_required'),
                ],
            ])
            ->add('filingReference', TextType::class, [
                'required' => false,
                'label' => 'case_overview.filing.field_reference',
                'constraints' => [
                    new Assert\Length(
                        max: 100,
                        maxMessage: 'case_overview.filing.error_reference_too_long',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'confirm_filing',
        ]);
    }
}
