<?php

namespace App\Form;

use App\Entity\Bookcase;
use App\Enums\EntryType;
use App\Form\subForms\AccessibilityType;
use App\Form\subForms\ActiveType;
use App\Form\subForms\AddressType;
use App\Form\subForms\CaretakerType;
use App\Form\subForms\OpeningTimeType;
use App\Form\subForms\PositionType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BookcaseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', null, ['label' => 'form.title'])
            ->add('position', PositionType::class)
            ->add('webpage', null, ['label' => 'form.webpage'])
            ->add('isMobile', CheckboxType::class, ['required' => false, 'label' => 'form.is_mobile', 'attr' => ['class' => 'toggle']])
            ->add('accessibility', AccessibilityType::class)
            ->add('entryType', EnumType::class, [
                'class' => EntryType::class,
                'empty_data' => EntryType::Bookcase,
                'label' => 'form.entry_type',
                // Translate the options (e.g. "bookcase" → "Bücherschrank") via the
                // bookcasetypes domain, keyed by the enum value.
                'choice_label' => fn (EntryType $case) => $case->value,
                'choice_translation_domain' => 'bookcasetypes',
            ])
            ->add('installationType', null, ['label' => 'form.installation_type'])
            ->add('active', ActiveType::class)
            ->add('digitalMediaAllowed', CheckboxType::class, ['label' => 'form.digital_media_allowed'])
            ->add(
                'caretakers',
                CollectionType::class,
                [
                    'entry_type' => CaretakerType::class,
                    'allow_add' => true,
                    'allow_delete' => true,
                    'by_reference' => false,
                ]
            )
            ->add('address', AddressType::class)
            ->add(
                'openingTimes',
                CollectionType::class,
                [
                    'entry_type' => OpeningTimeType::class,
                    'allow_add' => true,
                    'allow_delete' => true,
                ]
            )
            ->add('comment', null, ['label' => 'form.comment'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Bookcase::class,
        ]);
    }
}
