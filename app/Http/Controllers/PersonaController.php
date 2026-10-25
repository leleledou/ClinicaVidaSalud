<?php

namespace App\Http\Controllers;

use App\Models\Persona;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class PersonaController extends Controller
{
    public function index()
    {
        //
    }

    public function store(Request $request)
    {
        //
    }

    public function show($id)
    {
        //
    }

    public function update(Request $request)
    {
        //
    }

    public function delete(Request $request)
    {
        //
    }

    // CU07 paso 4 / CU10 paso 2 / CU04 paso 3 / CU13 paso 3: buscar una persona por documento (lo usa
    // el botón «Buscar» de los formularios de médico, paciente, usuario y vínculo). Devuelve JSON.
    public function buscar(Request $request)
    {
        // Quien registra médicos, pacientes, usuarios o vínculos.
        $usuario = $request->user();
        $esPersonal = $usuario->can("medicos.gestionar") || $usuario->can("pacientes.gestionar")
            || $usuario->can("usuarios.gestionar") || $usuario->can("vinculos.gestionar");

        // CU13 A1: el paciente, solo para registrar sus propios contactos.
        $propioPaciente = ! $esPersonal && $usuario->can("perfil.gestionar") ? $usuario->persona?->paciente : null;
        abort_unless($esPersonal || $propioPaciente, 403);

        $datos = $request->validate([
            "tipo" => ["required", "in:" . implode(",", array_keys(Persona::TIPOS_DOCUMENTO))],
            "numero" => ["required", "string", "max:30"],
            "complemento" => ["nullable", "string", "max:10"],
        ], [
            "tipo.required" => "Indique el tipo de documento.",
            "tipo.in" => "El tipo de documento no es válido.",
            "numero.required" => "Indique el número de documento.",
            "numero.max" => "El número de documento no puede superar los 30 caracteres.",
            "complemento.max" => "El complemento no puede superar los 10 caracteres.",
        ]);

        $persona = Persona::with(["medico", "paciente", "recepcionista", "administrador", "usuario"])
            ->find(Persona::buscarPorDocumento($datos["tipo"], $datos["numero"], $datos["complemento"] ?? null)?->id);

        if ($persona === null) {
            return response()->json(["existe" => false]);
        }

        // CU13 A1: al paciente solo se le confirma el nombre (sin teléfono, correo ni dirección de
        // terceros); el servidor reutiliza a la persona al guardar.
        if ($propioPaciente) {
            return response()->json([
                "existe" => true,
                "reducida" => true,
                "propio" => $persona->id === $propioPaciente->persona_id,
                "persona" => ["id" => $persona->id, "nombres" => $persona->nombres, "apellido_paterno" => $persona->apellido_paterno],
            ]);
        }

        // Perfil resumido: id, código y estado (o null si la persona no lo tiene).
        $perfil = fn ($modelo) => $modelo ? ["id" => $modelo->id, "codigo" => $modelo->codigo, "estado" => $modelo->estado] : null;

        // Datos para completar el formulario (sin contraseñas ni otros datos sensibles).
        return response()->json([
            "existe" => true,
            // toArray(): la fecha de nacimiento sale como AAAA-MM-DD (cast date:Y-m-d).
            "persona" => Arr::only($persona->toArray(), ["id", "tipo_documento", "numero_documento", "complemento", "expedido", "nombres",
                "apellido_paterno", "apellido_materno", "fecha_nacimiento", "sexo", "telefono", "correo", "direccion"]),
            "medico" => $perfil($persona->medico),
            "paciente" => $perfil($persona->paciente),
            "recepcionista" => $perfil($persona->recepcionista),
            "administrador" => $perfil($persona->administrador),
            // CU04 paso 4: solo quien gestiona usuarios ve si la persona ya tiene cuenta.
            "usuario" => $usuario->can("usuarios.gestionar") && $persona->usuario ? ["nombre_usuario" => $persona->usuario->nombre_usuario] : null,
        ]);
    }
}
