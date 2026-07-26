<?php

declare(strict_types=1);

namespace App\Form\Deadline;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Repository\LegalCaseRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The manual deadline added from the global agenda: the same form the case page
 * uses, plus the one thing the agenda has no URL to take it from, the case.
 *
 * It is composed on top of {@see AddDeadlineType} through `getParent()` rather than
 * by subclassing it, so the date and description fields, their constraints and their
 * labels stay defined once and the form used inside a case is left untouched.
 *
 * The choices are the active cases of the signed-in lawyer, which makes a foreign
 * case fail validation before it ever reaches a controller; the authorization check
 * on the chosen case is done there as well, because a form is a filter, not a
 * permission.
 */
final class AgendaAddDeadlineType extends AbstractType
{
    public function __construct(
        private readonly LegalCaseRepository $legalCaseRepository,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $user */
        $user = $options['user'];

        $builder->add('legalCase', EntityType::class, [
            'class' => LegalCase::class,
            'query_builder' => fn (): QueryBuilder => $this->legalCaseRepository->activeByUserQueryBuilder($user),
            'choice_label' => static fn (LegalCase $case): string => $case->getCourtCaseNumber() ?? $case->getCaseNumber(),
            'placeholder' => 'deadlines.add.field_case_placeholder',
            'label' => 'deadlines.add.field_case_label',
            'constraints' => [
                new Assert\NotNull(message: 'deadlines.add.field_case_required'),
            ],
        ]);
    }

    public function getParent(): string
    {
        return AddDeadlineType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // A token of its own: the two forms live on different pages and must not be
        // interchangeable.
        $resolver->setDefaults(['csrf_token_id' => 'agenda_add_deadline']);
        $resolver->setRequired('user');
        $resolver->setAllowedTypes('user', User::class);
    }
}
