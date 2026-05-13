<?php

declare(strict_types=1);

namespace App\Form\Wizard;

use App\Entity\Creditor;
use App\Entity\User;
use App\Repository\CreditorRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

/**
 * Pas 3.3 — Tom Select autocomplete for picking an existing creditor from the
 * logged-in user's library. Scoped per-user via
 * {@see CreditorRepository::createAutocompleteQueryBuilder()} so the dropdown
 * never leaks other lawyers' creditor records (UNIQUE(user, cui) on the entity
 * means two lawyers can have the same CUI without overlap).
 */
#[AsEntityAutocompleteField]
final class CreditorAutocompleteType extends AbstractType
{
    public function __construct(
        private readonly Security $security,
    ) {}

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Creditor::class,
            'placeholder' => 'wizard.step1.autocomplete.placeholder',
            'choice_label' => static fn (Creditor $c): string => sprintf(
                '%s — %s',
                $c->getName(),
                $c->getCui() ?? '—',
            ),
            'searchable_fields' => ['name', 'cui'],
            'query_builder' => function (CreditorRepository $repo) {
                $user = $this->security->getUser();
                if (!$user instanceof User) {
                    // No authenticated user — return a builder that yields nothing
                    // (instead of leaking every creditor in the table).
                    return $repo->createQueryBuilder('c')->andWhere('1 = 0');
                }

                return $repo->createAutocompleteQueryBuilder($user);
            },
            // Don't autoload the full DTO Step1CreditorData — the parent form
            // handles binding; this child just emits a Creditor id.
            'mapped' => true,
            'required' => false,
            'label' => false,
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
