<?php

declare(strict_types=1);

namespace App\Form\Case;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** One invoice uploaded from the claim card, to be read and turned into a position. */
class ClaimInvoiceUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FileType::class, [
            'constraints' => [
                new Assert\NotBlank(message: 'document.upload.file_required'),
                new Assert\File(
                    maxSize: '10M',
                    mimeTypes: ['application/pdf', 'image/jpeg', 'image/png'],
                    maxSizeMessage: 'document.upload.too_large',
                    mimeTypesMessage: 'document.upload.invalid_type',
                ),
            ],
        ]);
    }

    /** The dialog posts `claim_invoice[...]`; the class name would give a longer prefix. */
    public function getBlockPrefix(): string
    {
        return 'claim_invoice';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'claim_invoice',
        ]);
    }
}
