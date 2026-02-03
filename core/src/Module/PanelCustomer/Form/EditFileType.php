<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;

final class EditFileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('path', HiddenType::class)
            ->add('content', TextareaType::class, [
                'required' => true,
                'attr' => [
                    'rows' => 22,
                    'class' => 'w-full rounded-lg border border-slate-200 bg-white px-3 py-2 font-mono text-sm',
                ],
            ]);
    }
}
