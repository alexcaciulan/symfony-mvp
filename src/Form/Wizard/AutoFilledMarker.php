<?php

declare(strict_types=1);

namespace App\Form\Wizard;

use Symfony\Component\Form\FormView;

/**
 * Sets `data-auto-filled="true"` on every form child whose name appears in
 * `$fields`. Used by Step1/2/3 form types' `finishView()` so that Stimulus
 * (Pas 3.3) can render the "auto · N%" badge over pre-populated inputs.
 *
 * The lookup is guarded with `isset()` — if the DTO's `autoFilled` list
 * carries a field name that the form doesn't expose (drift between the
 * prefill aggregator and the form), we silently skip rather than crash.
 */
final class AutoFilledMarker
{
    /**
     * @param list<string> $fields
     */
    public static function apply(FormView $view, array $fields): void
    {
        foreach ($fields as $field) {
            if (!isset($view->children[$field])) {
                continue;
            }
            $view->children[$field]->vars['attr']['data-auto-filled'] = 'true';
        }
    }
}
