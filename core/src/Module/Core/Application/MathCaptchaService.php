<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class MathCaptchaService
{
    public function issueChallenge(SessionInterface $session, string $formKey): array
    {
        $left = random_int(1, 9);
        $right = random_int(1, 9);

        $session->set($this->sessionKey($formKey), [
            'answer' => (string) ($left + $right),
            'issued_at' => time(),
        ]);

        return [
            'question' => sprintf('%d + %d = ?', $left, $right),
        ];
    }

    public function verifyAnswer(SessionInterface $session, string $formKey, string $answer): bool
    {
        $data = $session->get($this->sessionKey($formKey));
        if (!is_array($data)) {
            return false;
        }

        $expected = (string) ($data['answer'] ?? '');
        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, trim($answer));
    }

    private function sessionKey(string $formKey): string
    {
        return 'math_captcha_' . $formKey;
    }
}
