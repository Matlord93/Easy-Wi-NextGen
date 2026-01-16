<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\HttpFoundation\Request;

final class PortRangeFormType
{
    public const PROTOCOLS = ['tcp', 'udp'];

    /**
     * @return array{purpose: string, protocol: string, start_port: ?int, end_port: ?int, enabled: bool}
     */
    public function getDataFromRequest(Request $request): array
    {
        $purpose = trim((string) $request->request->get('purpose', ''));
        $protocol = strtolower(trim((string) $request->request->get('protocol', '')));
        $startValue = $request->request->get('start_port');
        $endValue = $request->request->get('end_port');
        $enabled = $request->request->get('enabled') === '1';

        return [
            'purpose' => $purpose,
            'protocol' => $protocol,
            'start_port' => is_numeric($startValue) ? (int) $startValue : null,
            'end_port' => is_numeric($endValue) ? (int) $endValue : null,
            'enabled' => $enabled,
        ];
    }

    /**
     * @param array{purpose: string, protocol: string, start_port: ?int, end_port: ?int, enabled: bool} $data
     * @return string[]
     */
    public function validate(array $data): array
    {
        $errors = [];

        if ($data['purpose'] === '') {
            $errors[] = 'purpose_required';
        } elseif (mb_strlen($data['purpose']) > 120) {
            $errors[] = 'purpose_too_long';
        }

        if (!in_array($data['protocol'], self::PROTOCOLS, true)) {
            $errors[] = 'protocol_invalid';
        }

        if ($data['start_port'] === null || $data['end_port'] === null) {
            $errors[] = 'ports_required';
        } else {
            if ($data['start_port'] < 1 || $data['end_port'] < 1 || $data['start_port'] > 65535 || $data['end_port'] > 65535) {
                $errors[] = 'ports_out_of_range';
            }

            if ($data['start_port'] >= $data['end_port']) {
                $errors[] = 'ports_order_invalid';
            }
        }

        return $errors;
    }

    /**
     * @return string[]
     */
    public function getProtocols(): array
    {
        return self::PROTOCOLS;
    }
}
