<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Services\Products\Files\PrivateArtifactStorage;
use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class InvoicePdf
{
    public function bytes(Invoice $invoice): string
    {
        $disk = config('invoicing.disk');
        app(PrivateArtifactStorage::class)->assertSecure($disk);

        return DB::transaction(function () use ($invoice, $disk) {
            Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $file = DB::table('invoice_files')->where('invoice_id', $invoice->id)->first();
            if ($file) {
                app(PrivateArtifactStorage::class)->assertSecure($file->disk);
                $bytes = Storage::disk($file->disk)->get($file->path);
                if (! hash_equals($file->checksum, hash('sha256', $bytes))) {
                    throw new \RuntimeException('Invoice file integrity check failed.');
                }

                return $bytes;
            }
            $bytes = $this->render($invoice);
            $path = 'invoices/'.$invoice->public_uuid.'.pdf';
            if (! Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private'])) {
                throw new \RuntimeException('Invoice storage unavailable.');
            }
            DB::table('invoice_files')->insert(['invoice_id' => $invoice->id, 'disk' => $disk, 'path' => $path, 'checksum' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'generated_at' => now('UTC')->toIso8601String()]);

            return $bytes;
        }, 3);
    }

    public function render(Invoice $invoice): string
    {
        $directory = storage_path('framework/invoice-render');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
            'chroot' => [resource_path('views/invoicing'), base_path('vendor/dompdf/dompdf/lib/fonts')], 'tempDir' => $directory, 'fontCache' => $directory,
            'defaultFont' => 'DejaVu Sans']);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('invoicing.document', ['document' => $invoice->load('items', 'original', 'refund')])->render());
        $pdf->setPaper('A4');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        if ($canvas instanceof CPDF) {
            $canvas->get_cpdf()->addInfo('CreationDate', $invoice->issued_at->utc()->format('YmdHis').'+00\'00\'');
            $canvas->get_cpdf()->addInfo('ModDate', $invoice->issued_at->utc()->format('YmdHis').'+00\'00\'');
            $canvas->get_cpdf()->fileIdentifier = md5($invoice->public_uuid);
        }

        return $pdf->output();
    }
}
