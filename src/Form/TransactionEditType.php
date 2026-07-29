<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\Tag;
use App\Entity\Transaction;
use App\Enum\TransactionNature;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Transaction>
 */
class TransactionEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'choice_label' => 'fullName',
                'required' => false,
                'placeholder' => 'À trier',
                'label' => 'Catégorie',
            ])
            ->add('nature', EnumType::class, [
                'class' => TransactionNature::class,
                'choice_label' => static fn (TransactionNature $nature): string => $nature->label(),
                'label' => 'Nature',
            ])
            ->add('tags', EntityType::class, [
                'class' => Tag::class,
                'choice_label' => 'name',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'label' => 'Tags',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Transaction::class,
        ]);
    }
}
