<?php

namespace App\Form\subForms;

use App\Entity\Embeddables\Active;
use App\Enums\ActiveStatus;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ActiveType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('status', EnumType::class, ['class' => ActiveStatus::class, 'empty_data' => ActiveStatus::Active])
            ->add('statusDescription')
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Active::class,
        ]);
    }
}
