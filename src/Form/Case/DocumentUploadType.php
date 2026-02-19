<?php

namespace App\Form\Case;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

class DocumentUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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
                'choices' => [
                    'document.type.dovada' => 'dovada',
                    'document.type.contract' => 'contract',
                    'document.type.factura' => 'factura',
                    'document.type.alt_document' => 'alt_document',
                ],
            ])
        ;
    }
}
