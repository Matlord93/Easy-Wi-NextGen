<?php

declare(strict_types=1);

namespace App\Module\Core\Form;

use App\Module\Core\Dto\Ts6\Ts6NodeDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class Ts6NodeType extends AbstractType
{
    use NodeFormTypeTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addAgentFields($builder, $options);
        $builder
            ->add('osType', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    'Linux' => 'linux',
                    'Windows' => 'windows',
                ],
                'placeholder' => 'Auto',
            ])
            ;
        $this->addInstanceFields($builder);
        $builder
            ->add('queryHttpsPort', IntegerType::class)
            ->add('voicePort', IntegerType::class);
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
