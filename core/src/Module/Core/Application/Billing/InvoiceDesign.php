<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Infrastructure\Config\JsonArray;
use App\Module\Core\Application\AppSettingsService;

final readonly class InvoiceDesign
{
    public function __construct(private AppSettingsService $settings)
    {
    }

    /**
     * @return array{accent: string, text_color: string, font: string, font_size: int, margin_mm: int,
     * spacing: string, header_align: string, style: string, heading: string, intro: string, footer_text:
     * string, logo_data_uri: string, logo_width: int, show_tax_column: bool}
     */
    public static function defaults(): array
    {
        return ['accent' => '#4f46e5', 'text_color' => '#18212f', 'font' => 'sans', 'font_size' => 10,
            'margin_mm' => 15, 'spacing' => 'comfortable', 'header_align' => 'left', 'style' => 'modern',
            'heading' => '', 'intro' => '', 'footer_text' => '', 'logo_data_uri' => '', 'logo_width' => 140, 'show_tax_column' => true];
    }

    public function current(): array
    {
        $stored = $this->settings->getSettings()[AppSettingsService::KEY_INVOICE_DESIGN] ?? [];
        try {
            $options = self::normalize(array_replace(self::defaults(), JsonArray::orEmpty($stored['options'] ?? null)));
        } catch (\InvalidArgumentException) {
            $options = self::defaults();
        }
        $layout = $this->settings->getInvoiceLayout();

        return ['options' => $options, 'layout' => $layout, 'mode' => hash_equals(hash('sha256', self::layout($options)), hash('sha256', $layout)) ? 'visual' : 'custom'];
    }

    public static function normalize(array $input): array
    {
        $values = self::defaults();
        foreach (['accent', 'text_color'] as $key) {
            if (!is_string($input[$key] ?? null) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $input[$key])) {
                throw new \InvalidArgumentException('billing.invalid_design');
            }
            $values[$key] = strtolower($input[$key]);
        }
        foreach (['font' => ['sans', 'serif', 'mono'], 'spacing' => ['comfortable', 'compact'], 'header_align' => ['left', 'right'], 'style' => ['modern', 'classic']] as $key => $allowed) {
            if (!in_array($input[$key] ?? null, $allowed, true)) {
                throw new \InvalidArgumentException('billing.invalid_design');
            }
            $values[$key] = $input[$key];
        }
        foreach (['font_size' => [9, 14], 'margin_mm' => [10, 25], 'logo_width' => [50, 180]] as $key => [$min, $max]) {
            $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT);
            if (false === $value || $value < $min || $value > $max) {
                throw new \InvalidArgumentException('billing.invalid_design');
            }
            $values[$key] = $value;
        }
        foreach (['heading' => 80, 'intro' => 1000, 'footer_text' => 2000] as $key => $max) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || mb_strlen($value) > $max) {
                throw new \InvalidArgumentException('billing.invalid_design');
            }
            $values[$key] = trim($value);
        }
        $values['show_tax_column'] = filter_var($input['show_tax_column'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $values['logo_data_uri'] = self::logo($input['logo_data_uri'] ?? '');

        return $values;
    }

    public static function logo(mixed $value): string
    {
        if ('' === $value) {
            return '';
        }
        if (!is_string($value) || strlen($value) > 66000 || !preg_match('~^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$~D', $value, $match)) {
            throw new \InvalidArgumentException('billing.invalid_logo');
        }
        $bytes = base64_decode($match[2], true);
        $info = false !== $bytes && strlen($bytes) <= 49152 ? @getimagesizefromstring($bytes) : false;
        if (false === $info || $info[0] < 1 || $info[1] < 1 || $info[0] > 1600 || $info[1] > 1600 || ($info['mime'] ?? '') !== 'image/'.$match[1]) {
            throw new \InvalidArgumentException('billing.invalid_logo');
        }

        if (extension_loaded('gd')) {
            $image = @imagecreatefromstring($bytes);
            if (false === $image) {
                throw new \InvalidArgumentException('billing.invalid_logo');
            }
            unset($image);
        }

        return $value;
    }

    public function save(array $options, string $layout): void
    {
        $this->settings->updateSettings([
            AppSettingsService::KEY_INVOICE_LAYOUT => $layout,
            AppSettingsService::KEY_INVOICE_DESIGN => ['options' => $options],
        ]);
    }

    /** Values are substituted as escaped literal HTML, never as Twig source. */
    public static function layout(array $options): string
    {
        $o = self::normalize($options);
        $font = ['sans' => 'DejaVu Sans', 'serif' => 'DejaVu Serif', 'mono' => 'DejaVu Sans Mono'][$o['font']];
        $padding = 'compact' === $o['spacing'] ? 5 : 10;
        $accent = $o['accent'];
        $style = '<style>@page{margin:'.$o['margin_mm'].'mm}body{font-family:"'.$font.'";font-size:'.$o['font_size'].'pt;color:'.$o['text_color'].'}table{width:100%;border-collapse:collapse}th,td{padding:'.$padding.'px 5px;vertical-align:top;word-wrap:break-word}th{text-align:left;border-bottom:2px solid '.$accent.'}.num{text-align:right;white-space:nowrap}.items{table-layout:fixed;font-size:'.min($o['font_size'], 11).'pt}.items th{white-space:normal}.total{color:'.$accent.'}thead{display:table-header-group}tr{page-break-inside:avoid}</style>';
        $escape = static fn (string $value): string => str_replace(['{', '}'], ['&#123;', '&#125;'], htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $header = '<div style="text-align:'.$o['header_align'].';border-bottom:'.('modern' === $o['style'] ? '5' : '1').'px solid '.$accent.';padding-bottom:16px">';
        $company = '<h1>{{ company.name }}</h1><p>{% if company.address %}{{ company.address|nl2br }}<br>{% endif %}{{ company.email }}{% if company.vat_id %}<br>{{ labels.vat_id }}: {{ company.vat_id }}{% endif %}</p>';
        if ('' !== $o['logo_data_uri']) {
            $logo = '<td style="padding:0;width:'.$o['logo_width'].'px"><img alt="" src="'.$o['logo_data_uri'].'" style="width:'.$o['logo_width'].'px;max-height:120px"></td>';
            $company = '<table><tr>'.('left' === $o['header_align'] ? $logo : '').'<td style="padding:0 12px;text-align:'.$o['header_align'].'">'.$company.'</td>'.('right' === $o['header_align'] ? $logo : '').'</tr></table>';
        }
        $header .= $company.'</div>';
        $heading = '' === $o['heading'] ? '{{ labels.invoice }}' : $escape($o['heading']);
        $intro = '' === $o['intro'] ? '' : '<p>'.nl2br($escape($o['intro'])).'</p>';
        $footer = '' === $o['footer_text'] ? '' : '<p>'.nl2br($escape($o['footer_text'])).'</p>';
        $taxHead = $o['show_tax_column'] ? '<th class="num" style="width:12%">{{ labels.tax_short }}</th>' : '';
        $taxCell = $o['show_tax_column'] ? '<td class="num">{{ (item.tax_rate_bps / 100)|number_format(2, decimal_separator, thousands_separator) }}%</td>' : '';

        return $style.$header.<<<TWIG
<h2>$heading {{ invoice.number }}</h2>
<p>{{ labels.date }}: {{ invoice.created_at|date(date_format) }} · {{ labels.due_date }}: {{ invoice.due_date|date(date_format) }}</p>
{% if invoice.status == 'cancelled' %}<p><strong>{{ labels.cancelled }}</strong>: {{ invoice.cancellation_reason }}</p>{% endif %}
<h3>{{ labels.bill_to }}</h3><p>{{ customer.name }}{% if customer.address %}<br>{{ customer.address }}{% endif %}{% if customer.postal or customer.city %}<br>{{ customer.postal }} {{ customer.city }}{% endif %}{% if customer.country %}<br>{{ customer.country }}{% endif %}{% if customer.email != customer.name %}<br>{{ customer.email }}{% endif %}{% if customer.vat_id %}<br>{{ customer.vat_id }}{% endif %}</p>
$intro
<table class="items"><thead><tr><th>{{ labels.description }}</th><th class="num" style="width:14%">{{ labels.quantity }}</th><th class="num" style="width:18%">{{ labels.unit_price_short }}<br>{{ labels.gross }}</th>$taxHead<th class="num" style="width:22%">{{ labels.line_total_short }}<br>{{ labels.gross }}</th></tr></thead><tbody>
{% for item in invoice.items %}<tr><td>{{ item.description|nl2br }}</td><td class="num">{{ (item.quantity_milli / 1000)|number_format(3, decimal_separator, thousands_separator) }}</td><td class="num">{{ (item.unit_price_cents / 100)|number_format(2, decimal_separator, thousands_separator) }}</td>$taxCell<td class="num">{{ (item.gross_cents / 100)|number_format(2, decimal_separator, thousands_separator) }}</td></tr>{% endfor %}
</tbody></table>
<div style="text-align:right;margin-top:20px"><p>{{ labels.net }}: {{ (invoice.net_cents / 100)|number_format(2, decimal_separator, thousands_separator) }} {{ invoice.currency }}</p>
{% for group in invoice.tax_breakdown %}<p>{{ labels.tax }} ({{ (group.tax_rate_bps / 100)|number_format(2, decimal_separator, thousands_separator) }}%): {{ (group.tax_cents / 100)|number_format(2, decimal_separator, thousands_separator) }} {{ invoice.currency }}</p>{% endfor %}
<h3 class="total">{{ labels.total }}: {{ (invoice.amount_total / 100)|number_format(2, decimal_separator, thousands_separator) }} {{ invoice.currency }}</h3>
<p>{{ labels.outstanding }}: {{ (invoice.amount_due / 100)|number_format(2, decimal_separator, thousands_separator) }} {{ invoice.currency }}</p></div>
<p>{{ invoice.tax_notice }}</p><hr><p>{{ company.footer|nl2br }}</p>$footer
TWIG;
    }
}
