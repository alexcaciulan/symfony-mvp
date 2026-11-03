<?php

declare(strict_types=1);

namespace App\Form\Case;

use App\DTO\Case\ClaimPositionInput;
use App\DTO\Wizard\Step3ClaimData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One invoice added to the claim from the case page. Rendered by hand in the
 * dialog (the names below are what the inputs post), bound and validated here.
 */
class ClaimPositionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('documentNumber', TextType::class, ['required' => false, 'empty_data' => ''])
            ->add('documentDate', DateType::class, ['required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('dueDate', DateType::class, ['required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('amount', NumberType::class, ['required' => false, 'scale' => 2, 'html5' => true])
            ->add('currency', ChoiceType::class, [
                'choices' => array_combine(Step3ClaimData::SUPPORTED_CURRENCIES, Step3ClaimData::SUPPORTED_CURRENCIES),
                'empty_data' => 'RON',
            ])
            ->add('causeReference', TextType::class, ['required' => false])
            ->add('description', TextType::class, ['required' => false])
            ->add('sourceDocumentId', HiddenType::class, ['required' => false])
            ->add('sameCauseConfirmed', CheckboxType::class, ['required' => false])
            ->add('clauseAppliesConfirmed', CheckboxType::class, ['required' => false]);

        // Posted as the id of the debtor link; a single-debtor case posts nothing.
        $builder->add('debtorId', HiddenType::class, ['required' => false]);
        $builder->get('debtorId')->addModelTransformer(new \Symfony\Component\Form\CallbackTransformer(
            static fn (?int $id): string => $id === null ? '' : (string) $id,
            static fn (?string $raw): ?int => $raw === null || $raw === '' || !ctype_digit($raw) ? null : (int) $raw,
        ));
        $builder->get('sourceDocumentId')->addModelTransformer(new \Symfony\Component\Form\CallbackTransformer(
            static fn (?int $id): string => $id === null ? '' : (string) $id,
            static fn (?string $raw): ?int => $raw === null || $raw === '' || !ctype_digit($raw) ? null : (int) $raw,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ClaimPositionInput::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'claim_position',
        ]);
    }
}
