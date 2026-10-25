{{-- Requerimiento técnico: mensaje en español, sin detalles internos (el detalle queda en storage/logs). --}}
@extends('errors::minimal')

@section('title', 'Demasiadas solicitudes')
@section('code', '429')
@section('message', 'Realizó demasiadas solicitudes. Espere un momento e intente de nuevo.')
