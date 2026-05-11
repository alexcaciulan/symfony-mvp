<?php

declare(strict_types=1);

namespace App\Form\Wizard;

use App\DTO\Wizard\Step2DebtorsData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Pas 3.1 wizard step 2 form — collection wrapper.
 *
 * `by_reference: false` is critical: the DTO exposes `$debtors` as a plain
 * `array` property (not an `ArrayCollection`); without `by_reference: false`
 * Symfony Forms would mutate the array in place via the getter and skip the
 * setter on add/remove, silently breaking the LiveComponent flow added in
 * Pas 3.3. Count/Valid constraints live on the DTO, not on the form.
 *
 * `prototype: true` lets Pas 3.3's `Step2DebtorsLiveComponent` render new
 * entries client-side without extra round-trips.
 */
final class Step2DebtorsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('debtors', CollectionType::class, [
            'entry_type' => Step2DebtorEntryType::class,
            'entry_options' => ['label' => false],
            'allow_add' => true,
            'allow_delete' => true,
            'prototype' => true,
            'prototype_name' => '__name__',
            'by_reference' => false,
            'label' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Step2DebtorsData::class,
            'empty_data' => fn () => new Step2DebtorsData(),
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'wizard_step2_debtors',
        ]);
    }
}
