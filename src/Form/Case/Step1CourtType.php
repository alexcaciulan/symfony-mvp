<?php

namespace App\Form\Case;

use App\Entity\Court;
use App\Repository\CourtRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class Step1CourtType extends AbstractType
{
    public function __construct(
        private CourtRepository $courtRepository,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $counties = $this->courtRepository->findDistinctCounties();
        $countyChoices = array_combine($counties, $counties);

        $builder->add('county', ChoiceType::class, [
            'label' => 'wizard.step1.county',
            'choices' => $countyChoices,
            'placeholder' => 'wizard.step1.county_placeholder',
            'required' => true,
            'constraints' => [
                new NotBlank(message: 'wizard.step1.county_required'),
            ],
        ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $data = $event->getData();
            $county = $data['county'] ?? null;
            $this->addCourtField($event->getForm(), $county);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $county = $data['county'] ?? null;
            $this->addCourtField($event->getForm(), $county);
        });
    }

    private function addCourtField(FormInterface $form, ?string $county): void
    {
        $form->add('court', EntityType::class, [
            'class' => Court::class,
            'label' => 'wizard.step1.court',
            'placeholder' => 'wizard.step1.court_placeholder',
            'required' => true,
            'choice_label' => 'name',
            'choices' => $county ? $this->courtRepository->findActiveByCounty($county) : [],
            'constraints' => [
                new NotBlank(message: 'wizard.step1.court_required'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
