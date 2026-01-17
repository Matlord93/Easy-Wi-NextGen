<?php

declare(strict_types=1);

namespace App\Module\Core\Form;

use App\Module\Core\Dto\Ts6\Ts6NodeDto;
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
            ->add('agentNodeId', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'Agent auswählen',
                'choices' => $options['agent_choices'],
            ])
            ->add('agentBaseUrl', TextType::class, [
                'required' => false,
                'empty_data' => '',
            ])
            ->add('agentApiToken', TextType::class, [
                'required' => false,
                'empty_data' => '',
            ])
            ->add('osType', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    'Linux' => 'linux',
                    'Windows' => 'windows',
                ],
                'placeholder' => 'Auto',
            ])
            ->add('downloadUrl', TextType::class)
            ->add('installPath', TextType::class, [
                'required' => false,
                'empty_data' => '',
            ])
            ->add('instanceName', TextType::class, [
                'required' => false,
                'empty_data' => '',
            ])
            ->add('serviceName', TextType::class, [
                'required' => false,
                'empty_data' => '',
            ])
            ->add('queryBindIp', TextType::class)
            ->add('queryHttpsPort', IntegerType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ts6NodeDto::class,
            'agent_choices' => [],
        ]);

        $resolver->setAllowedTypes('agent_choices', 'array');
    }
}
