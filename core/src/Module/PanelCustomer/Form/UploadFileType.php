<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;

final class UploadFileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('path', HiddenType::class)
            ->add('upload', FileType::class, [
                'required' => true,
                'attr' => [
                    'class' => 'block w-full text-sm text-slate-700',
                ],
            ]);
    }
}
