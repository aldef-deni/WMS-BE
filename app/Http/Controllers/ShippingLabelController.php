<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Picqer\Barcode\BarcodeGeneratorPNG;

class ShippingLabelController extends Controller
{
    public function label(Request $r, int $id)
    {
        $d = Document::visible($r->user())->where('type', 'order')->findOrFail($id);
        Gate::authorize('view', $d);
        abort_unless(! empty($d->meta['tracking_number']), 422, 'Resi belum dibuat.');
        $generator = new BarcodeGeneratorPNG;
        $barcode = base64_encode($generator->getBarcode($d->meta['tracking_number'], $generator::TYPE_CODE_128, 2, 60));
        $html = view('shipping-label', ['document' => $d->load('client', 'warehouse'), 'barcode' => $barcode])->render();
        $pdf = new Dompdf(['isRemoteEnabled' => false]);
        $pdf->setPaper([0, 0, 283.46, 425.2]);
        $pdf->loadHtml($html);
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="label-'.$d->number.'.pdf"']);
    }
}
