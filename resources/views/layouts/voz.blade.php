{{-- CU39 Ejecutar comando de voz (RF-40) pasos 1 y 7: botón de micrófono y panel (solo con voz.usar; lo maneja
     resources/js/voz.js). La transcripción se puede corregir o escribir; toda acción se confirma antes de ejecutarse. --}}
<button type="button" data-voz-abrir aria-expanded="false" aria-controls="panel-voz"
        class="fixed bottom-5 end-5 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-teal-600 text-white shadow-lg hover:bg-teal-700 focus:outline-none focus:ring-4 focus:ring-teal-300"
        title="Comando de voz" aria-label="Abrir el panel de comandos de voz">
    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
    </svg>
</button>

<section id="panel-voz" data-voz-panel hidden
         data-url-interpretar="{{ route('voz.interpretar') }}" data-url-ejecutar="{{ route('voz.ejecutar') }}"
         class="fixed inset-x-4 bottom-24 z-40 rounded-xl border border-slate-200 bg-white p-4 shadow-xl sm:inset-x-auto sm:end-5 sm:w-96"
         aria-label="Comando de voz">
    <div class="mb-3 flex items-center justify-between">
        <h2 class="font-semibold text-marino-900">Comando de voz</h2>
        <button type="button" data-voz-cerrar class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Cerrar el panel de voz">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
        </button>
    </div>

    <label for="voz-texto" class="sr-only">Comando</label>
    <textarea id="voz-texto" data-voz-texto rows="2" maxlength="{{ \App\Services\VozService::MAX_TEXTO }}" class="campo"
              placeholder="Por ejemplo: quiero una cita de medicina general para el lunes"></textarea>

    <div class="mt-3 flex flex-wrap items-center gap-2">
        <button type="button" data-voz-escuchar class="btn-secundario btn-chico">🎤 Hablar</button>
        <button type="button" data-voz-interpretar class="btn-primario btn-chico">Interpretar</button>
    </div>
    <p data-voz-estado class="mt-2 min-h-5 text-sm text-slate-500" role="status" aria-live="polite"></p>

    {{-- CU39 paso 7: confirmación antes de ejecutar --}}
    <div data-voz-confirmacion hidden class="mt-3 space-y-3 rounded-lg bg-teal-50 p-3">
        <p data-voz-mensaje class="text-sm font-medium text-marino-900"></p>
        <div class="flex gap-2">
            <button type="button" data-voz-confirmar class="btn-primario btn-chico">Confirmar</button>
            <button type="button" data-voz-cancelar class="btn-secundario btn-chico">Cancelar</button>
        </div>
    </div>
    <p class="mt-3 text-xs text-slate-400">La interpretación usa un servicio de IA externo. Nada se ejecuta sin su confirmación.</p>
</section>
