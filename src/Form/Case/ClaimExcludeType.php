<?php

declare(strict_types=1);

namespace App\Form\Case;

use App\Enum\ClaimExclusionReason;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Why a position leaves the claim: a reason from the list, with an optional note. */
class ClaimExcludeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reason', EnumType::class, [
                'class' => ClaimExclusionReason::class,
                'constraints' => [new Assert\NotNull(message: 'case.claim.edit.error.exclude_reason_required')],
            ])
            ->add('note', TextType::class, [
                'required' => false,
                'constraints' => [new Assert\Length(max: 255, maxMessage: 'case.claim.edit.error.exclude_note_too_long')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'claim_exclude',
        ]);
    }
}
