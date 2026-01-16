<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\Ts6\Ts6NodeDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class Ts6NodeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('agentBaseUrl', TextType::class)
            ->add('agentApiToken', TextType::class)
            ->add('osType', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    'Linux' => 'linux',
                    'Windows' => 'windows',
                ],
                'placeholder' => 'Auto',
            ])
            ->add('downloadUrl', TextType::class)
            ->add('installPath', TextType::class)
            ->add('instanceName', TextType::class)
            ->add('serviceName', TextType::class)
            ->add('queryBindIp', TextType::class)
            ->add('queryHttpsPort', IntegerType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ts6NodeDto::class,
        ]);
    }
}
