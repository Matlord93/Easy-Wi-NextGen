<?php

declare(strict_types=1);

namespace App\Module\Core\Form;

use App\Module\Core\Dto\Ts6\AdminCreateVirtualServerDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class Ts6AdminCreateVirtualServerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('customerId', ChoiceType::class, [
                'choices' => $options['customer_choices'],
            ])
            ->add('nodeId', ChoiceType::class, [
                'choices' => $options['node_choices'],
            ])
            ->add('name', TextType::class)
            ->add('slots', IntegerType::class)
            ->add('voicePort', IntegerType::class, [
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminCreateVirtualServerDto::class,
            'customer_choices' => [],
            'node_choices' => [],
        ]);
    }
}
