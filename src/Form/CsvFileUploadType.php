<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array{file: mixed}>
 */
class CsvFileUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'Sélectionnez votre fichier CSV',
                'attr' => [
                    'class' => 'form-control',
                    'accept' => '.csv,.txt',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Veuillez sélectionner un fichier']),
                    new File([
                        'maxSize' => '10M',
                        'mimeTypes' => [
                            'text/csv',
                            'text/plain',
                            'application/csv',
                            'text/comma-separated-values',
                            'application/vnd.ms-excel',
                            'application/octet-stream',
                        ],
                        'mimeTypesMessage' => 'Veuillez uploader un fichier CSV valide',
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
