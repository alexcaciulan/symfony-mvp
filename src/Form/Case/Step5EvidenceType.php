<?php

namespace App\Form\Case;

use App\DTO\Case\Step5EvidenceData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class Step5EvidenceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('evidenceDescription', TextareaType::class, [
                'label' => 'wizard.step5.evidence_description',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('hasWitnesses', CheckboxType::class, [
                'label' => 'wizard.step5.has_witnesses',
                'required' => false,
            ])
            ->add('witnesses', CollectionType::class, [
                'entry_type' => WitnessEntryType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
                'prototype' => true,
                'prototype_name' => '__witness__',
            ])
            ->add('requestOralDebate', CheckboxType::class, [
                'label' => 'wizard.step5.request_oral_debate',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Step5EvidenceData::class,
        ]);
    }
}
