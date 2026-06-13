<?php

namespace App\Form\subForms;

use App\Entity\Embeddables\Address;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AddressType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('street', null, ['label' => 'form.street'])
            ->add('houseNumber', null, ['label' => 'form.house_number'])
            ->add('city', null, ['label' => 'form.city'])
            ->add('zipcode', null, ['label' => 'form.zipcode'])
            ->add('additionalData', null, ['label' => 'form.additional_data'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Address::class,
        ]);
    }
}
