<?php

namespace App\Http\Controllers;

use App\Models\Alergia;
use App\Models\Antecedente;
use App\Models\Bitacora;
use App\Models\HistoriaClinica;
use App\Models\Paciente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CU30 Consultar cardex (RF-31) y CU29 Gestionar historia clínica (RF-30). Ver: el médico con
 * historia.consultar y cita con el paciente, o el paciente con historia.propia (el suyo y el de los
 * menores a su cargo); la regla está en Paciente::accesoClinico. Editar: solo el médico con
 * historia.gestionar (HistoriaClinica::puedeEditar); el paciente solo consulta (CU29 A1).
 * No hay borrado (RN11): la historia la abre CU10 y no se elimina.
 */
class HistoriaClinicaController extends Controller
{
    // Campos de la historia que se modifican y que se registran en la bitácora.
    private const CAMPOS = ["grupo_sanguineo", "observaciones"];

    // CU30 paso 2: el médico ve «Mis pacientes» (con cita con él); el paciente va a su cardex o elige
    // entre el suyo y los de los menores a su cargo (como CU20 A1). Si no, 403.
    public function index(Request $request)
    {
        $usuario = $request->user();

        // CU30 paso 2 (médico): buscador como CU11 y paginación.
        if ($medico = Paciente::medicoClinico($usuario)) {
            $texto = trim((string) $request->query("q", ""));
            $pacientes = Paciente::buscar($texto)
                ->whereExists(Paciente::citasConMedico($medico->id))
                ->paginate(15)
                ->withQueryString();

            return view("historias.index", ["modo" => "medico", "pacientes" => $pacientes, "texto" => $texto, "opciones" => collect()]);
        }

        // CU30 paso 2 (paciente): sin menores a cargo, directo a su cardex.
        abort_unless($usuario->tienePermiso("historia.propia"), 403);
        $opciones = Paciente::aCargoDe($usuario->persona);
        abort_if($opciones->isEmpty(), 404);
        if ($opciones->count() === 1) {
            return redirect()->route("historias-clinicas.mostrar", $opciones->first()->id);
        }

        return view("historias.index", ["modo" => "paciente", "pacientes" => null, "texto" => "", "opciones" => $opciones]);
    }

    // CU30 paso 3: cardex del paciente (id del paciente). CU29: con los formularios si el usuario puede editar.
    public function show(Request $request, $id)
    {
        // El id llega en la URL: si no es un número, la página no existe.
        abort_unless(ctype_digit((string) $id), 404);

        $paciente = Paciente::with(["persona", "historiaClinica"])->findOrFail($id);
        $acceso = $paciente->accesoClinico($request->user());
        abort_if($acceso === null, 403);

        $historia = $paciente->historiaClinica;
        abort_if($historia === null, 404);
        $historia->setRelation("paciente", $paciente);
        $historia->load([
            "alergias" => fn ($alergia) => $alergia->orderBy("sustancia"),
            "antecedentes" => fn ($antecedente) => $antecedente->orderBy("tipo")->orderByDesc("fecha_inicio")->orderBy("id"),
        ]);

        // CU30 paso 4: el médico deja constancia en la bitácora (el paciente que ve el suyo, no).
        if ($acceso === "medico") {
            Bitacora::registrar("CONSULTAR_CARDEX", "clinica", $request->user()->id, true, "historias_clinicas", $historia->id, "WEB", null,
                ["paciente" => $paciente->codigo]);
        }

        return view("historias.show", [
            "paciente" => $paciente,
            "historia" => $historia,
            "acceso" => $acceso,
            // CU29 A1: el paciente no ve formularios ni botones.
            "puedeEditar" => $historia->puedeEditar($request->user()),
            // CU32 paso 4: registrar la evolución de los tratamientos (médico con acceso y tratamientos.gestionar).
            "puedeEvolucion" => $acceso === "medico" && $request->user()->tienePermiso("tratamientos.gestionar"),
            "consultas" => $paciente->consultasFinalizadas()->get(),
            "resultados" => $paciente->resultadosLaboratorio()->get(),
            // CU29 pasos 2 y 3: opciones de los formularios.
            "tiposAntecedente" => Antecedente::tiposPara($paciente->persona->sexo),
            "tiposAlergia" => Alergia::TIPOS,
            "severidades" => Alergia::SEVERIDADES,
            "grupos" => HistoriaClinica::GRUPOS_SANGUINEOS,
            // A1: el paciente con menores a cargo elige qué cardex ver.
            "opciones" => $acceso === "paciente" ? Paciente::aCargoDe($request->user()->persona) : collect(),
        ]);
    }

    // CU29 paso 1: grupo sanguíneo y observaciones de la historia. El id viaja oculto.
    public function update(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $historia = HistoriaClinica::with("paciente")->findOrFail($request->input("id"));

        // CU29 A1: solo el médico con historia.gestionar y acceso al paciente.
        abort_unless($historia->puedeEditar($request->user()), 403);

        if (is_string($request->input("observaciones"))) {
            $request->merge(["observaciones" => trim($request->input("observaciones")) ?: null]);
        }
        $datos = $request->validate($this->reglas(), $this->mensajes());

        $anteriores = $this->foto($historia);

        DB::transaction(function () use ($historia, $datos, $anteriores, $request) {
            $historia->update($datos);

            Bitacora::registrar("MODIFICAR_HISTORIA", "clinica", $request->user()->id, true, "historias_clinicas", $historia->id, "WEB", $anteriores, $this->foto($historia));
        });

        return redirect()->route("historias-clinicas.mostrar", $historia->paciente_id)->with("exito", "Historia clínica actualizada");
    }

    // Datos de la historia para la bitácora.
    private function foto(HistoriaClinica $historia): array
    {
        return $historia->only(self::CAMPOS);
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:historias_clinicas,id"]];
    }

    // CU29 paso 1: grupo sanguíneo de la lista (ck_historias_grupo_sanguineo) y observaciones opcionales.
    private function reglas(): array
    {
        return [
            "grupo_sanguineo" => ["required", Rule::in(array_keys(HistoriaClinica::GRUPOS_SANGUINEOS))],
            "observaciones" => ["nullable", "string", "max:2000"],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó la historia clínica.",
            "id.integer" => "La historia clínica indicada no es válida.",
            "id.exists" => "La historia clínica indicada no existe.",
            "grupo_sanguineo.required" => "Elija el grupo sanguíneo.",
            "grupo_sanguineo.in" => "El grupo sanguíneo elegido no es válido.",
            "observaciones.max" => "Las observaciones no pueden superar los 2000 caracteres.",
        ];
    }
}
