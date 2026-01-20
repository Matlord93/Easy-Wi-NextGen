<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

final class NullMailer implements MailerInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
    }
}
