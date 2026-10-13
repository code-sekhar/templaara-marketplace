<?php

namespace App\Http\Controllers\Invoicing;

use App\Models\Invoice;
use App\Services\Invoicing\InvoicePdf;
use App\Services\Invoicing\InvoicePresentation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class InvoiceController
{
    public function index(Request $request, InvoicePresentation $presentation): Response
    {
        return Inertia::render('invoices/Index', $presentation->listing($request, $request->routeIs('admin.*')));
    }

    public function show(Request $request, Invoice $invoice, InvoicePresentation $presentation): Response
    {
        $this->authorize($request, $invoice);
        Inertia::encryptHistory();

        return Inertia::render('invoices/Show', ['document' => $presentation->detail($invoice, $request->routeIs('admin.*'))]);
    }

    public function pdf(Request $request, Invoice $invoice, InvoicePdf $pdf): \Illuminate\Http\Response
    {
        $this->authorize($request, $invoice);

        return response($pdf->bytes($invoice))->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="'.$invoice->number.'.pdf"')->header('Cache-Control', 'private, no-store');
    }

    private function authorize(Request $request, Invoice $invoice): void
    {
        Gate::authorize($request->routeIs('admin.*') ? 'inspect' : 'view', $invoice);
    }
}
