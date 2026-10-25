{{-- Requerimiento técnico: mensaje en español, sin detalles internos (el detalle queda en storage/logs). --}}
@extends('errors::minimal')

@section('title', 'Página expirada')
@section('code', '419')
@section('message', 'La página expiró. Vuelva a cargarla e intente de nuevo.')
