<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: Persona, superclase {incompleta, superpuesta} de Paciente, Medico,
 * Recepcionista y Administrador (cada subclase referencia a esta tabla por
 * persona_id UNIQUE).
 *
 * Unicidad del documento: (tipo_documento, numero_documento, complemento) solo
 * cuando existe numero_documento. Un complemento ausente (NULL) se normaliza a
 * '' dentro del índice con COALESCE, de modo que "123456" sin complemento no se
 * repite, pero "123456 1A" y "123456 1B" son documentos distintos. Una persona
 * sin documento (p. ej. un niño) no entra en el índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_documento', 30)->nullable();
            $table->string('numero_documento', 30)->nullable();
            $table->string('complemento', 10)->nullable();
            $table->string('expedido', 10)->nullable();
            $table->string('nombres');
            $table->string('apellido_paterno');
            $table->string('apellido_materno')->nullable();
            $table->date('fecha_nacimiento');
            $table->string('sexo', 30);
            $table->string('telefono', 30);
            $table->string('correo')->nullable(); // contacto: puede repetirse (V3)
            $table->string('direccion')->nullable();

            $table->index(['apellido_paterno', 'apellido_materno', 'nombres'], 'ix_personas_nombre_completo');
        });

        DB::statement("ALTER TABLE personas ADD CONSTRAINT ck_personas_tipo_documento CHECK (tipo_documento IS NULL OR tipo_documento IN ('CI','PASAPORTE','CARNET_EXTRANJERO','CERTIFICADO_NACIMIENTO'))");
        DB::statement("ALTER TABLE personas ADD CONSTRAINT ck_personas_sexo CHECK (sexo IN ('M','F'))");
        DB::statement('ALTER TABLE personas ADD CONSTRAINT ck_personas_numero_requiere_tipo CHECK (numero_documento IS NULL OR tipo_documento IS NOT NULL)');
        DB::statement("ALTER TABLE personas ADD CONSTRAINT ck_personas_numero_no_vacio CHECK (numero_documento IS NULL OR btrim(numero_documento) <> '')");
        DB::statement("ALTER TABLE personas ADD CONSTRAINT ck_personas_complemento_no_vacio CHECK (complemento IS NULL OR btrim(complemento) <> '')");
        DB::statement("CREATE UNIQUE INDEX ux_personas_documento ON personas (tipo_documento, numero_documento, (COALESCE(complemento, ''))) WHERE numero_documento IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
