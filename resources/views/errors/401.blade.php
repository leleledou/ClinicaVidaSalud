{{-- Requerimiento técnico: mensaje en español, sin detalles internos (el detalle queda en storage/logs). --}}
@extends('errors::minimal')

@section('title', 'Acceso no autorizado')
@section('code', '401')
@section('message', 'Debe iniciar sesión para continuar.')
