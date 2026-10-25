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

    // CU07 paso 4 / CU10 paso 2 / CU04 paso 3: buscar una persona por documento (lo usa el botón
    // «Buscar» de los formularios de médico, paciente y usuario). Devuelve JSON.
    public function buscar(Request $request)
    {
        // Solo quien registra médicos, pacientes o usuarios.
        $usuario = $request->user();
        abort_unless($usuario->can("medicos.gestionar") || $usuario->can("pacientes.gestionar") || $usuario->can("usuarios.gestionar"), 403);

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
