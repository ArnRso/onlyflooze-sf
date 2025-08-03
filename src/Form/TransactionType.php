<?php

namespace App\Form;

use App\Entity\RecurringTransaction;
use App\Entity\Tag;
use App\Entity\Transaction;
use App\Repository\RecurringTransactionRepository;
use App\Repository\TagRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class TransactionType extends AbstractType
{
    public function __construct(
        private readonly TagRepository                  $tagRepository,
        private readonly RecurringTransactionRepository $recurringTransactionRepository,
        private readonly Security                       $security
    )
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'Libellé',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Le libellé est obligatoire',
                    ]),
                ],
            ])
            ->add('transactionDate', DateType::class, [
                'label' => 'Date de transaction',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank([
                        'message' => 'La date est obligatoire',
                    ]),
                ],
            ])
            ->add('amount', MoneyType::class, [
                'label' => 'Montant (€)',
                'currency' => 'EUR',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Le montant est obligatoire',
                    ]),
                ],
            ])
            ->add('info', TextareaType::class, [
                'label' => 'Informations complémentaires',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Informations optionnelles...'
                ],
            ])
            ->add('tags', EntityType::class, [
                'class' => Tag::class,
                'choice_label' => 'name',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'label' => 'Tags',
                'attr' => [
                    'class' => 'form-select',
                    'multiple' => true
                ],
                'query_builder' => function () {
                    $user = $this->security->getUser();
                    return $this->tagRepository->createQueryBuilder('t')
                        ->where('t.user = :user')
                        ->setParameter('user', $user)
                        ->orderBy('t.name', 'ASC');
                },
            ])
            ->add('budgetMonth', TextType::class, [
                'label' => 'Mois budgétaire (YYYY-MM)',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '2025-01',
                    'pattern' => '[0-9]{4}-[0-9]{2}'
                ],
                'help' => 'Format : YYYY-MM (ex: 2025-01 pour janvier 2025)',
                'constraints' => [
                    new Regex([
                        'pattern' => '/^\d{4}-\d{2}$/',
                        'message' => 'Le format doit être YYYY-MM (ex: 2025-01)',
                    ]),
                ],
            ])
            ->add('recurringTransaction', EntityType::class, [
                'class' => RecurringTransaction::class,
                'choice_label' => 'name',
                'required' => false,
                'label' => 'Transaction récurrente',
                'placeholder' => 'Sélectionner une transaction récurrente...',
                'attr' => [
                    'class' => 'form-select'
                ],
                'query_builder' => function () {
                    $user = $this->security->getUser();
                    return $this->recurringTransactionRepository->createQueryBuilder('rt')
                        ->where('rt.user = :user')
                        ->setParameter('user', $user)
                        ->orderBy('rt.name', 'ASC');
                },
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Transaction::class,
        ]);
    }
}
