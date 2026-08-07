<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProfileEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'required' => false,
                'label' => 'profile.edit.first_name',
            ])
            ->add('lastName', TextType::class, [
                'required' => false,
                'label' => 'profile.edit.last_name',
            ])
            ->add('phone', TextType::class, [
                'required' => false,
                'label' => 'profile.edit.phone',
            ])
            // Printed on every document that goes to the debtor and to the court, and
            // until now writable only from a console command, so in production the
            // bar number never appeared on any of them.
            ->add('barNumber', TextType::class, [
                'required' => false,
                'label' => 'profile.edit.bar_number',
                'help' => 'profile.edit.bar_number_help',
            ])
            // Fiscal data — required to issue invoices (see User::hasCompleteFiscalData()).
            // Clients are law practices/companies, always identified by CIF.
            ->add('companyName', TextType::class, [
                'required' => false,
                'label' => 'profile.edit.company_name',
                'help' => 'profile.edit.company_name_help',
            ])
            ->add('cui', TextType::class, [
                'required' => false,
                'label' => 'profile.edit.cui',
                'help' => 'profile.edit.cui_help',
            ])
            ->add('street', TextType::class, [
                'required' => false,
                'label' => 'profile.edit.street',
            ])
            ->add('streetNumber', TextType::class, [
                'required' => false,
                'label' => 'profile.edit.street_number',
            ])
            ->add('city', TextType::class, [
                'required' => false,
                'label' => 'profile.edit.city',
            ])
            ->add('county', TextType::class, [
                'required' => false,
                'label' => 'profile.edit.county',
            ])
            ->add('postalCode', TextType::class, [
                'required' => false,
                'label' => 'profile.edit.postal_code',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
