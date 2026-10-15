<?php
declare(strict_types=1);

/**
 * The layout knobs, declared once. pdf.php reads them from the query string and
 * layout.php builds its form from the same table, so the two cannot drift apart.
 */
final class PageOptions
{
    public const FIELDS = [
        'title'          => ['type' => 'text',     'label' => 'Title'],
        'subtitle'       => ['type' => 'text',     'label' => 'Subtitle'],
        'intro'          => ['type' => 'textarea', 'label' => 'Intro text',  'hint' => 'simple HTML: b, i, br, p'],
        'notes'          => ['type' => 'textarea', 'label' => 'Notes below', 'hint' => 'simple HTML: b, i, br, p'],
        'footer'         => ['type' => 'text',     'label' => 'Footer text'],
        'author'         => ['type' => 'text',     'label' => 'PDF author'],
        'pageNumber'     => ['type' => 'int',      'label' => 'Page number'],
        'margin'         => ['type' => 'float',    'label' => 'Margin mm'],
        'font'           => ['type' => 'select',   'label' => 'Font', 'options' => ['helvetica', 'times', 'courier']],
        'titleSize'      => ['type' => 'int',      'label' => 'Title pt'],
        'subtitleSize'   => ['type' => 'int',      'label' => 'Subtitle pt'],
        'bodySize'       => ['type' => 'int',      'label' => 'Body pt'],
        'showRule'       => ['type' => 'bool',     'label' => 'Rule under title'],
        'showQr'         => ['type' => 'bool',     'label' => 'Answer QR code'],
        'qrSize'         => ['type' => 'float',    'label' => 'QR size mm'],
        'qrCaption'      => ['type' => 'text',     'label' => 'QR caption'],
        'showPageNumber' => ['type' => 'bool',     'label' => 'Show page number'],
    ];

    /** Only the keys we know about, coerced to their declared types. */
    public static function fromQuery(array $q): array
    {
        $out = [];
        foreach (self::FIELDS as $key => $field) {
            if (!isset($q[$key])) {
                continue;
            }
            $raw = $q[$key];
            switch ($field['type']) {
                case 'int':
                    $out[$key] = (int) $raw;
                    break;
                case 'float':
                    $out[$key] = (float) $raw;
                    break;
                case 'bool':
                    $out[$key] = (bool) (int) $raw;
                    break;
                default:
                    $out[$key] = (string) $raw;
            }
        }
        return $out;
    }

    /** Current value for a field: what was submitted, else the PdfPage default. */
    public static function value(array $opts, string $key)
    {
        return $opts[$key] ?? (PdfPage::DEFAULTS[$key] ?? '');
    }

    /** Every layout field as query parameters, for building links. */
    public static function toQuery(array $opts): array
    {
        $q = [];
        foreach (self::FIELDS as $key => $field) {
            $v = self::value($opts, $key);
            $q[$key] = $field['type'] === 'bool' ? ($v ? '1' : '0') : (string) $v;
        }
        return $q;
    }
}
