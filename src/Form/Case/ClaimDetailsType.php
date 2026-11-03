<?php

declare(strict_types=1);

namespace App\Form\Case;

use App\DTO\Case\ClaimDetailsInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** The claim details the lawyer rewrites from the case page; the sums are not among them. */
class ClaimDetailsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('claimDescription', TextareaType::class, ['required' => false])
            ->add('contractReference', TextType::class, ['required' => false])
            ->add('penaltyClauseArticle', TextType::class, ['required' => false])
            ->add('penaltyClauseText', TextareaType::class, ['required' => false])
            ->add('paymentNoticeNumber', TextType::class, ['required' => false]);
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
