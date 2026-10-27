<?php

declare(strict_types=1);

namespace App\Form\Case;

use App\Enum\DocumentType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class DocumentUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Derive choices from the enum so the form and the upload modal stay in
        // lockstep (DocumentType::uploadableTypes() is the single source).
        $choices = [];
        foreach (DocumentType::uploadableTypes() as $type) {
            $choices['document.type.' . $type->value] = $type->value;
        }

        $builder
            ->add('file', FileType::class, [
                'label' => 'document.upload.file_label',
                'constraints' => [
                    new Assert\NotBlank(message: 'document.upload.file_required'),
                    new Assert\File(
                        maxSize: '10M',
                        mimeTypes: ['application/pdf', 'image/jpeg', 'image/png'],
                        maxSizeMessage: 'document.upload.too_large',
                        mimeTypesMessage: 'document.upload.invalid_type',
                    ),
                ],
            ])
            ->add('documentType', ChoiceType::class, [
                'label' => 'document.upload.type_label',
                'choices' => $choices,
            ])
        ;

        // Only a power of attorney carries these; the date is required for it alone,
        // checked below, so every other type uploads without them.
        PowerOfAttorneyDetailsType::addFields($builder, dateRequired: false);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Explicit token_id so it matches csrf_token('document_upload') rendered in the
        // modal (default would be the stateless 'submit' token, see config/packages/csrf.yaml).
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'document_upload',
            'constraints' => [
                new Assert\Callback(callback: static function (mixed $data, ExecutionContextInterface $context): void {
                    if (!is_array($data)
                        || ($data['documentType'] ?? null) !== DocumentType::IMPUTERNICIRE_AVOCATIALA->value
                        || ($data['powerOfAttorneyDate'] ?? null) !== null) {
                        return;
                    }
                    $context->buildViolation('document.power_of_attorney.date_required')
                        ->atPath('[powerOfAttorneyDate]')
                        ->addViolation();
                }),
            ],
        ]);
    }
}
