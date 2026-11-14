<?php
/**
 * SenTri Emergency Contacts Classification & Formatting Helpers
 * Distinguishes telephone (landline) vs mobile phone numbers vs shortcode hotlines.
 */

if (!function_exists('sentri_parse_contact_numbers')) {
    /**
     * Splits and categorizes numbers into telephone, mobile, or hotline.
     *
     * @param string|null $raw
     * @return array<int, array{raw: string, type: string, label: string, icon: string, dial: string}>
     */
    function sentri_parse_contact_numbers(?string $raw): array {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        // Split by slash or newline (e.g. "0998-598-5601 / 0947-726-6180")
        $parts = preg_split('/\s*[\/\n]\s*/', trim($raw));
        $results = [];

        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;

            $digitsOnly = preg_replace('/[^0-9]/', '', $p);
            $normalized = preg_replace('/[\s-]/', '', $p);

            $type = 'telephone';
            $label = 'Telephone';
            $icon = 'fa-phone';

            // Mobile: 11 digits starting with 09, or +639 / 639 (12 digits)
            if (
                preg_match('/^(09|\+?639)\d{9}$/', $normalized) ||
                (strlen($digitsOnly) === 11 && str_starts_with($digitsOnly, '09')) ||
                (strlen($digitsOnly) === 12 && str_starts_with($digitsOnly, '639'))
            ) {
                $type = 'mobile';
                $label = 'Mobile';
                $icon = 'fa-mobile-screen-button';
            }
            // Hotline: 911, 117, 16211, 1626, or 3-4 digit shortcodes
            elseif (
                in_array($digitsOnly, ['911', '117', '16211', '1626'], true) ||
                (strlen($digitsOnly) >= 3 && strlen($digitsOnly) <= 4 && !str_starts_with($digitsOnly, '0'))
            ) {
                $type = 'hotline';
                $label = 'Hotline';
                $icon = 'fa-tower-broadcast';
            }
            // Landline / Telephone: Cavite (046) area code, Metro Manila (02), or 7-8 digit local numbers
            else {
                $type = 'telephone';
                $label = 'Telephone';
                $icon = 'fa-phone';
            }

            // Dial URI handling with extension pause support (loc. 248 -> ,248)
            $dial = preg_replace('/[^0-9+]/', '', $p);
            if (preg_match('/loc\.\s*(\d+)/i', $p, $matches)) {
                $dial = preg_replace('/[^0-9+]/', '', preg_replace('/loc\..*$/i', '', $p)) . ',' . $matches[1];
            }

            $results[] = [
                'raw'   => $p,
                'type'  => $type,
                'label' => $label,
                'icon'  => $icon,
                'dial'  => $dial
            ];
        }

        return $results;
    }
}

if (!function_exists('sentri_render_contact_numbers_html')) {
    /**
     * Renders accessible, styled HTML with badges distinguishing Telephone vs Mobile vs Hotline.
     *
     * @param string|null $raw
     * @param string $styleVariant 'default' | 'inline'
     * @return string
     */
    function sentri_render_contact_numbers_html(?string $raw, string $styleVariant = 'default'): string {
        $parsed = sentri_parse_contact_numbers($raw);
        if (empty($parsed)) return '';

        $out = '';
        foreach ($parsed as $item) {
            $type = $item['type'];
            $label = htmlspecialchars($item['label']);
            $icon = htmlspecialchars($item['icon']);
            $rawText = htmlspecialchars($item['raw']);
            $dial = htmlspecialchars($item['dial']);

            $badgeStyle = '';
            if ($type === 'mobile') {
                $badgeStyle = 'background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;';
            } elseif ($type === 'hotline') {
                $badgeStyle = 'background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;';
            } else {
                $badgeStyle = 'background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;';
            }

            $badge = '<span class="phone-type-tag phone-type-' . $type . '" style="display:inline-flex;align-items:center;gap:4px;font-size:0.66rem;font-weight:700;padding:2px 6px;border-radius:4px;letter-spacing:0.3px;text-transform:uppercase;' . $badgeStyle . '"><i class="fas ' . $icon . '" style="font-size:0.62rem;"></i>' . $label . '</span>';

            if ($styleVariant === 'inline') {
                $out .= '<span class="contact-number-item inline" style="display:inline-flex;align-items:center;gap:5px;margin-right:10px;">'
                      . $badge
                      . '<a href="tel:' . $dial . '" style="font-weight:600;color:inherit;text-decoration:none;">' . $rawText . '</a>'
                      . '</span>';
            } else {
                $out .= '<div class="contact-row contact-phone-row" style="display:flex;align-items:center;gap:7px;margin-top:3px;flex-wrap:wrap;">'
                      . $badge
                      . '<a href="tel:' . $dial . '" style="font-weight:600;color:var(--text);text-decoration:none;">' . $rawText . '</a>'
                      . '</div>';
            }
        }

        return $out;
    }
}
