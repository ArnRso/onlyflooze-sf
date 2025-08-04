<?php

namespace App\Form;

use App\Entity\CsvImportProfile;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array{file: mixed, profile: mixed}>
 */
class CsvUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'Fichier CSV',
                'attr' => [
                    'class' => 'form-control',
                    'accept' => '.csv,.txt'
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
                            'application/octet-stream'
                        ],
                        'mimeTypesMessage' => 'Veuillez uploader un fichier CSV valide',
                    ])
                ],
            ])
            ->add('profile', EntityType::class, [
                'class' => CsvImportProfile::class,
                'choice_label' => 'name',
                'choice_value' => 'id',
                'choices' => $options['profiles'],
                'label' => 'Profil d\'import',
                'attr' => ['class' => 'form-select'],
                'placeholder' => 'Sélectionnez un profil',
                'constraints' => [
                    new NotBlank(['message' => 'Veuillez sélectionner un profil d\'import']),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'profiles' => [],
        ]);

        $resolver->setAllowedTypes('profiles', 'array');
    }
}
