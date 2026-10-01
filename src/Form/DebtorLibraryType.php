<?php

declare(strict_types=1);

namespace App\Form;

use App\DTO\Library\DebtorLibraryData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Debtor library form (create or edit a company outside the wizard). Legal
 * persons only, so there is no person type to choose.
 */
final class DebtorLibraryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'wizard.step2.field.name'])
            ->add('cui', TextType::class, ['label' => 'wizard.step2.field.cui'])
            ->add('onrcNumber', TextType::class, ['label' => 'wizard.step2.field.onrc_number'])
            ->add('administrator', TextType::class, ['label' => 'wizard.step2.field.administrator', 'required' => false])
            ->add('address', TextareaType::class, ['label' => 'wizard.step2.field.address'])
            ->add('addressCounty', TextType::class, ['label' => 'wizard.step2.field.address_county', 'required' => false])
            ->add('addressLocality', TextType::class, ['label' => 'wizard.step2.field.address_locality', 'required' => false])
            ->add('iban', TextType::class, ['label' => 'wizard.step2.field.iban', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'wizard.step2.field.email', 'required' => false])
            ->add('phone', TextType::class, ['label' => 'wizard.step2.field.phone', 'required' => false])
        ;

        // Same leniency as the wizard: an IBAN with spaces or a lowercase CUI
        // is accepted and normalised before the strict constraints run.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }
            foreach (['iban', 'cui'] as $field) {
                if (isset($data[$field]) && is_string($data[$field])) {
                    $data[$field] = strtoupper(preg_replace('/\s+/', '', $data[$field]) ?? '');
                }
            }
            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DebtorLibraryData::class,
            'empty_data' => fn () => new DebtorLibraryData(),
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'debtor_library',
        ]);
    }
}
