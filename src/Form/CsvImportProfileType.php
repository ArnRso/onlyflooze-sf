<?php

namespace App\Form;

use App\Entity\CsvImportProfile;
use App\Service\CsvProfileService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * @extends AbstractType<CsvImportProfile>
 */
class CsvImportProfileType extends AbstractType
{
    public function __construct(private readonly CsvProfileService $csvProfileService)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom du profil',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(['message' => 'Le nom est obligatoire']),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Description optionnelle du profil'
                ],
            ])
            ->add('delimiter', ChoiceType::class, [
                'label' => 'Séparateur',
                'choices' => $this->csvProfileService->getAvailableDelimiters(),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('encoding', ChoiceType::class, [
                'label' => 'Encodage',
                'choices' => $this->csvProfileService->getAvailableEncodings(),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('dateFormat', ChoiceType::class, [
                'label' => 'Format de date',
                'choices' => $this->csvProfileService->getAvailableDateFormats(),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('amountType', ChoiceType::class, [
                'label' => 'Type de montant',
                'choices' => $this->csvProfileService->getAmountTypes(),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('hasHeader', CheckboxType::class, [
                'label' => 'Le fichier contient une ligne d\'en-tête',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ])

            // Column mapping fields
            ->add('dateColumn', IntegerType::class, [
                'label' => 'Colonne Date (numéro)',
                'mapped' => false,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => '0'
                ],
                'constraints' => [
                    new NotBlank(['message' => 'La colonne date est obligatoire']),
                    new Range(['min' => 0, 'max' => 50]),
                ],
            ])
            ->add('labelColumn', IntegerType::class, [
                'label' => 'Colonne Libellé (numéro)',
                'mapped' => false,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => '1'
                ],
                'constraints' => [
                    new NotBlank(['message' => 'La colonne libellé est obligatoire']),
                    new Range(['min' => 0, 'max' => 50]),
                ],
            ])
            ->add('amountColumn', IntegerType::class, [
                'label' => 'Colonne Montant (numéro)',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => '2'
                ],
                'constraints' => [
                    new Range(['min' => 0, 'max' => 50]),
                ],
            ])
            ->add('creditColumn', IntegerType::class, [
                'label' => 'Colonne Crédit (numéro)',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => '3'
                ],
                'constraints' => [
                    new Range(['min' => 0, 'max' => 50]),
                ],
            ])
            ->add('debitColumn', IntegerType::class, [
                'label' => 'Colonne Débit (numéro)',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => '4'
                ],
                'constraints' => [
                    new Range(['min' => 0, 'max' => 50]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CsvImportProfile::class,
        ]);
    }
}
