<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Message\SendTemplateMailMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class MailService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly AppSettingsService $appSettingsService,
        private readonly ?MessageBusInterface $messageBus = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function sendTemplate(
        string $to,
        string $templateKey,
        array $context = [],
        ?string $locale = null,
        bool $useQueue = false,
    ): bool {
        $resolvedLocale = $this->resolveLocale($locale ?? ($context['locale'] ?? null));

        if ($useQueue && $this->messageBus !== null) {
            $this->messageBus->dispatch(new SendTemplateMailMessage($to, $templateKey, $context, $resolvedLocale));

            return true;
        }

        if ($useQueue) {
            $this->logger->warning('Mail queue requested but messenger is not available. Sending synchronously.', [
                'to' => $to,
                'template' => $templateKey,
            ]);
        }

        return $this->sendNow($to, $templateKey, $context, $resolvedLocale);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function sendNow(string $to, string $templateKey, array $context, string $locale): bool
    {
        $htmlTemplate = sprintf('email/%s/%s.html.twig', $locale, $templateKey);
        $textTemplate = sprintf('email/%s/%s.text.twig', $locale, $templateKey);

        if (!$this->twig->getLoader()->exists($htmlTemplate)) {
            $this->logger->error('Email template not found.', [
                'template' => $htmlTemplate,
                'locale' => $locale,
                'key' => $templateKey,
            ]);

            return false;
        }

        $subjectKey = sprintf('mail.%s.subject', $templateKey);
        $subject = $this->translator->trans($subjectKey, [], 'mail', $locale);
        if ($subject === $subjectKey) {
            $this->logger->warning('Email subject translation missing.', [
                'key' => $subjectKey,
                'locale' => $locale,
            ]);
            $subject = ucfirst(str_replace('_', ' ', $templateKey));
        }

        $payload = $this->buildContext($context, $locale);

        $fromAddress = $this->appSettingsService->getMailFromAddress();
        $fromName = $this->appSettingsService->getMailFromName();
        $replyTo = $this->appSettingsService->getMailReplyTo();

        $email = (new Email())
            ->from(new Address($fromAddress, $fromName))
            ->to($to)
            ->subject($subject)
            ->html($this->twig->render($htmlTemplate, $payload));

        if ($this->twig->getLoader()->exists($textTemplate)) {
            $email->text($this->twig->render($textTemplate, $payload));
        }
        if ($replyTo !== null && $replyTo !== '') {
            $email->replyTo($replyTo);
        }

        try {
            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Failed to send email.', [
                'to' => $to,
                'template' => $templateKey,
                'locale' => $locale,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildContext(array $context, string $locale): array
    {
        $supportEmail = $this->appSettingsService->getSupportEmail();

        return array_merge([
            'app_name' => $this->appSettingsService->getBrandingName(),
            'support_email' => $supportEmail ?? $this->appSettingsService->getMailFromAddress(),
            'locale' => $locale,
            'current_year' => (new \DateTimeImmutable())->format('Y'),
        ], $context);
    }

    private function resolveLocale(?string $locale): string
    {
        if (!is_string($locale)) {
            return $this->appSettingsService->getMailDefaultLocale();
        }

        $normalized = strtolower(trim($locale));
        if ($normalized === '') {
            return 'en';
        }

        $short = substr($normalized, 0, 2);
        if (in_array($short, ['de', 'en'], true)) {
            return $short;
        }

        return $this->appSettingsService->getMailDefaultLocale();
    }
}
