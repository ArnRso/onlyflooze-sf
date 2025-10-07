<?php

namespace App\Form;

use App\Entity\RecurringTransaction;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<RecurringTransaction>
 */
class RecurringTransactionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom de la transaction récurrente',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Facture électricité, Salaire, Crédit maison...',
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Le nom est obligatoire',
                    ]),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RecurringTransaction::class,
        ]);
    }
}
