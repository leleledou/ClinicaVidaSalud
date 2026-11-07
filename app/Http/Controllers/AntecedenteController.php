<?php

namespace App\Http\Controllers;

use App\Models\Antecedente;
use App\Models\Bitacora;
use App\Models\HistoriaClinica;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CU29 Gestionar historia clínica (RF-30): antecedentes del paciente (patológicos, crónicos,
 * quirúrgicos, familiares, gineco-obstétricos, hábitos y medicación habitual). Solo el médico con
 * historia.gestionar y acceso al paciente (HistoriaClinica::puedeEditar); el paciente, 403 (A1).
 * No hay borrado físico (RN11, trigger sin DELETE): dar de baja pone vigente = false.
 */
class AntecedenteController extends Controller
{
    // Campos que se guardan y que se registran en la bitácora.
    private const CAMPOS = ["tipo", "descripcion", "fecha_inicio", "vigente"];

    // CU29 paso 2: registrar un antecedente vigente. La historia viaja oculta.
    public function store(Request $request)
    {
        $request->validate(["historia_clinica_id" => ["required", "integer", "exists:historias_clinicas,id"]], $this->mensajes());
        $historia = HistoriaClinica::with("paciente.persona")->findOrFail($request->input("historia_clinica_id"));
        abort_unless($historia->puedeEditar($request->user()), 403);

        $this->limpiarDescripcion($request);
        $datos = $request->validate($this->reglas($historia), $this->mensajes());

        DB::transaction(function () use ($historia, $datos, $request) {
            $antecedente = Antecedente::create($datos + ["historia_clinica_id" => $historia->id, "vigente" => true]);

            Bitacora::registrar("CREAR_ANTECEDENTE", "clinica", $request->user()->id, true, "antecedentes", $antecedente->id, "WEB", null, $this->foto($antecedente));
        });

        return $this->volver($historia)->with("exito", "Antecedente registrado");
    }

    // CU29 paso 2: modificar un antecedente; con vigente = 1 se reactiva uno dado de baja. El id viaja oculto.
    public function update(Request $request)
    {
        $antecedente = $this->antecedenteDelFormulario($request);
        $historia = $antecedente->historia;
        abort_unless($historia->puedeEditar($request->user()), 403);

        $this->limpiarDescripcion($request);
        $datos = $request->validate($this->reglas($historia) + ["vigente" => ["nullable", "boolean"]], $this->mensajes());
        $datos["vigente"] = $request->boolean("vigente", $antecedente->vigente);

        $anteriores = $this->foto($antecedente);

        DB::transaction(function () use ($antecedente, $datos, $anteriores, $request) {
            $antecedente->update($datos);

            Bitacora::registrar("MODIFICAR_ANTECEDENTE", "clinica", $request->user()->id, true, "antecedentes", $antecedente->id, "WEB", $anteriores, $this->foto($antecedente));
        });

        return $this->volver($historia)->with("exito", "Antecedente actualizado");
    }

    // CU29 paso 2: dar de baja (vigente = false; el registro se conserva). El id viaja oculto.
    public function delete(Request $request)
    {
        $antecedente = $this->antecedenteDelFormulario($request);
        $historia = $antecedente->historia;
        abort_unless($historia->puedeEditar($request->user()), 403);

        if (! $antecedente->vigente) {
            return $this->volver($historia)->withErrors(["operacion" => "El antecedente ya estaba dado de baja."]);
        }

        DB::transaction(function () use ($antecedente, $request) {
            $anteriores = $this->foto($antecedente);
            $antecedente->update(["vigente" => false]);

            Bitacora::registrar("BAJA_ANTECEDENTE", "clinica", $request->user()->id, true, "antecedentes", $antecedente->id, "WEB", $anteriores, $this->foto($antecedente));
        });

        return $this->volver($historia)->with("exito", "Antecedente dado de baja");
    }

    // El antecedente del id oculto (modificar y dar de baja), con su historia y su paciente.
    private function antecedenteDelFormulario(Request $request): Antecedente
    {
        $request->validate($this->reglaId(), $this->mensajes());

        return Antecedente::with("historia.paciente.persona")->findOrFail($request->input("id"));
    }

    // Vuelve al cardex del paciente.
    private function volver(HistoriaClinica $historia)
    {
        return redirect()->route("historias-clinicas.mostrar", $historia->paciente_id);
    }

    // CU29 paso 2: descripción sin espacios en los extremos.
    private function limpiarDescripcion(Request $request): void
    {
        if (is_string($request->input("descripcion"))) {
            $request->merge(["descripcion" => trim($request->input("descripcion"))]);
        }
    }

    // Datos del antecedente para la bitácora.
    private function foto(Antecedente $antecedente): array
    {
        return array_merge($antecedente->only(self::CAMPOS), ["fecha_inicio" => $antecedente->fecha_inicio?->toDateString()]);
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:antecedentes,id"]];
    }

    // CU29 paso 2: tipo de la lista (GINECO_OBSTETRICO solo si la paciente es mujer), descripción
    // obligatoria y fecha de inicio opcional y no futura.
    private function reglas(HistoriaClinica $historia): array
    {
        return [
            "tipo" => ["required", Rule::in(array_keys(Antecedente::tiposPara($historia->paciente->persona->sexo)))],
            "descripcion" => ["required", "string", "max:2000"],
            "fecha_inicio" => ["nullable", "date_format:Y-m-d", "before_or_equal:today"],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó el antecedente.",
            "id.integer" => "El antecedente indicado no es válido.",
            "id.exists" => "El antecedente indicado no existe.",
            "historia_clinica_id.required" => "No se indicó la historia clínica.",
            "historia_clinica_id.integer" => "La historia clínica indicada no es válida.",
            "historia_clinica_id.exists" => "La historia clínica indicada no existe.",
            "tipo.required" => "Elija el tipo de antecedente.",
            "tipo.in" => "El tipo de antecedente no es válido para este paciente (el gineco-obstétrico es solo para mujeres).",
            "descripcion.required" => "El campo Descripción es obligatorio.",
            "descripcion.max" => "La descripción no puede superar los 2000 caracteres.",
            "fecha_inicio.date_format" => "La fecha de inicio no es válida.",
            "fecha_inicio.before_or_equal" => "La fecha de inicio no puede ser futura.",
            "vigente.boolean" => "El valor de Vigente no es válido.",
        ];
    }
}
