<?php

declare(strict_types=1);

namespace App\Module\Core\Form;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

trait NodeFormTypeTrait
{
    private function addAgentFields(FormBuilderInterface $builder, array $options): void
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
            ]);
    }

    private function addInstanceFields(FormBuilderInterface $builder): void
    {
        $builder
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
            ->add('queryBindIp', TextType::class);
    }
}
