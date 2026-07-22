<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * The lawyer's agreement to sub-processing of case documents by Anthropic.
 *
 * Deliberately not bound to the User entity: the entity fields it leads to
 * (aiProcessingAgreementAt / Version) record when the agreement was given, and
 * must not be settable straight from request data.
 *
 * Legally this is not a GDPR consent. The data subject in the documents is the
 * debtor, not the account holder, so the account holder's consent would carry
 * no weight towards them. What is being agreed here is sub-processing under
 * art. 28 plus the departure from professional secrecy that sending a client's
 * contract to a third-party processor entails.
 */
final class AiProcessingAgreementType extends AbstractType
{
    /**
     * Stamped onto the User alongside the timestamp. Bump it whenever the
     * agreement text changes materially, so an existing acceptance can be told
     * apart from one given under the current wording.
     */
    public const CURRENT_VERSION = 'v1-draft';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('accepted', CheckboxType::class, [
            'mapped' => false,
            'required' => true,
            'label' => 'profile.ai_agreement.accept_label',
            'constraints' => [
                new IsTrue(message: 'profile.ai_agreement.error.not_accepted'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_token_id' => 'ai_processing_agreement',
        ]);
    }
}
