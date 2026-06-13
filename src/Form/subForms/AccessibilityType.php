<?php

namespace App\Form\subForms;

use App\Entity\Embeddables\Accessibility;
use App\Enums\AccessibilityLevel;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AccessibilityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Rendered as a red/yellow/green traffic light in the template; this
            // field only needs to bind the chosen value.
            ->add('level', EnumType::class, [
                'class' => AccessibilityLevel::class,
                'required' => false,
                'label' => 'form.accessibility_level',
            ])
            ->add('description', null, ['label' => 'form.accessibility_description'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Accessibility::class,
        ]);
    }
}
