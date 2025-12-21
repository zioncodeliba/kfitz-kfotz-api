<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class YpayTestPdfController extends Controller
{
    use ApiResponse;

    public function generate(Request $request)
    {
        $data = $request->validate([
            'docType' => 'required',
            'mail' => 'required',
            'details' => 'required|string',
            'lang' => 'required|string',
            'contact' => 'required|array',
            'items' => 'required|array|min:1',
            'methods' => 'required|array|min:1',
        ]);

        $serialNumber = random_int(100000, 999999);
        $jsonPretty = json_encode($request->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $pdfContent = $this->buildPdfWithText($jsonPretty ?? 'Invalid payload');

        $path = "ypay-tests/ypay-test-{$serialNumber}.pdf";
        Storage::disk('public')->put($path, $pdfContent);

        $url = Storage::disk('public')->url($path);

        return response()->json([
            'url' => $url,
            'serialNumber' => $serialNumber,
            'responseCode' => 1,
        ]);
    }

    private function buildPdfWithText(string $text): string
    {
        $escaped = $this->escapePdfText($text);
        $contentStream = "BT\n/F1 12 Tf\n72 720 Td\n({$escaped}) Tj\nET";
        $objects = [];
        $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n";
        $objects[] = "4 0 obj\n<< /Length " . strlen($contentStream) . " >>\nstream\n{$contentStream}\nendstream\nendobj\n";
        $objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    private function escapePdfText(string $text): string
    {
        $text = str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $text);
        $text = str_replace(["\r", "\n"], [' ', ' '], $text);
        return $text;
    }
}
