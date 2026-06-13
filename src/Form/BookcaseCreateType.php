<?php

namespace App\Form;

use App\Entity\Bookcase;
use App\Enums\EntryType;
use App\Form\subForms\PositionType;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Deliberately minimal "quick add" form: just enough to drop a new entry on the
 * map (title, entry type, installation type, coordinates). Everything else uses
 * the entity defaults (status Active, map symbol Standard) and can be filled in
 * afterwards via the full BookcaseType edit form.
 */
class BookcaseCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', null, ['label' => 'form.title'])
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
            ->add('position', PositionType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Bookcase::class,
        ]);
    }
}
