<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class RenameFileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('path', HiddenType::class)
            ->add('newName', TextType::class, [
                'required' => true,
                'attr' => [
                    'class' => 'w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm',
                ],
            ]);
    }
}
