<?php

namespace App\Form\subForms;

use App\Entity\OpeningTime;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OpeningTimeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('open_time', null, ['label' => 'form.open_time'])
            ->add('twenty_for_seven', CheckboxType::class, ['label' => 'form.twenty_four_seven'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OpeningTime::class,
        ]);
    }
}
