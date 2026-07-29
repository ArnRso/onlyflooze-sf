<?php

namespace App\Form;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Enum\TransactionNature;
use App\Repository\CategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CategorizationRule>
 */
class RuleEditType extends AbstractType
{
    public function __construct(
        private readonly CategoryRepository $categoryRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom',
            ])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'choices' => $this->categoryRepository->findAllOrdered(),
                'choice_label' => 'fullName',
                'label' => 'Catégorie',
            ])
            ->add('tokens', TextType::class, [
                'label' => 'Tokens discriminants',
                'help' => 'Séparés par des espaces, matchés sur mot entier',
            ])
            ->add('amountCents', MoneyType::class, [
                'label' => 'Montant exact (sous-règle)',
                'divisor' => 100,
                'required' => false,
                'help' => 'Réservé aux agrégateurs type PayPal ; vide sinon',
            ])
            ->add('nature', EnumType::class, [
                'class' => TransactionNature::class,
                'choice_label' => static fn (TransactionNature $nature): string => $nature->label(),
                'required' => false,
                'placeholder' => 'Selon le sens (défaut)',
                'label' => 'Nature imposée',
            ])
            ->add('recurrenceOptOut', CheckboxType::class, [
                'label' => 'Ne jamais proposer en récurrence',
                'required' => false,
            ]);

        $builder->get('tokens')->addModelTransformer(new CallbackTransformer(
            static fn (?array $tokens): string => implode(' ', $tokens ?? []),
            static fn (?string $value): array => array_values(array_filter(explode(' ', mb_strtoupper(trim((string) $value))))),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CategorizationRule::class,
        ]);
    }
}
