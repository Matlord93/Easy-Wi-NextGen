<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\Sinusbot\SinusbotNodeDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SinusbotNodeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('agentBaseUrl', TextType::class)
            ->add('agentApiToken', TextType::class)
            ->add('downloadUrl', TextType::class)
            ->add('installPath', TextType::class)
            ->add('instanceRoot', TextType::class)
            ->add('webBindIp', TextType::class)
            ->add('webPortBase', IntegerType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SinusbotNodeDto::class,
        ]);
    }
}
