{{-- Requerimiento técnico: mensaje en español, sin detalles internos (el detalle queda en storage/logs). --}}
@extends('errors::minimal')

@section('title', 'Error del sistema')
@section('code', 'Error')
@section('message', 'Ocurrió un error inesperado. Intente nuevamente más tarde.')
