<?php

declare(strict_types=1);

namespace App\Module\Core\Form;

use App\Module\Core\Dto\Sinusbot\SinusbotNodeDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class SinusbotNodeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('customerId', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'Kein Kunde',
                'choices' => $options['customer_choices'],
                'empty_data' => null,
            ])
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
            ->add('downloadUrl', TextType::class)
            ->add('installPath', TextType::class, [
                'required' => true,
                'help' => 'Muss absolut sein, kein "..", "~" oder Nullbytes.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Installationspfad darf nicht leer sein.'),
                    new Assert\Regex(pattern: '/^\\//', message: 'Installationspfad muss mit "/" beginnen.'),
                    new Assert\Regex(pattern: '/\\.\\./', match: false, message: 'Installationspfad darf kein ".." enthalten.'),
                    new Assert\Regex(pattern: '/\\x00/', match: false, message: 'Installationspfad darf keine Nullbytes enthalten.'),
                    new Assert\Regex(pattern: '/~/', match: false, message: 'Installationspfad darf kein "~" enthalten.'),
                ],
            ])
            ->add('instanceRoot', TextType::class, [
                'required' => false,
                'empty_data' => '',
            ])
            ->add('webBindIp', TextType::class)
            ->add('webPortBase', IntegerType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SinusbotNodeDto::class,
            'agent_choices' => [],
            'customer_choices' => [],
        ]);

        $resolver->setAllowedTypes('agent_choices', 'array');
        $resolver->setAllowedTypes('customer_choices', 'array');
    }
}
