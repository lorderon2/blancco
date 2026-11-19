<?php

namespace App\Integrations;

class CertificatePdf
{
    public function render(string $productKey, string $serial): string
    {
        if (! preg_match('/^[A-Z0-9]{5}(?:-[A-Z0-9]{5}){4}$/i', $productKey) || ! preg_match('/^[\x20-\x7E]{1,100}$/', $serial)) {
            throw new \InvalidArgumentException('Nieprawidłowy klucz produktu lub numer seryjny.');
        }

        $serial = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $serial);
        $keyX = (606.614 - strlen($productKey) * 16 * 0.6) / 2;
        $serialSize = min(10, 120 / (strlen($serial) * 0.6));
        $serialX = 580 - strlen($serial) * $serialSize * 0.6;
        $overlay = sprintf("q 0.16 0.19 0.25 rg BT /CertificateFont 16 Tf 1 0 0 1 %.3F 228 Tm (%s) Tj ET BT /CertificateFont %.3F Tf 1 0 0 1 %.3F 24 Tm (%s) Tj ET Q\n", $keyX, $productKey, $serialSize, $serialX, $serial);
        $template = file_get_contents(resource_path('pdf/certificate-template.pdf'));
        $placeholder = str_pad('%CERTIFICATE_OVERLAY', 512);
        if (strlen($overlay) > 512 || substr_count($template, $placeholder) !== 1) {
            throw new \RuntimeException('Nieprawidłowy szablon certyfikatu.');
        }

        // Fixed stream length preserves the original PDF object offsets and vector artwork.
        return str_replace($placeholder, str_pad($overlay, 512), $template);
    }
}
