<?php

declare(strict_types=1);

namespace App\Form\Wizard;

use App\Entity\Debtor;
use App\Entity\User;
use App\Repository\DebtorRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

/**
 * Tom Select search over the logged-in lawyer's debtor library, scoped per user
 * so the dropdown never shows another lawyer's companies.
 */
#[AsEntityAutocompleteField]
final class DebtorAutocompleteType extends AbstractType
{
    public function __construct(
        private readonly Security $security,
    ) {}

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Debtor::class,
            'placeholder' => 'wizard.step2.library.placeholder',
            'choice_label' => static fn (Debtor $d): string => sprintf('%s (CUI %s)', $d->getName(), $d->getCui() ?? ''),
            'searchable_fields' => ['name', 'cui'],
            // Wait for two typed characters rather than listing the whole
            // library on focus.
            'min_characters' => 2,
            'preload' => false,
            'query_builder' => function (DebtorRepository $repo) {
                $user = $this->security->getUser();
                if (!$user instanceof User) {
                    return $repo->createQueryBuilder('d')->andWhere('1 = 0');
                }

                return $repo->createAutocompleteQueryBuilder($user);
            },
            'required' => false,
            'label' => false,
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
