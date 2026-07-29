<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\Recurrence;
use App\Enum\Direction;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Recurrence>
 */
class RecurrenceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom',
            ])
            ->add('direction', EnumType::class, [
                'class' => Direction::class,
                'choice_label' => static fn (Direction $direction): string => match ($direction) {
                    Direction::Debit => 'Dépense',
                    Direction::Credit => 'Revenu',
                },
                'label' => 'Sens',
            ])
            ->add('expectedDayOfMonth', IntegerType::class, [
                'label' => 'Jour attendu du mois',
                'attr' => ['min' => 1, 'max' => 31],
            ])
            ->add('expectedAmountCents', MoneyType::class, [
                'label' => 'Montant attendu',
                'divisor' => 100,
                'help' => 'Négatif pour une dépense (ex : -84,09)',
            ])
            ->add('tolerancePct', IntegerType::class, [
                'label' => 'Tolérance (%)',
            ])
            ->add('dayWindow', IntegerType::class, [
                'label' => 'Fenêtre de date (± jours)',
            ])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'choice_label' => 'fullName',
                'required' => false,
                'placeholder' => '—',
                'label' => 'Catégorie',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Active',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Recurrence::class,
        ]);
    }
}
