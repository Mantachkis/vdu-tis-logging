<?php

namespace Vdu\TisLogging\Traits;

use Vdu\TisLogging\EventLogger;

/**
 * Naudojimas kontroleryje:
 *
 *     use Vdu\TisLogging\Traits\LogsViews;
 *
 *     class InvoiceController extends Controller
 *     {
 *         use LogsViews;
 *
 *         public function show(Invoice $invoice)
 *         {
 *             $this->logView($invoice);
 *             return view('invoices.show', compact('invoice'));
 *         }
 *     }
 *
 * Eloquent modelis neturi įvykio "peržiūrėta", tad šis kvietimas
 * turi būti eksplicitiškas - įrankis negali automatiškai žinoti,
 * kurie duomenys jautrūs ir turi būti fiksuojami.
 */
trait LogsViews
{
    protected function logView($model, ?string $description = null)
    {
        app(EventLogger::class)->info(
            'view',
            $description ?? (class_basename($model)." (ID {$model->getKey()}) peržiūrėtas"),
            [
                'subject_type' => get_class($model),
                'subject_id' => $model->getKey(),
            ]
        );
    }
}
