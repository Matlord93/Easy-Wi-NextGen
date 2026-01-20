<?php

declare(strict_types=1);

namespace App\Module\Core\Form;

use App\Module\Core\Dto\Ts3\Ts3NodeDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class Ts3NodeType extends AbstractType
{
    use NodeFormTypeTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addAgentFields($builder, $options);
        $this->addInstanceFields($builder);
        $builder
            ->add('queryPort', IntegerType::class)
            ->add('filetransferPort', IntegerType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ts3NodeDto::class,
            'agent_choices' => [],
        ]);

        $resolver->setAllowedTypes('agent_choices', 'array');
    }
}
