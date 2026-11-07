<?php

namespace App\Http\Controllers;

use App\Models\Alergia;
use App\Models\Bitacora;
use App\Models\HistoriaClinica;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * CU29 Gestionar historia clínica (RF-30): alergias del paciente. Solo el médico con historia.gestionar
 * y acceso al paciente (HistoriaClinica::puedeEditar); el paciente, 403 (A1). No hay borrado físico
 * (RN11, trigger sin DELETE): dar de baja pone activa = false.
 */
class AlergiaController extends Controller
{
    // Campos que se guardan y que se registran en la bitácora.
    private const CAMPOS = ["tipo", "sustancia", "reaccion", "severidad", "activa"];

    private const SUSTANCIA_REPETIDA = "El paciente ya tiene una alergia activa a esa sustancia";

    // CU29 paso 3: registrar una alergia activa. La historia viaja oculta.
    public function store(Request $request)
    {
        $request->validate(["historia_clinica_id" => ["required", "integer", "exists:historias_clinicas,id"]], $this->mensajes());
        $historia = HistoriaClinica::with("paciente")->findOrFail($request->input("historia_clinica_id"));
        abort_unless($historia->puedeEditar($request->user()), 403);

        $this->limpiarEntrada($request);
        $datos = $this->validar($request, $historia->id);

        DB::transaction(function () use ($historia, $datos, $request) {
            $alergia = Alergia::create($datos + ["historia_clinica_id" => $historia->id, "activa" => true]);

            Bitacora::registrar("CREAR_ALERGIA", "clinica", $request->user()->id, true, "alergias", $alergia->id, "WEB", null, $this->foto($alergia));
        });

        return $this->volver($historia)->with("exito", "Alergia registrada");
    }

    // CU29 paso 3: modificar una alergia ACTIVA. El id viaja oculto.
    public function update(Request $request)
    {
        $alergia = $this->alergiaDelFormulario($request);
        $historia = $alergia->historia;
        abort_unless($historia->puedeEditar($request->user()), 403);

        if (! $alergia->activa) {
            return $this->volver($historia)->withErrors(["operacion" => "Solo se puede modificar una alergia activa."]);
        }

        // CU29 paso 3: las mismas validaciones, sin contarse a sí misma.
        $this->limpiarEntrada($request);
        $datos = $this->validar($request, $historia->id, $alergia->id);

        $anteriores = $this->foto($alergia);

        DB::transaction(function () use ($alergia, $datos, $anteriores, $request) {
            $alergia->update($datos);

            Bitacora::registrar("MODIFICAR_ALERGIA", "clinica", $request->user()->id, true, "alergias", $alergia->id, "WEB", $anteriores, $this->foto($alergia));
        });

        return $this->volver($historia)->with("exito", "Alergia actualizada");
    }

    // CU29 paso 3: dar de baja (activa = false; el registro se conserva). El id viaja oculto.
    public function delete(Request $request)
    {
        $alergia = $this->alergiaDelFormulario($request);
        $historia = $alergia->historia;
        abort_unless($historia->puedeEditar($request->user()), 403);

        if (! $alergia->activa) {
            return $this->volver($historia)->withErrors(["operacion" => "La alergia ya estaba dada de baja."]);
        }

        DB::transaction(function () use ($alergia, $request) {
            $anteriores = $this->foto($alergia);
            $alergia->update(["activa" => false]);

            Bitacora::registrar("BAJA_ALERGIA", "clinica", $request->user()->id, true, "alergias", $alergia->id, "WEB", $anteriores, $this->foto($alergia));
        });

        return $this->volver($historia)->with("exito", "Alergia dada de baja");
    }

    // La alergia del id oculto (modificar y dar de baja), con su historia y su paciente.
    private function alergiaDelFormulario(Request $request): Alergia
    {
        $request->validate($this->reglaId(), $this->mensajes());

        return Alergia::with("historia.paciente")->findOrFail($request->input("id"));
    }

    // Vuelve al cardex del paciente.
    private function volver(HistoriaClinica $historia)
    {
        return redirect()->route("historias-clinicas.mostrar", $historia->paciente_id);
    }

    // CU29 paso 3: sustancia sin espacios en los extremos y con uno solo entre palabras; reacción vacía = null.
    private function limpiarEntrada(Request $request): void
    {
        if (is_string($request->input("sustancia"))) {
            $request->merge(["sustancia" => preg_replace("/\s+/u", " ", trim($request->input("sustancia")))]);
        }
        if (is_string($request->input("reaccion"))) {
            $request->merge(["reaccion" => trim($request->input("reaccion")) ?: null]);
        }
    }

    // CU29 paso 3: reglas() y la alergia ACTIVA repetida en la misma historia ($idIgnorar: la propia alergia).
    private function validar(Request $request, int $historiaId, ?int $idIgnorar = null): array
    {
        $validador = Validator::make($request->all(), $this->reglas(), $this->mensajes());
        $validador->after(function ($validador) use ($request, $historiaId, $idIgnorar) {
            if (! $validador->errors()->has("sustancia") && Alergia::hayDuplicadaActiva($historiaId, $request->input("sustancia"), $idIgnorar)) {
                $validador->errors()->add("sustancia", self::SUSTANCIA_REPETIDA);
            }
        });

        return $validador->validate();
    }

    // Datos de la alergia para la bitácora.
    private function foto(Alergia $alergia): array
    {
        return $alergia->only(self::CAMPOS);
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:alergias,id"]];
    }

    // CU29 paso 3: tipo y severidad de la lista, sustancia obligatoria y reacción opcional.
    private function reglas(): array
    {
        return [
            "tipo" => ["required", Rule::in(array_keys(Alergia::TIPOS))],
            "sustancia" => ["required", "string", "max:255"],
            "reaccion" => ["nullable", "string", "max:255"],
            "severidad" => ["required", Rule::in(array_keys(Alergia::SEVERIDADES))],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó la alergia.",
            "id.integer" => "La alergia indicada no es válida.",
            "id.exists" => "La alergia indicada no existe.",
            "historia_clinica_id.required" => "No se indicó la historia clínica.",
            "historia_clinica_id.integer" => "La historia clínica indicada no es válida.",
            "historia_clinica_id.exists" => "La historia clínica indicada no existe.",
            "tipo.required" => "Elija el tipo de alergia.",
            "tipo.in" => "El tipo de alergia no es válido.",
            "sustancia.required" => "El campo Sustancia es obligatorio.",
            "sustancia.max" => "La sustancia no puede superar los 255 caracteres.",
            "reaccion.max" => "La reacción no puede superar los 255 caracteres.",
            "severidad.required" => "Elija la severidad.",
            "severidad.in" => "La severidad no es válida.",
        ];
    }
}
