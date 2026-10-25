{{-- Requerimiento técnico: mensaje en español, sin detalles internos (el detalle queda en storage/logs). --}}
@extends('errors::minimal')

@section('title', 'Solicitud no válida')
@section('code', 'Error')
@section('message', 'No se pudo procesar la solicitud.')
