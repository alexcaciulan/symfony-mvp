<?php

declare(strict_types=1);

namespace App\Form\Case;

use App\DTO\Case\ClaimDetailsInput;
use App\Enum\ContractualAccessoryLabel;
use App\Enum\PenaltyType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The claim details the lawyer rewrites from the case page; the positions are
 * not among them. The accessory fields take the same types as the wizard's
 * claim step, so a value is read the same way in both places.
 */
class ClaimDetailsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('claimDescription', TextareaType::class, ['required' => false])
            ->add('contractReference', TextType::class, ['required' => false])
            ->add('penaltyClauseArticle', TextType::class, ['required' => false])
            ->add('penaltyClauseText', TextareaType::class, ['required' => false])
            ->add('paymentNoticeNumber', TextType::class, ['required' => false])
            ->add('penaltyType', EnumType::class, [
                'class' => PenaltyType::class,
                'choice_label' => fn (PenaltyType $t) => $t->label(),
                'required' => true,
            ])
            ->add('contractualPenaltyRate', NumberType::class, ['scale' => 3, 'html5' => true, 'required' => false])
            ->add('contractualPenaltyCapPercent', NumberType::class, ['scale' => 2, 'html5' => true, 'required' => false])
            ->add('contractualAccessoryLabel', EnumType::class, [
                'class' => ContractualAccessoryLabel::class,
                'choice_label' => fn (ContractualAccessoryLabel $l) => $l->label(),
                'placeholder' => false,
                'required' => false,
            ])
            ->add('accessoryCutoffDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('contractNumber', TextType::class, ['required' => false])
            ->add('contractDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('contractObject', TextType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ClaimDetailsInput::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'claim_details',
        ]);
    }
}
