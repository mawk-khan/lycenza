<?php

namespace App\Domain\Students\Application\Certificates;

use App\Domain\Students\Application\Exceptions\StudentCertificateUnsupportedScriptException;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * SIS.EXP-4 (owner decision 2026-10-30): renders the ONE code-owned, generic
 * certificate template to PDF with dompdf. No School-supplied HTML, CSS,
 * script, merge field or media: the Blade view is fixed and every value is
 * an escaped snapshot field.
 *
 * - dompdf is locked down: no remote resources, no PHP, no PDF JavaScript,
 *   the filesystem chroot limited to the template directory, the bundled
 *   DejaVu Sans font only.
 * - Latin-script text only (the owner's decision): DejaVu Sans prints Latin
 *   fully but cannot shape other scripts, so a snapshot with any other script
 *   is refused (StudentCertificateUnsupportedScriptException) rather than
 *   printed wrongly.
 * - The wording is GENERIC (no statutory, board or government claim); a
 *   non-production build adds a visible DEMO / GENERIC banner.
 */
final class StudentCertificateRenderer
{
    /** Latin, common punctuation/digits and combining marks only. */
    private const PRINTABLE = '/^[\p{Latin}\p{Common}\p{Inherited}]*$/u';

    /** @param  array<string, ?string>  $snapshot */
    public static function assertPrintable(array $snapshot): void
    {
        foreach ($snapshot as $value) {
            if ($value !== null && preg_match(self::PRINTABLE, $value) !== 1) {
                throw new StudentCertificateUnsupportedScriptException;
            }
        }
    }

    /** @param  array<string, ?string>  $snapshot */
    public function render(StudentCertificateType $type, string $displayNumber, string $issuedOn, array $snapshot): string
    {
        self::assertPrintable($snapshot);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot([resource_path('views/certificates')]);
        $options->setDefaultFont('DejaVu Sans');
        // dompdf writes font-metric caches here: its own directory, never Laravel's cache root.
        $fontCache = storage_path('framework/cache/dompdf');
        if (! is_dir($fontCache)) {
            @mkdir($fontCache, 0775, true);
        }
        $options->setFontCache($fontCache);

        $html = view('certificates.student-certificate', [
            'type' => $type,
            'displayNumber' => $displayNumber,
            'issuedOn' => $issuedOn,
            's' => $snapshot,
            'reason' => StudentCertificateEligibility::reasonLabel((string) $snapshot['exit_reason']),
            'demo' => ! app()->environment('production'),
        ])->render();

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->addInfo('Title', $type->label().' '.$displayNumber);
        $pdf->addInfo('Creator', 'Lycenza');
        $pdf->render();

        return (string) $pdf->output();
    }
}
