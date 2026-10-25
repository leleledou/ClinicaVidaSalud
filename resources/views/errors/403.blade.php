{{-- Requerimiento técnico: mensaje en español, sin detalles internos (el detalle queda en storage/logs). --}}
@extends('errors::minimal')

@section('title', 'Acceso denegado')
@section('code', '403')
@section('message', 'No tiene permiso para acceder a esta sección.')
