<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class GuestLayout extends Component
{
    // $ancho = true: caja más ancha para formularios largos (CU12 registro).
    // $completo = true: ancho de página completo (CU14 disponibilidad de fichas).
    public function __construct(public bool $ancho = false, public bool $completo = false)
    {
    }

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.guest');
    }
}
